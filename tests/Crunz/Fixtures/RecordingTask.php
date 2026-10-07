<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz\Fixtures;

use SchedulerTest\Contract\TaskInterface;

/** Leaves "<id> started pid=<pid>" (and "finished" after the optional sleep) in the output file. */
final class RecordingTask implements TaskInterface
{
    public function __construct(
        private readonly string $output,
        private readonly string $id,
        private readonly float $sleepSeconds = 0.0,
    ) {
    }

    public function execute(): void
    {
        $this->write('started');
        if ($this->sleepSeconds > 0) {
            usleep((int) ($this->sleepSeconds * 1_000_000));
        }
        $this->write('finished');
    }

    private function write(string $what): void
    {
        if (file_put_contents($this->output, sprintf("%s %s pid=%d\n", $this->id, $what, getmypid()), FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('cannot write ' . $this->output);
        }
    }
}
