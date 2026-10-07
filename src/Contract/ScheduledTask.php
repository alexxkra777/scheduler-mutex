<?php

declare(strict_types=1);

namespace SchedulerTest\Contract;

use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Contract\Exception\InvalidTaskDefinitionException;
use SchedulerTest\Cron\CronDialect;

/**
 * Immutable description of one schedule entry: what to run, when, and how.
 *
 * - id:             stable id of this entry, [a-z0-9][a-z0-9._-]{0,127}. It is the
 *                   mutex key part and the name in logs, so never generate it randomly.
 * - task:           the work itself; one task object may be used by several entries
 *                   with different ids (they then get different lock keys).
 * - cronExpression: five cron fields, see below. Whitespace is normalised.
 * - timezone:       wall clock the expression is read in. Default UTC, never the
 *                   system timezone by accident.
 * - preventOverlap: true = the executor holds the mutex for this id while the task
 *                   runs; false = no mutex at all.
 *
 * Cron dialect v1: minute, hour, day of month, month, day of week. Allowed are `*`,
 * numbers, lists (1,2), ranges (1-5), steps on `*` or a range (*\/5, 1-30/2), month
 * names JAN-DEC and day names SUN-SAT in any case, also inside ranges with steps
 * (MON-FRI/2). Day of week accepts 0-7 (0 and 7 are Sunday; MON-SUN works). A step
 * must be 1..max value of its field (59, 23, 31, 12, 7); ranges must not go
 * backwards. Macros (@daily) and the extensions L, W, #, ? are rejected. Value
 * ranges (minute 61 etc.) and never-matching dates (Feb 31) are checked by the
 * backend's parser at registration. See SchedulerTest\Cron\CronDialect.
 */
final readonly class ScheduledTask
{
    public const ID_PATTERN = '/\A[a-z0-9][a-z0-9._-]{0,127}\z/';

    public string $cronExpression;

    /**
     * @throws InvalidTaskDefinitionException when the id is malformed, the expression is empty
     *                                        or does not have exactly five fields
     * @throws InvalidCronExpressionException when a field uses syntax outside the v1 dialect
     */
    public function __construct(
        public string $id,
        public TaskInterface $task,
        string $cronExpression,
        public \DateTimeZone $timezone = new \DateTimeZone('UTC'),
        public bool $preventOverlap = true,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidTaskDefinitionException(sprintf(
                'Task id "%s" is invalid: expected 1-128 chars of [a-z0-9._-], starting with a letter or digit.',
                $id,
            ));
        }

        $this->cronExpression = self::normaliseCron($id, $cronExpression);
    }

    private static function normaliseCron(string $id, string $expression): string
    {
        $trimmed = trim($expression);
        if ($trimmed === '') {
            throw new InvalidTaskDefinitionException(sprintf('Task "%s": cron expression is empty.', $id));
        }

        $fields = preg_split('/\s+/', $trimmed);
        if ($fields === false || count($fields) !== 5) {
            throw new InvalidTaskDefinitionException(sprintf(
                'Task "%s": cron expression "%s" must have exactly 5 fields (minute hour day-of-month month day-of-week).',
                $id,
                $trimmed,
            ));
        }

        $normalised = implode(' ', $fields);

        try {
            // only validates here; adapters call it again to get the numeric form they register
            CronDialect::toNumeric($normalised);
        } catch (InvalidCronExpressionException $e) {
            throw new InvalidCronExpressionException(sprintf('Task "%s": %s', $id, $e->getMessage()), 0, $e);
        }

        return $normalised;
    }
}
