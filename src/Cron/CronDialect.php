<?php

declare(strict_types=1);

namespace SchedulerTest\Cron;

use SchedulerTest\Contract\Exception\InvalidCronExpressionException;

/**
 * The v1 cron dialect: checks the syntax and rewrites an expression into a plain numeric
 * form the parser evaluates consistently, e.g. `*\/59 9-17/23 * JAN-DEC/2 MON-FRI/2` ->
 * `0,59 9 * 1-12/2 1,3,5`.
 *
 * Why the rewrite: dragonmantank/cron-expression 3.x (used by Laravel 12 and crunz)
 * mishandles some valid input - names inside stepped ranges (`MON-FRI/2` ran on Sundays),
 * day ranges touching 7 (`7-7` ran every day), and minute/hour steps equal to the field
 * maximum (`10-20/59` was rejected, `*\/59` got a wrong next run date). Explicit numbers are
 * handled the same way by its due check and its next-date search, so every backend
 * adapter validates and registers this numeric form - the same string in both places.
 *
 * This is a translator, not a scheduler: it never decides whether a time is due.
 *
 * Rules on top of plain syntax:
 * - names: JAN-DEC in the month field, SUN-SAT in the day-of-week field, any case;
 * - minute/hour items with a step become explicit lists (`*\/20` -> `0,20,40`);
 * - day of week becomes an explicit list with Sunday as 0, except plain `*` / `*\/n`;
 * - `*` stays `*` and an explicit full range stays restricted - cron's day-of-month /
 *   day-of-week OR rule depends on whether the field is `*`;
 * - a step must be 1..max value of its field (minute 59, hour 23, day 31, month 12,
 *   weekday 7). Bigger steps are wrapped around by the parser, so they're rejected
 *   here instead of silently changing the schedule. A step longer than its range is
 *   fine and just picks the range start;
 * - ranges must not go backwards (FRI-MON, 5-1);
 * - minute, hour and weekday values are range-checked here (an expanded list would
 *   otherwise hide `10-70/59`); other values (day 32, month 13) are left to the parser.
 */
final class CronDialect
{
    private const FIELDS = [
        // name, max value (= step limit), literal names => number
        ['minute', 59, []],
        ['hour', 23, []],
        ['day of month', 31, []],
        ['month', 12, ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
            'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12]],
        ['day of week', 7, ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6]],
    ];

    private function __construct()
    {
    }

    /**
     * @param string $expression five fields separated by single spaces
     *
     * @return string the same schedule with numbers only
     *
     * @throws InvalidCronExpressionException when the expression is outside the v1 dialect
     */
    public static function toNumeric(string $expression): string
    {
        $fields = explode(' ', $expression);
        if (count($fields) !== 5) {
            throw new InvalidCronExpressionException(sprintf('Cron expression "%s" must have exactly 5 fields.', $expression));
        }

        $numeric = [];
        foreach ($fields as $position => $field) {
            $items = [];
            foreach (explode(',', $field) as $item) {
                $items[] = self::item($position, $item, $field);
            }
            $numeric[] = match (true) {
                $position === 4 => self::dayList($items),
                $position <= 1 => self::stepList($items, self::FIELDS[$position][1]),
                default => implode(',', $items),
            };
        }

        // A day field that starts with `*` but isn't plain `*` (`*/2`, `*,5`): Laravel/crunz treat it as
        // restricted and OR it with the other day field, classic cron (cronie) treats it as `*` and ANDs.
        // Too easy to get a different schedule than you meant, so v1 refuses the mix when the other day
        // field is restricted, instead of picking one meaning silently.
        [$dom, $dow] = [$fields[2], $fields[4]];
        $starLike = static fn (string $f): bool => $f !== '*' && str_starts_with($f, '*');
        if (($starLike($dom) && $dow !== '*') || ($starLike($dow) && $dom !== '*')) {
            throw new InvalidCronExpressionException(sprintf(
                'Cron expression "%s": a step on `*` in day of month or day of week combined with a restricted other day field means different things in different cron implementations; write explicit values instead.',
                $expression,
            ));
        }

        return implode(' ', $numeric);
    }

    /**
     * Minute and hour: an item with a step becomes its explicit values. The parser's next-date
     * search wraps a step equal to the field maximum (`*\/59` ran next at :59 instead of :00,
     * `10-20/59` was "impossible") while its due check doesn't - plain lists avoid both paths.
     * Items without a step and plain `*` are left alone.
     *
     * @param list<string> $items numeric items of the field, already range-checked
     */
    private static function stepList(array $items, int $max = 59): string
    {
        if (!array_filter($items, static fn (string $item): bool => str_contains($item, '/'))) {
            return implode(',', $items);
        }

        $values = [];
        foreach ($items as $item) {
            if (!str_contains($item, '/')) {
                [$from, $to] = array_pad(explode('-', $item, 2), 2, $item);
                $step = '1';
            } else {
                [$range, $step] = explode('/', $item, 2);
                [$from, $to] = $range === '*' ? ['0', (string) $max] : array_pad(explode('-', $range, 2), 2, $range);
            }
            if ($from === '*') {
                [$from, $to] = ['0', (string) $max];
            }
            for ($value = (int) $from; $value <= (int) $to; $value += (int) $step) {
                $values[$value] = true;
            }
        }
        ksort($values);

        return implode(',', array_keys($values));
    }

