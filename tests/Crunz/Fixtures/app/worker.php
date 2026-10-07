<?php

declare(strict_types=1);

// Test fixture worker: the same WorkerCommand as the demo, over CaseProvider.
// FIXTURE_AUTOLOAD lets a copy of this directory live anywhere (paths with spaces and quotes).

use SchedulerTest\Execution\JsonLinesReporter;
use SchedulerTest\Execution\TaskExecutor;
use SchedulerTest\Infrastructure\Crunz\WorkerCommand;
use SchedulerTest\Infrastructure\Mutex\FileMutex;
use SchedulerTest\Tests\Crunz\Fixtures\CaseProvider;

$autoload = getenv('FIXTURE_AUTOLOAD');
require is_string($autoload) && $autoload !== '' ? $autoload : dirname(__DIR__, 4) . '/vendor/autoload.php';

$command = new WorkerCommand(
    static fn (): CaseProvider => new CaseProvider(),
    static fn (): TaskExecutor => new TaskExecutor(
        new FileMutex((string) getenv('FIXTURE_LOCK_DIR')),
        'fixture',
        'test',
        new JsonLinesReporter((string) getenv('FIXTURE_JOURNAL')),
    ),
);

/** @var list<string> $argv */
// FIXTURE_ARGV_LOG: what this process really got from crunz -> Symfony -> sh
$argvLog = getenv('FIXTURE_ARGV_LOG');
if (is_string($argvLog) && $argvLog !== '') {
    file_put_contents($argvLog, json_encode(['file' => __FILE__, 'argv' => $argv], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
}

exit($command->run(array_slice($argv, 1), STDERR));
