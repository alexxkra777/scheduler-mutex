<?php

declare(strict_types=1);

// Test fixture: the cases from FIXTURE_CASES registered through the real CrunzScheduler.
// FIXTURE_NOW freezes crunz's clock, FIXTURE_PHP sets the php binary the events start.

use Crunz\Schedule;
use SchedulerTest\Bootstrap\ScheduleLoader;
use SchedulerTest\Infrastructure\Crunz\CrunzScheduler;
use SchedulerTest\Tests\Crunz\Fixtures\CaseProvider;
use SchedulerTest\Tests\Crunz\Fixtures\FrozenClock;

$now = getenv('FIXTURE_NOW');
if (is_string($now) && $now !== '') {
    FrozenClock::freezeAt($now);
}

$php = getenv('FIXTURE_PHP');
$schedule = new Schedule();
(new ScheduleLoader())->registerAll(
    new CaseProvider(),
    new CrunzScheduler($schedule, dirname(__DIR__) . '/worker.php', is_string($php) && $php !== '' ? $php : null),
);

return $schedule;
