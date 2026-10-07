<?php

declare(strict_types=1);

namespace SchedulerTest\Contract\Exception;

/**
 * The same task id shows up twice in one task set. We never replace silently.
 */
class DuplicateTaskIdException extends \InvalidArgumentException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Task id "%s" is registered more than once.', $id));
    }
}
