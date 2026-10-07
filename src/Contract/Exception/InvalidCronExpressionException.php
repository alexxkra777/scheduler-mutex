<?php

declare(strict_types=1);

namespace SchedulerTest\Contract\Exception;

/**
 * The cron expression uses syntax outside the v1 dialect or values the parser rejects.
 */
class InvalidCronExpressionException extends \InvalidArgumentException
{
}
