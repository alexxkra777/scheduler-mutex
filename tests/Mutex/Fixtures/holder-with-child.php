<?php

declare(strict_types=1);

/*
 * Child process for FileMutexTest: takes the lock, then starts a long-running
 * grandchild (`sleep`) the way a task running an external command would, writes
 * "<readyFile>" with the grandchild's pid and waits to be killed.
 *
 *   php holder-with-child.php <lockDir> <key> <readyFile>
 */

use SchedulerTest\Infrastructure\Mutex\FileMutex;

require __DIR__ . '/../../../vendor/autoload.php';

[, $lockDir, $key, $readyFile] = $_SERVER['argv'] + [null, null, null, null];

$lock = (new FileMutex((string) $lockDir))->tryAcquire((string) $key);
if ($lock === null) {
    exit(3);
}

$child = proc_open(['sleep', '20'], [], $pipes);
if ($child === false) {
    exit(4);
}

file_put_contents($readyFile . '.tmp', (string) proc_get_status($child)['pid']);
rename($readyFile . '.tmp', (string) $readyFile);

// the test kills us with SIGKILL long before this ends
sleep(20);
exit(0);
