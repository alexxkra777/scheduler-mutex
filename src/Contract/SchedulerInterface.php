<?php

declare(strict_types=1);

namespace SchedulerTest\Contract;

use SchedulerTest\Contract\Exception\DuplicateTaskIdException;
use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Contract\Exception\SchedulerIntegrationException;

/**
 * Registers our task definitions in the native schedule of the current process.
 *
 * Nothing is persisted and nothing runs right away: the backend's own runner
 * (e.g. `php artisan schedule:run`) later decides which tasks are due and runs
 * them through the shared executor. Every new process registers again, usually
 * via ScheduleLoader and a ScheduleProviderInterface.
 */
interface SchedulerInterface
{
    /**
     * @throws InvalidCronExpressionException when the backend's cron parser rejects the expression
     * @throws DuplicateTaskIdException        when a task with the same id is already registered here
     * @throws SchedulerIntegrationException   when the backend fails to create the native event
     */
    public function register(ScheduledTask $task): void;
}
