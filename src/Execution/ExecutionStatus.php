<?php

declare(strict_types=1);

namespace SchedulerTest\Execution;

/**
 * Outcome of one execution attempt. Internal - it shows up in the reporter and in
 * TaskExecutionException, the backend adapters never look at it.
 */
enum ExecutionStatus: string
{
    case SUCCESS = 'success';
    // the lock was busy, the task did not run; this is a normal return
    case SKIPPED_LOCKED = 'skipped_locked';
    case FAILED_TASK = 'failed_task';
    // tryAcquire() threw, the task did not run
    case FAILED_MUTEX = 'failed_mutex';
    // the task finished fine but release() threw - so not a success either
    case FAILED_CLEANUP = 'failed_cleanup';
}
