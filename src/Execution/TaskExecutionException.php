<?php

declare(strict_types=1);

namespace SchedulerTest\Execution;

/**
 * Thrown by TaskExecutor when an attempt failed.
 *
 * getPrevious() is always the primary cause: the task error, the mutex error, or
 * the release error when the task itself was fine. If the task failed and release
 * failed too, the release error is kept in getCleanupError() so nothing gets lost.
 */
final class TaskExecutionException extends \RuntimeException
{
    public function __construct(
        public readonly string $taskId,
        public readonly ExecutionStatus $status,
        \Throwable $previous,
        private readonly ?\Throwable $cleanupError = null,
    ) {
        $message = sprintf(
            'Task "%s" failed (%s): %s: %s',
            $taskId,
            $status->value,
            $previous::class,
            $previous->getMessage(),
        );
        if ($cleanupError !== null && $cleanupError !== $previous) {
            $message .= sprintf('; lock release also failed: %s: %s', $cleanupError::class, $cleanupError->getMessage());
        }

        parent::__construct($message, 0, $previous);
    }

    /**
     * The release() failure, if there was one. For FAILED_CLEANUP it is the same object as getPrevious().
     */
    public function getCleanupError(): ?\Throwable
    {
        return $this->cleanupError;
    }
}
