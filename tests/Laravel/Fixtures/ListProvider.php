<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Laravel\Fixtures;

use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\ScheduleProviderInterface;

final class ListProvider implements ScheduleProviderInterface
{
    /** @param list<ScheduledTask> $tasks */
    public function __construct(private readonly array $tasks)
    {
    }

    public function tasks(): iterable
    {
        yield from $this->tasks;
    }
}
