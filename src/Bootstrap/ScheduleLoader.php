<?php

declare(strict_types=1);

namespace SchedulerTest\Bootstrap;

use SchedulerTest\Contract\Exception\DuplicateTaskIdException;
use SchedulerTest\Contract\Exception\ScheduleConfigurationException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\ScheduleProviderInterface;
use SchedulerTest\Contract\SchedulerInterface;

/**
 * Backend-neutral bootstrap step: read the provider once, check the whole set,
 * then register it. Used by every process that needs the schedule (Laravel's
 * schedule:run, the crunz task file and the crunz worker).
 *
 * The full set is checked before the first register() call, so a duplicate or a
 * wrong element never leaves a half-registered schedule behind. A cron value the
 * backend parser rejects is only found during register(); the exception still
 * escapes, so the native runner fails as a whole instead of running a partial set.
 */
final class ScheduleLoader
{
    /**
     * @return list<ScheduledTask>
     *
     * @throws ScheduleConfigurationException when the provider fails or yields something that is not a ScheduledTask
     * @throws DuplicateTaskIdException       when two entries share an id
     */
    public function load(ScheduleProviderInterface $provider): array
    {
        $tasks = [];
        $seen = [];

        try {
            foreach ($provider->tasks() as $task) {
                if (!$task instanceof ScheduledTask) {
                    throw new ScheduleConfigurationException(sprintf(
                        'Schedule provider %s yielded %s, expected %s.',
                        $provider::class,
                        get_debug_type($task),
                        ScheduledTask::class,
                    ));
                }

                if (isset($seen[$task->id])) {
                    throw DuplicateTaskIdException::forId($task->id);
                }

                $seen[$task->id] = true;
                $tasks[] = $task;
            }
        } catch (ScheduleConfigurationException|DuplicateTaskIdException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ScheduleConfigurationException(
                sprintf('Schedule provider %s failed: %s', $provider::class, $e->getMessage()),
                0,
                $e,
            );
        }

        return $tasks;
    }

    /**
     * Loads the full set and registers every task with the given scheduler.
     *
     * @throws ScheduleConfigurationException|DuplicateTaskIdException see load()
     * @throws \Throwable                                               whatever SchedulerInterface::register() throws
     */
    public function registerAll(ScheduleProviderInterface $provider, SchedulerInterface $scheduler): void
    {
        foreach ($this->load($provider) as $task) {
            $scheduler->register($task);
        }
    }
}
