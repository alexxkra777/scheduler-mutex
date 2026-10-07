<?php

declare(strict_types=1);

namespace SchedulerTest\Examples;

use SchedulerTest\Contract\TaskInterface;

/** Scheduled once a year in Prague time - normally "not due". */
final class NewYearGreetingTask implements TaskInterface
{
    public function __construct(private readonly OutputFile $output)
    {
    }

    public function execute(): void
    {
        $this->output->write('new-year-greeting');
    }
}
