<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Execution\Fake;

use SchedulerTest\Contract\TaskInterface;

final class FakeTask implements TaskInterface
{
    public int $calls = 0;

    /** @var list<mixed> arguments execute() was called with - must stay empty */
    public array $receivedArgs = [];

    public bool $lockWasHeld = false;

    public function __construct(
        private readonly ?FakeMutex $mutex = null,
        private readonly ?\Throwable $error = null,
    ) {
    }

    public function execute(): void
    {
        $this->calls++;
        $this->receivedArgs = func_get_args();

        if ($this->mutex !== null) {
            $this->mutex->log[] = 'task';
            $this->lockWasHeld = $this->mutex->heldLock() !== null;
        }

        if ($this->error !== null) {
            throw $this->error;
        }
    }
}
