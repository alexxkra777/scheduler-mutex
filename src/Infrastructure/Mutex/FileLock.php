<?php

declare(strict_types=1);

namespace SchedulerTest\Infrastructure\Mutex;

use SchedulerTest\Contract\Exception\MutexException;
use SchedulerTest\Contract\Exception\MutexReleaseException;
use SchedulerTest\Contract\LockInterface;

/**
 * Ownership handle of FileMutex. It holds the locked descriptor open until release().
 *
 * It only ever touches its own descriptor, so an old handle can't free a lock somebody
 * took later - that newer lock sits on a different open file description. The lock file
 * itself is never deleted.
 *
 * Can't be cloned or serialized: a copy would share the descriptor and look like a second
 * owner. The destructor closes the descriptor if it's still open (the kernel then drops
 * the lock), but that's only a safety net - callers release explicitly in finally.
 *
 * pcntl_fork() without exec inside a protected task is NOT supported. A forked child gets a
 * copy of the same open file description, so a flock(LOCK_UN) there would free the lock of
 * the parent that is still running. The handle remembers the pid that created it and only
 * that process may unlock. Anywhere else release() closes the inherited copy without
 * unlocking and throws; the destructor and process exit just close it too. That is misuse
 * protection, not fork support:
 * - while a forked child is alive it keeps the open file description, so if the owner is
 *   killed (or only drops the handle without release()) the lock stays held until the
 *   child closes its copy or dies;
 * - only an explicit release() in the owner process frees the lock no matter who else
 *   still has a copy.
 * Close-on-exec (see FileMutex) covers exec'd commands, it does nothing for fork.
 */
final class FileLock implements LockInterface
{
    /** @var resource|null null once released, or once dropped in a foreign process */
    private $handle;

    /** pid of the process that took the lock, the only one allowed to unlock */
    private readonly int $ownerPid;

    /** set when a foreign process tried to release, so every retry there throws too */
    private bool $foreign = false;

    /**
     * @internal created by FileMutex only
     *
     * @param resource $handle open descriptor that already holds LOCK_EX
     */
    public function __construct($handle, private readonly string $key)
    {
        $pid = getmypid();
        if ($pid === false) {
            // never seen in practice. We don't keep the descriptor, so the caller's variable is
            // the last reference - it goes away with the exception and the kernel drops the lock.
            throw new MutexException(sprintf('getmypid() failed, can not record the owner of key "%s"', $key));
        }

        $this->handle = $handle;
        $this->ownerPid = $pid;
    }

    public function release(): void
    {
        if ($this->handle === null && !$this->foreign) {
            return; // already released, no-op. Also fine in a child forked after the release.
        }

        $pid = getmypid();
        if ($pid === false) {
            throw new MutexReleaseException(sprintf('getmypid() failed, can not tell if key "%s" is ours to release', $this->key));
        }

        if ($pid !== $this->ownerPid) {
            // forked copy. Never LOCK_UN here, that would free the owner's lock.
            $this->dropForeignCopy();

            throw new MutexReleaseException(sprintf(
                'Lock for key "%s" belongs to process %d, refusing to release it from process %d '
                . '(pcntl_fork() without exec inside a protected task is not supported). '
                . 'The inherited descriptor was closed here, the owner still holds the lock.',
                $this->key,
                $this->ownerPid,
                $pid,
            ));
        }

        if (!is_resource($this->handle)) {
            // shouldn't happen, nobody else has the descriptor. We can't tell what the kernel did.
            throw new MutexReleaseException(sprintf('Lock descriptor for key "%s" is no longer open, state unknown', $this->key));
        }

        error_clear_last();
        if (!@flock($this->handle, LOCK_UN)) {
            // handle stays set, so a retry on this same handle is possible
            throw new MutexReleaseException(sprintf(
                'Cannot unlock key "%s": %s',
                $this->key,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        error_clear_last();
        if (!@fclose($this->handle)) {
            throw new MutexReleaseException(sprintf(
                'Unlocked key "%s" but could not close the descriptor: %s',
                $this->key,
                error_get_last()['message'] ?? 'unknown error',
            ));
        }

        $this->handle = null;
    }

    public function __destruct()
    {
        // No LOCK_UN here, in any process. In the owner closing is enough, the kernel drops
        // the flock with the last reference. In a forked child it's just closing the copy,
        // PHP's fclose() doesn't unlock by itself (checked with real forks on PHP 8.5).
        if (is_resource($this->handle)) {
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    /**
     * Closes this process's copy of the descriptor without unlocking and makes the handle
     * unusable here. The owner's open file description and its lock are not touched.
     */
    private function dropForeignCopy(): void
    {
        $this->foreign = true;
        if (is_resource($this->handle)) {
            @fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __clone()
    {
        // $this is the copy here. Drop its reference first, otherwise its destructor
        // would fclose() the shared descriptor and free the original's lock.
        $this->handle = null;

        throw new \LogicException('FileLock can not be cloned');
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('FileLock can not be serialized');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('FileLock can not be unserialized');
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        throw new \LogicException('FileLock can not be serialized');
    }

    public function __wakeup(): void
    {
        throw new \LogicException('FileLock can not be unserialized');
    }
}
