<?php

declare(strict_types=1);

namespace SchedulerTest\Execution;

use SchedulerTest\Contract\LockInterface;
use SchedulerTest\Contract\MutexInterface;
use SchedulerTest\Contract\ScheduledTask;

/**
 * The one place where scheduled tasks actually run, whatever the backend.
 *
 * execute() is synchronous: when it returns, the task is done (or was skipped
 * because the lock was busy). Every failure leaves as TaskExecutionException,
 * after the lock has been released. The reporter gets exactly one result per
 * attempt and is called last, so a broken reporter can never keep a lock held.
 *
 * Backend adapters get `$executor->execute(...)` as a Closure(ScheduledTask): void.
 */
final class TaskExecutor
{
    private readonly TaskLockKey $lockKey;
    private readonly ExecutionReporterInterface $reporter;

    /**
     * @throws \InvalidArgumentException when application or environment is not a valid key part
     */
    public function __construct(
        private readonly MutexInterface $mutex,
        string $application,
        string $environment,
        ?ExecutionReporterInterface $reporter = null,
    ) {
        $this->lockKey = new TaskLockKey($application, $environment);
        $this->reporter = $reporter ?? new NullReporter();
    }

    /**
     * @throws TaskExecutionException when the task, the mutex or the lock release failed
     */
    public function execute(ScheduledTask $task): void
    {
        $startedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start = hrtime(true);

        $status = ExecutionStatus::SUCCESS;
        $error = null;
        $cleanupError = null;

        if (!$task->preventOverlap) {
            $error = self::runTask($task);
        } else {
            try {
                $lock = $this->mutex->tryAcquire($this->lockKey->forTask($task->id));
            } catch (\Throwable $e) {
                // nothing acquired, so nothing to run and nothing to release
                $status = ExecutionStatus::FAILED_MUTEX;
                $error = $e;
                $lock = null;
            }

            if ($lock !== null) {
                // runTask() never throws, but keep the release in finally anyway -
                // it's the rule for locks and costs nothing
                try {
                    $error = self::runTask($task);
                } finally {
                    $cleanupError = self::release($lock);
                }
            } elseif ($status === ExecutionStatus::SUCCESS) {
                $status = ExecutionStatus::SKIPPED_LOCKED;
            }
        }

        if ($status === ExecutionStatus::SUCCESS) {
            if ($error !== null) {
                $status = ExecutionStatus::FAILED_TASK;
            } elseif ($cleanupError !== null) {
                $status = ExecutionStatus::FAILED_CLEANUP;
            }
        }

        $this->report(new ExecutionResult(
            $task->id,
            $status,
            $startedAt,
            (hrtime(true) - $start) / 1e6,
            $error,
            $cleanupError,
        ));

        $cause = $error ?? $cleanupError;
        if ($cause !== null) {
            throw new TaskExecutionException($task->id, $status, $cause, $cleanupError);
        }
    }

    private static function runTask(ScheduledTask $task): ?\Throwable
    {
        try {
            $task->task->execute();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    // one release attempt, no retries - a retry policy is not ours to invent here
    private static function release(LockInterface $lock): ?\Throwable
    {
        try {
            $lock->release();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function report(ExecutionResult $result): void
    {
        try {
            $this->reporter->report($result);
        } catch (\Throwable $e) {
            // the attempt's outcome stays as it is, but don't hide the broken reporter either
            error_log(str_replace(["\r", "\n"], ' ', sprintf(
                'scheduler: reporter failed for task "%s" (%s): %s: %s',
                $result->taskId,
                $result->status->value,
                $e::class,
                $e->getMessage(),
            )));
        }
    }
}
