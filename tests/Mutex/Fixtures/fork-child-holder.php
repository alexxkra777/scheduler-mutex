<?php

declare(strict_types=1);

/*
 * Child process for FileMutexTest: takes the lock and then misuses it the way a task
 * that calls pcntl_fork() without exec would. Three forked children, one after another,
 * each gated by a file from the test:
 *
 *   release - the child calls release() on the inherited handle (twice), writes what happened
 *   drop    - the child unsets the handle so the destructor runs, then stays alive until
 *             <syncDir>/drop.continue shows up
 *   exit    - the child just exits normally with the handle still alive (shutdown destructor)
 *
 * After each child the parent reaps it and writes <syncDir>/<step>.reaped. Then it waits for
 * <syncDir>/release, releases its own lock and writes <syncDir>/released.
 *
 *   php fork-child-holder.php <lockDir> <key> <syncDir>
 *
 * We lead our own process group, so the test can kill us and every forked child with one
 * kill(-pid). All waits are bounded anyway, a forgotten process dies by itself.
 */

use SchedulerTest\Contract\Exception\MutexReleaseException;
use SchedulerTest\Infrastructure\Mutex\FileMutex;

require __DIR__ . '/../../../vendor/autoload.php';

[, $lockDir, $key, $syncDir] = $_SERVER['argv'] + [null, null, null, null];

if ($lockDir === null || $key === null || $syncDir === null) {
    fwrite(STDERR, "usage: fork-child-holder.php <lockDir> <key> <syncDir>\n");
    exit(2);
}

$write = static function (string $name, string $text) use ($syncDir): void {
    file_put_contents("$syncDir/$name.tmp", $text);
    rename("$syncDir/$name.tmp", "$syncDir/$name");
};

$waitFor = static function (string $name, float $seconds) use ($syncDir): bool {
    $file = "$syncDir/$name";
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $file);
        if (file_exists($file)) {
            return true;
        }
        usleep(1000);
    }

    return false;
};

if (!function_exists('pcntl_fork')) {
    $write('ready', 'error: pcntl extension is not loaded');
    exit(5);
}

if (!posix_setpgid(0, 0)) {
    $write('ready', 'error: posix_setpgid() failed: ' . posix_strerror(posix_get_last_error()));
    exit(5);
}

$lock = (new FileMutex($lockDir))->tryAcquire($key);
if ($lock === null) {
    $write('ready', 'busy');
    exit(3);
}

$parentPid = getmypid();
$write('ready', "acquired $parentPid");

$runChild = static function (string $step) use (&$lock, $write, $waitFor, $parentPid): never {
    $me = getmypid();
    switch ($step) {
        case 'release':
            if ($lock === null) {
                exit(8);
            }
            $outcome = [];
            for ($i = 1; $i <= 2; $i++) {
                try {
                    $lock->release();
                    $outcome[] = 'returned';
                } catch (MutexReleaseException $e) {
                    $outcome[] = 'exception: ' . $e->getMessage();
                } catch (Throwable $e) {
                    $outcome[] = 'other: ' . get_class($e) . ': ' . $e->getMessage();
                }
            }
            $write('release.child', "parent=$parentPid child=$me\n" . implode("\n", $outcome));
            exit(0);
        case 'drop':
            $lock = null; // only reference in this process, destructor runs right here
            gc_collect_cycles();
            $write('drop.child', "parent=$parentPid child=$me\ndropped");
            exit($waitFor('drop.continue', 5.0) ? 0 : 6);
        case 'exit':
            $write('exit.child', "parent=$parentPid child=$me\nexiting");
            exit(0); // $lock is still alive, shutdown destroys it
    }
    exit(7);
};

foreach (['release', 'drop', 'exit'] as $step) {
    if (!$waitFor("$step.go", 10.0)) {
        $write("$step.reaped", 'error: go file never appeared');
        exit(4);
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        $write("$step.reaped", 'error: fork failed');
        exit(4);
    }
    if ($pid === 0) {
        $runChild($step);
    }

    // bounded reap, the child never waits longer than 5 s for anything
    $status = 0;
    $deadline = microtime(true) + 8.0;
    $reaped = 0;
    while (microtime(true) < $deadline) {
        $reaped = pcntl_waitpid($pid, $status, WNOHANG);
        if ($reaped !== 0) {
            break;
        }
        usleep(1000);
    }
    if ($reaped !== $pid) {
        posix_kill($pid, 9);
        pcntl_waitpid($pid, $status);
        $write("$step.reaped", 'error: child did not exit in time');
        exit(4);
    }

    $write("$step.reaped", pcntl_wifexited($status) ? 'exit ' . pcntl_wexitstatus($status) : 'signaled');
}

if (!$waitFor('release', 10.0)) {
    fwrite(STDERR, "release file never appeared, releasing anyway\n");
}

try {
    if ($lock === null) {
        throw new LogicException('parent lost its own handle');
    }
    $lock->release();
    $write('released', 'released');
} catch (Throwable $e) {
    $write('released', 'error: ' . get_class($e) . ': ' . $e->getMessage());
    exit(4);
}
exit(0);
