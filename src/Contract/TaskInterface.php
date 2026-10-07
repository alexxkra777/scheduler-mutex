<?php

declare(strict_types=1);

namespace SchedulerTest\Contract;

/**
 * A piece of application work that the scheduler can run.
 *
 * Everything the task needs (services, parameters) goes into its constructor,
 * so execute() takes no arguments. The call is synchronous: returning means the
 * work is done, any Throwable means it failed. There is no "return false" signal.
 *
 * Calling execute() directly gives no mutex protection - protected runs always
 * go through the shared executor.
 *
 * Not supported inside a protected task: pcntl_fork() without exec. A forked PHP
 * child shares the lock's descriptor; the shipped FileMutex refuses to unlock from
 * the child, but a child that outlives a killed parent keeps the lock held.
 * Starting external commands (exec) is fine - they don't inherit the lock.
 */
interface TaskInterface
{
    /**
     * @throws \Throwable when the work failed
     */
    public function execute(): void;
}
