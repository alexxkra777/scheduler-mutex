<?php

declare(strict_types=1);

namespace SchedulerTest\Contract;

/**
 * Source of the complete task set for one process.
 *
 * Every bootstrap (every scheduler or worker process) asks the provider again,
 * so objects and closures never have to travel between processes. The method may
 * return a fresh generator on each call; callers read the result only once.
 * It must not write or publish anything outside the process.
 */
interface ScheduleProviderInterface
{
    /**
     * @return iterable<ScheduledTask> finite set, ids must be unique
     *
     * @throws \Throwable when tasks cannot be built; the loader wraps it into ScheduleConfigurationException
     */
    public function tasks(): iterable;
}
