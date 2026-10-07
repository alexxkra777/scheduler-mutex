<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Execution\Fake;

use SchedulerTest\Execution\ExecutionReporterInterface;
use SchedulerTest\Execution\ExecutionResult;

final class RecordingReporter implements ExecutionReporterInterface
{
    /** @var list<ExecutionResult> */
    public array $results = [];

    public function __construct(
        private readonly ?FakeMutex $mutex = null,
        private readonly ?\Throwable $error = null,
    ) {
    }

    public function report(ExecutionResult $result): void
    {
        $this->results[] = $result;
        if ($this->mutex !== null) {
            $this->mutex->log[] = 'report:' . $result->status->value;
        }

        if ($this->error !== null) {
            throw $this->error;
        }
    }
}
