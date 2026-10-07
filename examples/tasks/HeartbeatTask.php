<?php

declare(strict_types=1);

namespace SchedulerTest\Examples;

use SchedulerTest\Contract\TaskInterface;

/** The simplest possible job: leave one line in the output file. */
final class HeartbeatTask implements TaskInterface
{
    public function __construct(private readonly OutputFile $output)
    {
    }

    public function execute(): void
    {
        $this->output->write('heartbeat');
    }
}
