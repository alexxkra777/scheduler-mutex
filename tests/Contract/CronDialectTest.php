<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Cron\CronDialect;

final class CronDialectTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function conversions(): iterable
    {
        yield 'plain numbers untouched' => ['30 6 1 1 0', '30 6 1 1 0'];
        yield 'wildcards stay wildcards' => ['* * * * *', '* * * * *'];
        // minute/hour steps become explicit lists, day-of-month/month steps stay as they are
        yield 'wildcard steps' => ['*/5 */2 */3 */4 *', '0,5,10,15,20,25,30,35,40,45,50,55 0,2,4,6,8,10,12,14,16,18,20,22 */3 */4 *'];
        yield 'minute step at the maximum' => ['*/59 * * * *', '0,59 * * * *'];
        yield 'minute range step longer than the range' => ['10-20/59 * * * *', '10 * * * *'];
        yield 'hour range step at the maximum' => ['0 1-2/23 * * *', '0 1 * * *'];
        yield 'hour star step at the maximum' => ['0 */23 * * *', '0 0,23 * * *'];
        yield 'minute list mixing steps and values' => ['0-30/10,45,50-52 * * * *', '0,10,20,30,45,50,51,52 * * * *'];
        yield 'minute ranges without a step stay' => ['5-10,30 9-17 * * *', '5-10,30 9-17 * * *'];
        yield 'day and month steps stay' => ['0 0 2-3/31 FEB-MAR/12 *', '0 0 2-3/31 2-3/12 *'];
        yield 'named day range with step' => ['0 0 * * MON-FRI/2', '0 0 * * 1,3,5'];
        yield 'lower case' => ['0 0 * * mon-fri/2', '0 0 * * 1,3,5'];
        yield 'named month range with step' => ['0 0 1 JAN-DEC/2 *', '0 0 1 1-12/2 *'];
        yield 'full week from Sunday' => ['0 0 * * SUN-SAT', '0 0 * * 0,1,2,3,4,5,6'];
        yield 'full week to Sunday' => ['0 0 * * MON-SUN', '0 0 * * 0,1,2,3,4,5,6'];
        yield 'range ending on Sunday' => ['0 0 * * FRI-SUN', '0 0 * * 0,5,6'];
        yield 'Sunday alone' => ['0 0 * * SUN', '0 0 * * 0'];
        yield 'Sunday to Sunday' => ['0 0 * * SUN-SUN', '0 0 * * 0'];
        yield 'numeric Sunday 7 becomes 0' => ['0 0 * * 7', '0 0 * * 0'];
        yield '7-7 is Sunday only' => ['0 0 * * 7-7', '0 0 * * 0'];
        yield 'step longer than day range' => ['0 0 * * SUN-SAT/7', '0 0 * * 0'];
        yield 'MON-SUN/2' => ['0 0 * * MON-SUN/2', '0 0 * * 0,1,3,5'];
        yield 'day star step stays' => ['0 0 * * */2', '0 0 * * */2'];
        yield 'star step inside a day list' => ['0 0 * * 1,*/3', '0 0 * * 0,1,3,6'];
        yield 'explicit full range is not folded into *' => ['0 0 13 * 0-6', '0 0 13 * 0,1,2,3,4,5,6'];
        yield 'lists with names' => ['0 0 1 JAN,jul * ', '0 0 1 1,7 *'];
        yield 'mixed name and number' => ['0 0 * * MON-5', '0 0 * * 1,2,3,4,5'];
        yield 'leading zero step' => ['0 0 */05 * *', '0 0 */5 * *'];
    }

    #[DataProvider('conversions')]
    public function testConvertsToNumericForm(string $expression, string $expected): void
    {
        self::assertSame($expected, CronDialect::toNumeric(trim($expression)));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function stepLimits(): iterable
    {
        $limits = [59, 23, 31, 12, 7];
        foreach ($limits as $position => $max) {
            foreach ([$max - 1 => true, $max => true, $max + 1 => false] as $step => $ok) {
                $fields = ['*', '*', '*', '*', '*'];
                $fields[$position] = "*/$step";
                yield "field $position */$step" => [implode(' ', $fields), $ok];

                $fields[$position] = $position === 2 || $position === 3 ? "1-2/$step" : "0-1/$step";
                yield "field $position range/$step" => [implode(' ', $fields), $ok];
            }
        }
        yield 'step 0' => ['*/0 * * * *', false];
        yield 'range step 0' => ['0-30/0 * * * *', false];
        yield 'huge step' => ['*/999 * * * *', false];
        yield 'minute range end beyond 59 hidden by a long step' => ['10-70/59 * * * *', false];
        yield 'hour range end beyond 23 hidden by a long step' => ['0 20-30/23 * * *', false];
    }

    #[DataProvider('stepLimits')]
    public function testStepIsLimitedByTheFieldMaximum(string $expression, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(InvalidCronExpressionException::class);
        }

        self::assertIsString(CronDialect::toNumeric($expression));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'reversed names' => ['0 0 * * FRI-MON'];
        yield 'reversed numbers' => ['0 0 * * 5-1'];
        yield 'reversed months' => ['0 0 1 DEC-JAN *'];
        yield 'numeric range ending on 0' => ['0 0 * * 1-0'];
        yield 'unknown name' => ['0 0 1 FOO *'];
        yield 'day name in month field' => ['0 0 1 MON *'];
        yield 'month name in weekday field' => ['0 0 * * JAN'];
        yield 'name in minute' => ['MON * * * *'];
        yield 'step on single value' => ['5/10 * * * *'];
        yield 'L' => ['0 0 L * *'];
        yield 'hash' => ['0 0 * * 5#2'];
        yield 'question mark' => ['0 0 ? * *'];
        yield 'empty list item' => ['1,,2 * * * *'];
        yield 'four fields' => ['* * * *'];
        yield 'day step with restricted day of month' => ['0 0 13 * */2'];
        yield 'steps in both day fields' => ['0 0 */3 * */2'];
        yield 'day-of-month step with restricted weekday' => ['0 0 */2 * MON'];
        yield 'star list in day of month with restricted weekday' => ['0 0 *,5 * MON'];
        yield 'day 8' => ['0 0 * * 8'];
        yield 'day range to 8' => ['0 0 * * 1-8'];
    }

    #[DataProvider('invalid')]
    public function testRejectsWithOwnException(string $expression): void
    {
        $this->expectException(InvalidCronExpressionException::class);
        CronDialect::toNumeric($expression);
    }
}
