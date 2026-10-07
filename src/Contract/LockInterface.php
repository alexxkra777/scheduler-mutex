<?php

declare(strict_types=1);

namespace SchedulerTest\Contract;

use SchedulerTest\Contract\Exception\MutexReleaseException;

/**
 * Ownership handle returned by MutexInterface::tryAcquire().
 *
 * It releases only the ownership it represents - there is no release by key and
 * no force release. It lives inside the executor: it is not handed to task code,
 * not serialized, not cloned and not passed to another process.
 *
 * Only the process that acquired the lock may release it. A copy inherited through
 * pcntl_fork() is not an owner (see TaskInterface for the fork limitation).
 */
interface LockInterface
{
    /**
     * Releases this ownership. After a successful release further calls are no-ops,
     * and an old handle can never release a lock somebody else took later.
     *
     * @throws MutexReleaseException when the backend failed (the lock state is then unknown,
     *                               an explicit retry on the same handle is allowed) or when
     *                               called from a process that does not own the lock
     */
    public function release(): void;
}
