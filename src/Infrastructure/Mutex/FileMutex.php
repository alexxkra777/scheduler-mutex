<?php

declare(strict_types=1);

namespace SchedulerTest\Infrastructure\Mutex;

use SchedulerTest\Contract\Exception\MutexException;
use SchedulerTest\Contract\LockInterface;
use SchedulerTest\Contract\MutexInterface;

/**
 * flock()-based mutex for separate CLI processes on one host with a shared local file system.
 *
 * One lock file per key lives in $directory; the name is sha256 of the key, so any valid
 * key gives a safe file name. Lock files are never deleted - deleting them would open the
 * classic unlink/reopen race where two processes lock two different inodes.
 *
 * flock() belongs to the open file description, so a second fopen()+flock() in the same
 * process is refused too. That is what makes the mutex non re-entrant.
 *
 * Not for NFS or several hosts. No TTL: the lock lives until release() or until the
 * holding process dies and the kernel closes its descriptor.
 *
 * pcntl_fork() without exec inside a protected task is not supported. Close-on-exec only
 * keeps the descriptor away from exec'd commands; a forked PHP child still shares it.
 * FileLock refuses to unlock from any pid but the owner's - that guards against misuse,
 * it doesn't make fork safe: a forked child that outlives a killed owner keeps the lock
 * until it closes the descriptor or exits.
 */
final class FileMutex implements MutexInterface
{
    // \z instead of $ - with $ a trailing "\n" would slip through
    private const KEY_PATTERN = '/\A[A-Za-z0-9._:-]{1,256}\z/';

    private readonly string $directory;

    /**
     * The directory is created lazily on the first tryAcquire(), not here. Relative paths are
     * resolved against the working directory of the process at that moment.
     *
     * @throws \InvalidArgumentException when the directory path is empty or contains a NUL byte
     */
    public function __construct(string $directory)
    {
        if ($directory === '') {
            throw new \InvalidArgumentException('Lock directory must not be empty');
        }
        // PHP file functions throw a raw ValueError on NUL; it's a config mistake, say so up front
        if (str_contains($directory, "\0")) {
            throw new \InvalidArgumentException('Lock directory must not contain a NUL byte');
        }

        $trimmed = rtrim($directory, '/');
        $this->directory = $trimmed === '' ? '/' : $trimmed;
    }

    public function tryAcquire(string $key): ?LockInterface
    {
        // validate first, nothing on disk is touched for a bad key
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new \InvalidArgumentException(
                'Mutex key must be 1-256 chars of [A-Za-z0-9._:-], got ' . json_encode($key, JSON_INVALID_UTF8_SUBSTITUTE),
            );
        }

        $this->ensureDirectory();

        $path = $this->lockPath($key);

        // 'c' = create if missing, never truncate. 'e' = close-on-exec: without it a task that starts
        // an external command passes the locked descriptor on, and that child keeps the lock alive
        // even after our process is killed. It does nothing for pcntl_fork() without exec, that's
        // unsupported (see the class doc). No exists-check before, the open+lock is the check.
        error_clear_last();
        $handle = @fopen($path, 'ce');
        if ($handle === false) {
            throw new MutexException(sprintf('Cannot open lock file %s for key "%s": %s', $path, $key, self::lastError()));
        }

        $wouldBlock = 0;
        error_clear_last();
        if (@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            return new FileLock($handle, $key);
        }

        // keep the error before fclose() can overwrite it
        $error = self::lastError();
        fclose($handle);

        if ($wouldBlock === 1) {
            return null; // somebody holds it, that's the only case for null
        }

        throw new MutexException(sprintf('flock() failed on %s for key "%s": %s', $path, $key, $error));
    }

    private function lockPath(string $key): string
    {
        $prefix = $this->directory === '/' ? '' : $this->directory;

        return $prefix . '/' . hash('sha256', $key) . '.lock';
    }

    /**
     * @throws MutexException
     */
    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        error_clear_last();
        if (@mkdir($this->directory, 0777, true)) {
            return;
        }

        $error = self::lastError();
        // another process may have created it between is_dir() and mkdir()
        clearstatcache(true, $this->directory);
        if (is_dir($this->directory)) {
            return;
        }

        throw new MutexException(sprintf('Cannot create lock directory %s: %s', $this->directory, $error));
    }

    private static function lastError(): string
    {
        return error_get_last()['message'] ?? 'unknown error';
    }
}
