<?php

declare(strict_types=1);

namespace SchedulerTest\Contract\Exception;

/**
 * The scheduler backend failed to register a task. The backend error is the previous exception.
 */
class SchedulerIntegrationException extends \RuntimeException
{
}
