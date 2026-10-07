<?php

declare(strict_types=1);

namespace SchedulerTest\Contract\Exception;

/**
 * Releasing a lock failed. The ownership state is unknown afterwards.
 */
class MutexReleaseException extends MutexException
{
}
