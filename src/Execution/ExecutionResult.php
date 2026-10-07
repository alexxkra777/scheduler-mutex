<?php

declare(strict_types=1);

namespace SchedulerTest\Execution;

/**
 * What happened in one attempt, handed to the reporter.
 *
 * - error:        primary cause (task or mutex failure, null on success/skip and on FAILED_CLEANUP)
 * - cleanupError: release() failure, kept next to the task error when both happened
 */
final readonly class ExecutionResult
{
    public function __construct(
        public string $taskId,
        public ExecutionStatus $status,
        public \DateTimeImmutable $startedAt,
        public float $durationMs,
        public ?\Throwable $error = null,
        public ?\Throwable $cleanupError = null,
    ) {
    }
}
