<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Execution\Fake;

use SchedulerTest\Contract\LockInterface;

final class FakeLock implements LockInterface
{
    public int $releaseCalls = 0;

    public function __construct(
        private readonly FakeMutex $mutex,
        public readonly string $key,
        private readonly ?\Throwable $releaseError = null,
    ) {
    }

    public function release(): void
    {
        $this->releaseCalls++;
        $this->mutex->log[] = 'release:' . $this->key;

        if ($this->releaseError !== null) {
            throw $this->releaseError;
        }
    }
}
