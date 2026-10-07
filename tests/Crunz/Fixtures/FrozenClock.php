<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz\Fixtures;

use Crunz\Clock\ClockInterface;
use Crunz\Event;

/**
 * crunz's due check reads the private static Event::$clock. crunz's own tests swap it the same
 * way (EventTest::setClockNow()). Only the clock is replaced - crunz still decides "is due".
 */
final class FrozenClock implements ClockInterface
{
    private function __construct(private readonly \DateTimeImmutable $now)
    {
    }

    public static function freezeAt(string $time): void
    {
        (new \ReflectionProperty(Event::class, 'clock'))->setValue(null, new self(new \DateTimeImmutable($time)));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
