<?php

declare(strict_types=1);

namespace SchedulerTest\Examples;

use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\ScheduleProviderInterface;

/**
 * The demo's whole schedule, written only against our own contracts.
 * Swapping Laravel for another backend doesn't touch this file.
 */
final class DemoScheduleProvider implements ScheduleProviderInterface
{
    public function __construct(
        private readonly string $outputFile,
        private readonly float $slowSeconds = 5.0,
        private readonly bool $includeBrokenTask = false,
        private readonly bool $failWhileLoading = false,
    ) {
    }

    public function tasks(): iterable
    {
        $output = new OutputFile($this->outputFile);
        $prague = new \DateTimeZone('Europe/Prague');

        yield new ScheduledTask('heartbeat', new HeartbeatTask($output), '* * * * *');
        // the failing task sits before the slow one on purpose: one failure must not stop the rest
        yield new ScheduledTask('failing-task', new FailingTask($output), '* * * * *');
        yield new ScheduledTask('slow-report', new SlowReportTask($output, $this->slowSeconds), '* * * * *');
        yield new ScheduledTask('new-year-greeting', new NewYearGreetingTask($output), '0 0 1 JAN *', $prague);

        if ($this->failWhileLoading) {
            // e.g. a config source that is down - the loader turns this into ScheduleConfigurationException
            throw new \RuntimeException('Demo provider failure: task configuration is unavailable.');
        }

        if ($this->includeBrokenTask) {
            // passes our shape check, but minute 61 is rejected by the cron parser at registration
            yield new ScheduledTask('broken-task', new HeartbeatTask($output), '61 * * * *');
        }
    }
}
