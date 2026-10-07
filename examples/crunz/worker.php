#!/usr/bin/env php
<?php

declare(strict_types=1);

// Worker started by every crunz event: `php worker.php task:run <id>`. Same application wiring
// as the crunz task file and the Laravel demo (DemoApplication), so the same provider, executor,
// lock directory and journal. Exit codes: see WorkerCommand.

use SchedulerTest\Examples\App\DemoApplication;
use SchedulerTest\Infrastructure\Crunz\WorkerCommand;

require __DIR__ . '/../../vendor/autoload.php';

$demo = DemoApplication::fromEnvironment();

/** @var list<string> $argv */
exit((new WorkerCommand($demo->provider(...), $demo->executor(...)))->run(array_slice($argv, 1), STDERR));
