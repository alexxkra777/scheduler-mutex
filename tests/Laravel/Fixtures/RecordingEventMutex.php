<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Laravel\Fixtures;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;

/**
 * Stand-in for Laravel's overlap mutex. The adapter must never make Laravel use it.
 */
final class RecordingEventMutex implements EventMutex
{
    /** @var list<string> */
    public array $calls = [];

    public function create(Event $event)
    {
        $this->calls[] = 'create';

        return true;
    }

    public function exists(Event $event)
    {
        $this->calls[] = 'exists';

        return false;
    }

    public function forget(Event $event)
    {
        $this->calls[] = 'forget';
    }
}
