<?php

declare(strict_types=1);

/*
 * Child process for FileMutexTest. Usage:
 *
 *   php lock-holder.php <lockDir> <key> <resultFile> [<goFile> [<releaseFile>]]
 *
 * 1. If goFile is given: write "<resultFile>.ready", then wait until goFile shows up.
 * 2. tryAcquire(key) and write "acquired", "busy" or "error: ..." to resultFile
 *    (tmp + rename, so the test never reads half a line).
 * 3. If acquired and releaseFile is given: hold the lock until releaseFile shows up,
 *    then release. Without releaseFile it releases right away.
 *
 * Every wait is bounded, so a forgotten child dies by itself. "-" means "no goFile".
 */

use SchedulerTest\Infrastructure\Mutex\FileMutex;

require __DIR__ . '/../../../vendor/autoload.php';

$argv = $_SERVER['argv'];

[$lockDir, $key, $resultFile] = array_slice($argv, 1, 3) + [null, null, null];
$goFile = $argv[4] ?? '-';
$releaseFile = $argv[5] ?? null;

if ($lockDir === null || $key === null || $resultFile === null) {
    fwrite(STDERR, "usage: lock-holder.php <lockDir> <key> <resultFile> [<goFile> [<releaseFile>]]\n");
    exit(2);
}

$waitFor = static function (string $file, float $seconds): bool {
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $file);
        if (file_exists($file)) {
            return true;
        }
        usleep(500);
    }

    return false;
};

$writeResult = static function (string $text) use ($resultFile): void {
    file_put_contents($resultFile . '.tmp', $text);
    rename($resultFile . '.tmp', $resultFile);
};

if ($goFile !== '-') {
    touch($resultFile . '.ready');
    if (!$waitFor($goFile, 10.0)) {
        $writeResult('error: go file never appeared');
        exit(3);
    }
}

try {
    $lock = (new FileMutex($lockDir))->tryAcquire($key);
} catch (Throwable $e) {
    $writeResult('error: ' . get_class($e) . ': ' . $e->getMessage());
    exit(4);
}

if ($lock === null) {
    $writeResult('busy');
    exit(0);
}

$writeResult('acquired');

if ($releaseFile !== null && !$waitFor($releaseFile, 30.0)) {
    fwrite(STDERR, "release file never appeared, releasing anyway\n");
}

$lock->release();
exit(0);
