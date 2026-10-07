<?php

declare(strict_types=1);

namespace SchedulerTest\Examples;

use SchedulerTest\Contract\TaskInterface;

/** Always throws - shows how a failed job looks in the journal. */
final class FailingTask implements TaskInterface
{
    public function __construct(private readonly OutputFile $output)
    {
    }

    public function execute(): void
    {
        $this->output->write('failing-task started');

        throw new \RuntimeException('Demo failure: the upstream API did not answer.');
    }
}
