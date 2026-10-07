<?php

declare(strict_types=1);

namespace SchedulerTest\Execution;

/**
 * Receives one result per execution attempt. Infrastructure only, not part of the
 * public contract. Implementations may throw - the executor logs that and moves on.
 */
interface ExecutionReporterInterface
{
    public function report(ExecutionResult $result): void;
}
