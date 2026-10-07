<?php

declare(strict_types=1);

// crunz task file: crunz `require`s every *Tasks.php in tasks/ inside `crunz schedule:run` and
// `schedule:list`, and expects a Crunz\Schedule back. Composer's autoloader is already loaded by
// vendor/bin/crunz. Same wiring as the worker and the Laravel demo (DemoApplication).
//
// If the provider, the loader or one registration throws, the exception leaves this file, crunz
// stops with a non-zero exit code and runs no event at all - from this file or any other.

use Crunz\Clock\ClockInterface;
use Crunz\Event;
use Crunz\Schedule;
use SchedulerTest\Bootstrap\ScheduleLoader;
use SchedulerTest\Examples\App\DemoApplication;
use SchedulerTest\Infrastructure\Crunz\CrunzScheduler;

$demo = DemoApplication::fromEnvironment();

// Test hook only: freeze crunz's clock so tests can ask "what is due at <time>" without waiting
// for that time. crunz has no public API for it; this is how crunz's own tests do it
// (EventTest::setClockNow() sets the private static Event::$clock). crunz still makes the due
// decision itself. The worker never looks at this clock - it doesn't check due at all.
if ($demo->fakeNow !== null) {
    $frozen = new class(new DateTimeImmutable($demo->fakeNow)) implements ClockInterface {
        public function __construct(private readonly DateTimeImmutable $now)
        {
        }

        public function now(): DateTimeImmutable
        {
            return $this->now;
        }
    };
    (new ReflectionProperty(Event::class, 'clock'))->setValue(null, $frozen);
}

// Build the executor here too, although only the worker uses it: an invalid app or env name must stop
// crunz now. Otherwise every worker would exit 3 while crunz still exits 0. Nothing is written to disk
// by this, so an unwritable lock or journal path still shows up only on first use (as in Laravel).
$demo->executor();

$schedule = new Schedule();

(new ScheduleLoader())->registerAll(
    $demo->provider(),
    new CrunzScheduler($schedule, dirname(__DIR__) . '/worker.php'),
);

return $schedule;
