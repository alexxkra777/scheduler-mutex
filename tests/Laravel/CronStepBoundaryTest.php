<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Infrastructure\Laravel\LaravelScheduler;
use SchedulerTest\Tests\Laravel\Fixtures\FixtureApp;
use SchedulerTest\Tests\Laravel\Fixtures\NoopTask;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Minute and hour steps around the field maximum, checked twice through real Laravel:
 * `schedule:run` for "is it due now" and `schedule:list --json` for "when is the next run".
 * The parser used to disagree with itself at step == max (59 / 23). Expected values are
 * written out by hand, not computed.
 */
final class CronStepBoundaryTest extends TestCase
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
        yield 'step 1' => ['*/1 * * * *', '12:37', true];
        yield '*/58 at :00' => ['*/58 * * * *', '12:00', true];
        yield '*/58 at :58' => ['*/58 * * * *', '12:58', true];
        yield '*/58 at :59' => ['*/58 * * * *', '12:59', false];
        yield '0-59/59 at :00' => ['0-59/59 * * * *', '12:00', true];
        yield '0-59/59 at :59' => ['0-59/59 * * * *', '12:59', true];
        yield '0-59/59 at :30' => ['0-59/59 * * * *', '12:30', false];
        // non-zero start, step longer than / equal to / shorter than the range
        yield '10-20/59 at :10' => ['10-20/59 * * * *', '12:10', true];
        yield '10-20/59 at :20' => ['10-20/59 * * * *', '12:20', false];
        yield '10-20/59 at :59' => ['10-20/59 * * * *', '12:59', false];
        yield '10-20/11 at :10' => ['10-20/11 * * * *', '12:10', true];
        yield '10-20/11 at :20' => ['10-20/11 * * * *', '12:20', false];
        yield '10-20/10 at :10' => ['10-20/10 * * * *', '12:10', true];
        yield '10-20/10 at :20' => ['10-20/10 * * * *', '12:20', true];
        yield '10-20/10 at :15' => ['10-20/10 * * * *', '12:15', false];
        yield '10-20/3 at :19' => ['10-20/3 * * * *', '12:19', true];
        yield '10-20/3 at :20' => ['10-20/3 * * * *', '12:20', false];
        yield 'list with a max step at :05' => ['5,10-20/59,30 * * * *', '12:05', true];
        yield 'list with a max step at :10' => ['5,10-20/59,30 * * * *', '12:10', true];
        yield 'list with a max step at :20' => ['5,10-20/59,30 * * * *', '12:20', false];
        yield 'list with a max step at :30' => ['5,10-20/59,30 * * * *', '12:30', true];
        // hours
        yield 'hour */23 at 00:00' => ['0 */23 * * *', '00:00', true];
        yield 'hour */23 at 23:00' => ['0 */23 * * *', '23:00', true];
        yield 'hour */23 at 01:00' => ['0 */23 * * *', '01:00', false];
        yield 'hour */22 at 22:00' => ['0 */22 * * *', '22:00', true];
        yield 'hour */22 at 23:00' => ['0 */22 * * *', '23:00', false];
        yield 'hour 1-2/23 at 01:00' => ['0 1-2/23 * * *', '01:00', true];
        yield 'hour 1-2/23 at 02:00' => ['0 1-2/23 * * *', '02:00', false];
        yield 'hour 1-2/23 at 23:00' => ['0 1-2/23 * * *', '23:00', false];
        yield 'hour 5-10/5 at 10:00' => ['0 5-10/5 * * *', '10:00', true];
        yield 'hour 5-10/5 at 06:00' => ['0 5-10/5 * * *', '06:00', false];
    }

    #[DataProvider('dueCases')]
    public function testLaravelRunsTheTaskExactlyAtTheExpectedMinute(string $cron, string $time, bool $expectRun): void
    {
        $app = $this->appWith($cron);

        self::assertSame(0, $app->runScheduleAt("2026-03-02 $time:00"), $app->output);
        self::assertSame($expectRun, $this->ran, sprintf('"%s" at %s', $cron, $time));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function nextRunCases(): iterable
    {
        yield '*/59 over midnight' => ['*/59 * * * *', '2026-03-02 23:59', '2026-03-03 00:00'];
        yield '*/59 within the hour' => ['*/59 * * * *', '2026-03-02 12:00', '2026-03-02 12:59'];
        yield '*/59 from the middle' => ['*/59 * * * *', '2026-03-02 12:30', '2026-03-02 12:59'];
        yield '*/58 into the next hour' => ['*/58 * * * *', '2026-03-02 12:58', '2026-03-02 13:00'];
        yield '0-59/59 into the next hour' => ['0-59/59 * * * *', '2026-03-02 12:59', '2026-03-02 13:00'];
        yield '10-20/59 next hour' => ['10-20/59 * * * *', '2026-03-02 12:10', '2026-03-02 13:10'];
        yield '10-20/59 over midnight' => ['10-20/59 * * * *', '2026-03-02 23:15', '2026-03-03 00:10'];
        yield 'hour */23 over midnight' => ['0 */23 * * *', '2026-03-02 23:00', '2026-03-03 00:00'];
        yield 'hour */23 later the same day' => ['0 */23 * * *', '2026-03-02 00:00', '2026-03-02 23:00'];
        yield 'hour 1-2/23 next day' => ['0 1-2/23 * * *', '2026-03-02 01:00', '2026-03-03 01:00'];
        yield 'hour 1-2/23 over the month end' => ['0 1-2/23 * * *', '2026-03-31 05:00', '2026-04-01 01:00'];
    }

    #[DataProvider('nextRunCases')]
    public function testScheduleListShowsTheExpectedNextRun(string $cron, string $now, string $expectedNext): void
    {
        $app = $this->appWith($cron);

        Carbon::setTestNow(Carbon::parse("$now:00", 'UTC'));
        $output = new BufferedOutput();
        $exitCode = $app->app->make(Kernel::class)->handle(new ArrayInput(['command' => 'schedule:list', '--json' => true]), $output);
        $json = $output->fetch();

        self::assertSame(0, $exitCode, $json);
        $list = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($list);
        self::assertSame("$expectedNext:00 +00:00", $list[0]['next_due_date'] ?? null, sprintf('"%s" after %s', $cron, $now));
    }

    private function appWith(string $cron): FixtureApp
    {
        $task = new ScheduledTask('step-boundary', new NoopTask(), $cron);
        $this->apps[] = $app = new FixtureApp(function (Schedule $schedule) use ($task): void {
            (new LaravelScheduler($schedule, function (): void {
                $this->ran = true;
            }))->register($task);
        });

        return $app;
    }
}
