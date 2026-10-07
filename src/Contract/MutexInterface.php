<?php

declare(strict_types=1);

namespace SchedulerTest\Contract;

use SchedulerTest\Contract\Exception\MutexException;

/**
 * Non-blocking exclusive lock by key.
 *
 * tryAcquire() either takes the lock atomically and hands back the ownership
 * handle, or returns null right away when somebody else holds it. It never waits
 * or retries. null means "busy" and nothing else - technical problems are thrown.
 *
 * Not re-entrant: while a handle for a key is alive, another tryAcquire() for the
 * same key returns null, even in the same process.
 *
 * There is no TTL. The lock lives until release() or until the owning process
 * ends. A backend with expiring leases (e.g. Redis with TTL) gives weaker
 * guarantees and is not a drop-in replacement for this contract.
 */
interface MutexInterface
{
    /**
     * Key: 1-256 ASCII chars from [A-Za-z0-9._:-].
     *
     * @throws \InvalidArgumentException when the key does not match the format
     * @throws MutexException            when the backend fails (the task must not run)
     */
    public function tryAcquire(string $key): ?LockInterface;
}
