<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Laravel\Fixtures;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;

/**
 * Stand-in for Laravel's onOneServer() mutex. Must stay untouched as well.
 */
final class RecordingSchedulingMutex implements SchedulingMutex
{
    /** @var list<string> */
    public array $calls = [];

    public function create(Event $event, DateTimeInterface $time)
    {
        $this->calls[] = 'create';

        return true;
    }

    public function exists(Event $event, DateTimeInterface $time)
    {
        $this->calls[] = 'exists';

        return false;
    }
}
