<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Bootstrap\ScheduleLoader;
use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Infrastructure\Laravel\LaravelScheduler;
use SchedulerTest\Tests\Laravel\Fixtures\FixtureApp;
use SchedulerTest\Tests\Laravel\Fixtures\ListProvider;
use SchedulerTest\Tests\Laravel\Fixtures\NoopTask;

/**
 * The whole v1 cron dialect checked where it matters: does the real Laravel
 * `schedule:run` call the task at a given minute or not.
 *
 * March 2026: Sun 1, Mon 2, Tue 3, Wed 4, Thu 5, Fri 6, Sat 7, ... Fri 13.
 */
final class CronDialectRunTest extends TestCase
{
    /** @var list<FixtureApp> */
    private array $apps = [];

    private bool $ran = false;

    protected function tearDown(): void
    {
        foreach ($this->apps as $app) {
            $app->close();
        }
        $this->apps = [];
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function dueCases(): iterable
    {
        // named day range with a step: Mon, Wed, Fri
        foreach (['MON-FRI/2', 'mon-fri/2'] as $dow) {
            yield "$dow Sun 1" => ["0 0 * * $dow", '2026-03-01 00:00', false];
            yield "$dow Mon 2" => ["0 0 * * $dow", '2026-03-02 00:00', true];
            yield "$dow Tue 3" => ["0 0 * * $dow", '2026-03-03 00:00', false];
            yield "$dow Wed 4" => ["0 0 * * $dow", '2026-03-04 00:00', true];
            yield "$dow Thu 5" => ["0 0 * * $dow", '2026-03-05 00:00', false];
            yield "$dow Fri 6" => ["0 0 * * $dow", '2026-03-06 00:00', true];
            yield "$dow Sat 7" => ["0 0 * * $dow", '2026-03-07 00:00', false];
        }

        // named month range with a step: odd months
        foreach (range(1, 12) as $month) {
            $date = sprintf('2026-%02d-01 00:00', $month);
            yield "JAN-DEC/2 month $month" => ['0 0 1 JAN-DEC/2 *', $date, $month % 2 === 1];
        }

        // full named week, both ways of writing Sunday
        foreach (range(1, 7) as $day) {
            $date = sprintf('2026-03-%02d 00:00', $day);
            yield "SUN-SAT day $day" => ['0 0 * * SUN-SAT', $date, true];
            yield "MON-SUN day $day" => ['0 0 * * MON-SUN', $date, true];
            yield "sun-sat lower day $day" => ['0 0 * * sun-sat', $date, true];
        }

        // Sunday as 0 and as 7, by number and by name
        foreach (['0', '7', 'SUN', 'sun'] as $sunday) {
            yield "Sunday '$sunday' on Sun" => ["0 0 * * $sunday", '2026-03-01 00:00', true];
            yield "Sunday '$sunday' on Mon" => ["0 0 * * $sunday", '2026-03-02 00:00', false];
        }
        yield 'FRI-SUN on Sun' => ['0 0 * * FRI-SUN', '2026-03-01 00:00', true];
        yield 'FRI-SUN on Thu' => ['0 0 * * FRI-SUN', '2026-03-05 00:00', false];
        yield 'list with names' => ['0 0 * * SUN,wed', '2026-03-04 00:00', true];
        yield 'list with names, other day' => ['0 0 * * SUN,wed', '2026-03-03 00:00', false];
        yield 'single month name' => ['0 0 1 MAR *', '2026-03-01 00:00', true];
        yield 'single month name, other month' => ['0 0 1 MAR *', '2026-04-01 00:00', false];

        // day-of-month OR day-of-week once both are restricted - a full explicit week is still "restricted"
        yield '13 + SUN-SAT on Mon 2' => ['0 0 13 * SUN-SAT', '2026-03-02 00:00', true];
        yield '13 + MON-SUN on Tue 3' => ['0 0 13 * MON-SUN', '2026-03-03 00:00', true];
        yield '13 + 0-6 on Mon 2' => ['0 0 13 * 0-6', '2026-03-02 00:00', true];
        yield '13 + * on 13th' => ['0 0 13 * *', '2026-03-13 00:00', true];
        yield '13 + * on Mon 2' => ['0 0 13 * *', '2026-03-02 00:00', false];
        yield '13 + MON on Mon 2' => ['0 0 13 * MON', '2026-03-02 00:00', true];
        yield '13 + MON on Fri 13' => ['0 0 13 * MON', '2026-03-13 00:00', true];
        yield '13 + MON on Tue 3' => ['0 0 13 * MON', '2026-03-03 00:00', false];

        // degenerate Sunday ranges: the parser turned 0-0 / 7-7 into "every day"
        foreach (['SUN-SUN', '0-0', '7-7', '0-SUN', '7-SUN', '1,SUN-SUN', '7-7/2'] as $dow) {
            yield "$dow on Sun" => ["0 0 * * $dow", '2026-03-01 00:00', true];
            yield "$dow on Wed" => ["0 0 * * $dow", '2026-03-04 00:00', false];
        }
        // a step longer than a day range picks the range start
        yield 'SUN-SAT/7 on Sun' => ['0 0 * * SUN-SAT/7', '2026-03-01 00:00', true];
        yield 'SUN-SAT/7 on Mon' => ['0 0 * * SUN-SAT/7', '2026-03-02 00:00', false];
        yield 'SUN-WED/7 on Sun' => ['0 0 * * SUN-WED/7', '2026-03-01 00:00', true];
        yield 'MON-SUN/2 on Sun' => ['0 0 * * MON-SUN/2', '2026-03-01 00:00', true];
        yield 'MON-SUN/2 on Tue' => ['0 0 * * MON-SUN/2', '2026-03-03 00:00', false];
        yield 'MON-SUN/2 on Wed' => ['0 0 * * MON-SUN/2', '2026-03-04 00:00', true];

        // steps: limit is the position's max value, a step longer than the range picks its start
        yield '*/5 at :05' => ['*/5 * * * *', '2026-03-02 12:05', true];
        yield '*/5 at :06' => ['*/5 * * * *', '2026-03-02 12:06', false];
        yield '0-30/10 at :30' => ['0-30/10 * * * *', '2026-03-02 12:30', true];
        yield '0-30/10 at :35' => ['0-30/10 * * * *', '2026-03-02 12:35', false];
        yield '*/58 at :58' => ['*/58 * * * *', '2026-03-02 12:58', true];
        yield '*/59 at :00' => ['*/59 * * * *', '2026-03-02 12:00', true];
        yield '*/59 at :59' => ['*/59 * * * *', '2026-03-02 12:59', true];
        yield '*/59 at :01' => ['*/59 * * * *', '2026-03-02 12:01', false];
        yield '10-20/30 at :10' => ['10-20/30 * * * *', '2026-03-02 12:10', true];
        yield '10-20/30 at :20' => ['10-20/30 * * * *', '2026-03-02 12:20', false];
        yield 'hour */23 at 23:00' => ['0 */23 * * *', '2026-03-02 23:00', true];
        yield 'hour */23 at 01:00' => ['0 */23 * * *', '2026-03-02 01:00', false];
        yield 'dom */31 on 1st' => ['0 0 */31 * *', '2026-03-01 00:00', true];
        yield 'dom */31 on 31st' => ['0 0 */31 * *', '2026-03-31 00:00', false];
        yield 'month */12 in Jan' => ['0 0 1 */12 *', '2026-01-01 00:00', true];
        yield 'month */12 in Mar' => ['0 0 1 */12 *', '2026-03-01 00:00', false];
        yield 'dow */7 on Sun' => ['0 0 * * */7', '2026-03-01 00:00', true];
        yield 'dow */7 on Mon' => ['0 0 * * */7', '2026-03-02 00:00', false];
    }

    #[DataProvider('dueCases')]
    public function testLaravelRunsTheTaskExactlyWhenTheExpressionSays(string $cron, string $utcNow, bool $expectRun): void
    {
        $app = $this->appWith(new ScheduledTask('dialect', new NoopTask(), $cron));

        self::assertSame(0, $app->runScheduleAt($utcNow . ':00'));
        self::assertSame($expectRun, $this->ran, sprintf('"%s" at %s', $cron, $utcNow));
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'minute step 60' => ['*/60 * * * *'];
        yield 'minute step 61' => ['*/61 * * * *'];
        yield 'minute range step 60' => ['0-30/60 * * * *'];
        yield 'hour step 24' => ['0 */24 * * *'];
        yield 'dom step 32' => ['0 0 */32 * *'];
        yield 'month step 13' => ['0 0 1 */13 *'];
        yield 'month named step 13' => ['0 0 1 JAN-DEC/13 *'];
        yield 'dow step 8' => ['0 0 * * */8'];
        yield 'dow named step 8' => ['0 0 * * MON-FRI/8'];
        yield 'step 0' => ['*/0 * * * *'];
        yield 'range step 0' => ['0-30/0 * * * *'];
        yield 'reversed named range' => ['0 0 * * FRI-MON'];
        yield 'reversed numeric range' => ['0 0 * * 5-1'];
        yield 'reversed month range' => ['0 0 1 DEC-JAN *'];
        yield 'minute 61' => ['61 * * * *'];
        yield 'day of week 8' => ['0 0 * * 8'];
        yield 'Feb 31' => ['0 0 31 2 *'];
        yield 'L extension' => ['0 0 L * *'];
        // */n in one day field with the other day field restricted: Laravel ORs them, classic cron ANDs
        yield 'dow step with restricted dom' => ['0 0 13 * */2'];
        yield 'dom step with restricted dow' => ['0 0 */2 * MON'];
        yield 'day of week 8 in a range' => ['0 0 * * 1-8'];
    }

    #[DataProvider('rejected')]
    public function testRejectedBeforeAnythingRuns(string $cron): void
    {
        try {
            $app = $this->appWith(new ScheduledTask('dialect', new NoopTask(), $cron));
            $exitCode = $app->runScheduleAt('2026-03-01 00:00:00');
        } catch (InvalidCronExpressionException) {
            // rejected while building the task definition - fine, nothing could run
            self::assertFalse($this->ran);

            return;
        }

        // otherwise registration inside withSchedule() must have failed the whole command
        self::assertFalse($this->ran, sprintf('"%s" was executed', $cron));
        self::assertNotSame(0, $exitCode);
        self::assertStringContainsString('dialect', $app->output);
    }

    private function appWith(ScheduledTask $task): FixtureApp
    {
        $this->apps[] = $app = new FixtureApp(function (Schedule $schedule) use ($task): void {
            (new ScheduleLoader())->registerAll(
                new ListProvider([$task]),
                new LaravelScheduler($schedule, function (): void {
                    $this->ran = true;
                }),
            );
        });

        return $app;
    }
}
