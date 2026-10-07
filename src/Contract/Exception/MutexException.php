<?php

declare(strict_types=1);

namespace SchedulerTest\Contract\Exception;

/**
 * The mutex backend failed. This is never used for "lock is busy" - that is a null from tryAcquire().
 */
class MutexException extends \RuntimeException
{
}
