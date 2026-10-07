<?php

declare(strict_types=1);

namespace SchedulerTest\Examples;

use SchedulerTest\Contract\TaskInterface;

/**
 * Takes a while, so two scheduler processes can actually collide on it.
 * Writes a start and an end marker - overlapping runs would show up as
 * two "started" lines without a "finished" in between.
 */
final class SlowReportTask implements TaskInterface
{
    public function __construct(
        private readonly OutputFile $output,
        private readonly float $seconds,
    ) {
    }

    public function execute(): void
    {
        $this->output->write('slow-report started');
        usleep((int) ($this->seconds * 1_000_000));
        $this->output->write('slow-report finished');
    }
}
