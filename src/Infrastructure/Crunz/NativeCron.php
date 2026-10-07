<?php

declare(strict_types=1);

namespace SchedulerTest\Infrastructure\Crunz;

use Cron\CronExpression;
use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Cron\CronDialect;

/**
 * The cron string the crunz side registers, checked the way crunz will evaluate it later.
 *
 * crunz 3.9 decides "is due" with dragonmantank/cron-expression (`CronExpression::factory()`
 * in Event::isDue()), and Event::cron() itself only counts the fields. So the numeric form
 * goes through the same parser here, before any native event exists. Used by CrunzScheduler
 * and by the worker, so both reject exactly the same definitions.
 *
 * @internal
 */
final class NativeCron
{
    private function __construct()
    {
    }

    /**
     * @return string the numeric form of the task's expression, as handed to crunz
     *
     * @throws InvalidCronExpressionException when the dialect or the parser rejects it, or it can never match
     */
    public static function numericFor(ScheduledTask $task): string
    {
        try {
            $expression = CronDialect::toNumeric($task->cronExpression);
        } catch (InvalidCronExpressionException $e) {
            throw new InvalidCronExpressionException(sprintf('Task "%s": %s', $task->id, $e->getMessage()), 0, $e);
        }

        try {
            // getNextRunDate() also catches dates that can never happen, like "0 0 31 2 *"
            (new CronExpression($expression))->getNextRunDate('now', 0, false, $task->timezone->getName());
        } catch (\Throwable $e) {
            throw new InvalidCronExpressionException(
                sprintf('Task "%s": cron expression "%s" rejected by the parser: %s', $task->id, $task->cronExpression, $e->getMessage()),
                0,
                $e,
            );
        }

        return $expression;
    }
}
