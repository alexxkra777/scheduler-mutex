<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Execution\Fake;

use SchedulerTest\Contract\LockInterface;
use SchedulerTest\Contract\MutexInterface;

/**
 * Records calls; can be told to report "busy", to fail on acquire or to fail on release.
 * Shares its call log with tasks and reporters so tests can check the order of things.
 */
final class FakeMutex implements MutexInterface
{
    /** @var list<string> */
    public array $log = [];

    /** @var list<string> */
    public array $acquiredKeys = [];

    /** @var list<FakeLock> */
    public array $locks = [];

    public bool $busy = false;
    public ?\Throwable $acquireError = null;
    public ?\Throwable $releaseError = null;

    public function tryAcquire(string $key): ?LockInterface
    {
        $this->acquiredKeys[] = $key;
        $this->log[] = 'acquire:' . $key;

        if ($this->acquireError !== null) {
            throw $this->acquireError;
        }
        if ($this->busy) {
            return null;
        }

        return $this->locks[] = new FakeLock($this, $key, $this->releaseError);
    }

    public function heldLock(): ?FakeLock
    {
        foreach ($this->locks as $lock) {
            if ($lock->releaseCalls === 0) {
                return $lock;
            }
        }

        return null;
    }
}
