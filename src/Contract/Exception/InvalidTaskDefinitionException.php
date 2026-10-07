<?php

declare(strict_types=1);

namespace SchedulerTest\Contract\Exception;

/**
 * A task definition is malformed (id, empty cron, wrong number of cron fields).
 */
class InvalidTaskDefinitionException extends \InvalidArgumentException
{
}
