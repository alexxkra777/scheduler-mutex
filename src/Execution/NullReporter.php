<?php

declare(strict_types=1);

namespace SchedulerTest\Execution;

final class NullReporter implements ExecutionReporterInterface
{
    public function report(ExecutionResult $result): void
    {
    }
}