    /**
     * Day of week goes to the parser as an explicit list with Sunday as 0 (`MON-FRI/2` -> `1,3,5`).
     * The parser rewrites ranges that touch 7 (`7-7`, `0-0` ended up as "every day") and rejects
     * some valid stepped ones (`0-6/7`), so it never gets a day range from us. `*` and `*\/n` stay
     * as they are - a list is still "restricted", so the day-of-month OR rule doesn't change.
     *
     * @param list<string> $items numeric items of the field
     */
    private static function dayList(array $items): string
    {
        if (count($items) === 1 && str_starts_with($items[0], '*')) {
            return $items[0];
        }

        $days = [];
        foreach ($items as $item) {
            [$range, $step] = array_pad(explode('/', $item, 2), 2, '1');
            // inside a list `*` means the whole week
            [$from, $to] = $range === '*' ? ['0', '6'] : array_pad(explode('-', $range, 2), 2, $range);
            for ($day = (int) $from; $day <= (int) $to; $day += (int) $step) {
                $days[$day % 7] = true;
            }
        }
        ksort($days);

        return implode(',', array_keys($days));
    }

    private static function item(int $position, string $item, string $field): string
    {
        [$name, $max, $literals] = self::FIELDS[$position];
        $value = $literals === [] ? '\d+' : '(?:\d+|[A-Za-z]{3})';

        // `*`, `*/n`, `v`, `v-v`, `v-v/n` - a step on a single value is not plain cron
        $matched = preg_match(
            '~\A(?:(?<star>\*)|(?<from>' . $value . ')(?:-(?<to>' . $value . '))?)(?:/(?<step>\d+))?\z~',
            $item,
            $m,
            PREG_UNMATCHED_AS_NULL,
        );
        $star = $m['star'] ?? null;
        $from = $m['from'] ?? null;
        $to = $m['to'] ?? null;
        $step = $m['step'] ?? null;

        if ($matched !== 1 || ($step !== null && $star === null && $to === null)) {
            throw self::error($name, $field, sprintf('"%s" is not supported (allowed: *, numbers%s, lists, ranges, steps)', $item, $literals === [] ? '' : ', names'));
        }

        if ($step !== null && (strlen($step) > 2 || (int) $step < 1 || (int) $step > $max)) {
            throw self::error($name, $field, sprintf('step %s must be between 1 and %d', $step, $max));
        }

        if ($star !== null || $from === null) {
            return $step === null ? '*' : '*/' . (int) $step;
        }

        $start = self::number($name, $field, $from, $literals);
        $out = (string) $start;
        if ($position === 4 && $start > 7) {
            throw self::error($name, $field, sprintf('day %d is out of range 0-7', $start));
        }

        $end = null;
        if ($to !== null) {
            $end = self::number($name, $field, $to, $literals);
            // SUN at the end of a day range means the 7 that closes the week, not the 0 that opens it
            if ($position === 4 && $end === 0 && strtoupper($to) === 'SUN' && $start > 0) {
                $end = 7;
            }
            if ($end < $start) {
                throw self::error($name, $field, sprintf('range "%s-%s" goes backwards', $from, $to));
            }
            if ($position === 4 && $end > 7) {
                throw self::error($name, $field, sprintf('day %d is out of range 0-7', $end));
            }
            $out .= '-' . $end;
        }

        // minute/hour items with a step are expanded into lists, which would hide an end beyond the
        // field (`10-70/59` -> `10`); the parser can't see that any more, so check it here
        if ($step !== null && $position <= 1 && max($start, $end ?? $start) > $max) {
            throw self::error($name, $field, sprintf('value %d is out of range 0-%d', max($start, $end ?? $start), $max));
        }

        return $step === null ? $out : $out . '/' . (int) $step;
    }

    /** @param array<string, int> $literals */
    private static function number(string $name, string $field, string $token, array $literals): int
    {
        if (ctype_digit($token)) {
            // keep it small enough for an int; the backend parser does the real range check
            return strlen($token) > 3 ? throw self::error($name, $field, sprintf('value %s is out of range', $token)) : (int) $token;
        }

        $upper = strtoupper($token);
        if (!isset($literals[$upper])) {
            throw self::error($name, $field, sprintf('unknown name "%s"', $token));
        }

        return $literals[$upper];
    }

    private static function error(string $name, string $field, string $reason): InvalidCronExpressionException
    {
        return new InvalidCronExpressionException(sprintf('cron field %s "%s": %s.', $name, $field, $reason));
    }
}
