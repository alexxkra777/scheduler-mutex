<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Laravel\Fixtures;

use SchedulerTest\Contract\TaskInterface;

/**
 * The adapter never calls the task itself (the runner does), so this does nothing.
 */
final class NoopTask implements TaskInterface
{
    public function execute(): void
    {
    }
}
