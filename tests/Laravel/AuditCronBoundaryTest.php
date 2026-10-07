<?php
declare(strict_types=1);
namespace SchedulerTest\Tests\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Infrastructure\Laravel\LaravelScheduler;
use SchedulerTest\Tests\Laravel\Fixtures\FixtureApp;
use SchedulerTest\Tests\Laravel\Fixtures\NoopTask;

final class AuditCronBoundaryTest extends TestCase
{
    public static function cases(): array
    {
        return [
            'minute boundary 59 selects range start 10' => ['10-20/59 * * * *', '2026-03-02 12:10:00'],
            'hour boundary 23 selects range start 1' => ['0 1-2/23 * * *', '2026-03-02 01:00:00'],
            'minute below boundary 58 control' => ['10-20/58 * * * *', '2026-03-02 12:10:00'],
            'hour below boundary 22 control' => ['0 1-2/22 * * *', '2026-03-02 01:00:00'],
            'day boundary 31 selects range start 2' => ['0 0 2-3/31 * *', '2026-03-02 00:00:00'],
            'month boundary 12 selects February' => ['0 0 1 FEB-MAR/12 *', '2026-02-01 00:00:00'],
            'minute star 59 does run at zero control' => ['*/59 * * * *', '2026-03-03 00:00:00'],
            'minute star 59 does run at fifty-nine control' => ['*/59 * * * *', '2026-03-03 00:59:00'],
        ];
    }

    #[DataProvider('cases')]
    public function testValidRangeWithStepIsRegisteredAndExecuted(string $cron, string $now): void
    {
        $ran = false;
        $task = new ScheduledTask('boundary', new NoopTask(), $cron);
        $app = new FixtureApp(static function (Schedule $schedule) use ($task, &$ran): void {
            (new LaravelScheduler($schedule, static function () use (&$ran): void { $ran = true; }))->register($task);
        });
        try {
            $code = $app->runScheduleAt($now);
            self::assertSame(0, $code, $app->output);
            self::assertTrue($ran, $app->output);
        } finally {
            $app->close();
        }
    }

    public function testMinuteMaximumStepHasCorrectNextOccurrence(): void
    {
        $numeric = \SchedulerTest\Cron\CronDialect::toNumeric('*/59 * * * *');
        $parser = new \Cron\CronExpression($numeric);
        self::assertSame('2026-03-03 00:00', $parser->getNextRunDate('2026-03-02 23:59:00', 0, false, 'UTC')->format('Y-m-d H:i'));
    }

    public function testScheduleListShowsMinuteMaximumStepNextOccurrence(): void
    {
        $task = new ScheduledTask('boundary-list', new NoopTask(), '*/59 * * * *');
        $app = new FixtureApp(static function (Schedule $schedule) use ($task): void {
            (new LaravelScheduler($schedule, static function (): void {}))->register($task);
        });
        try {
            \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-03-02 23:59:00','UTC'));
            $out = new \Symfony\Component\Console\Output\BufferedOutput();
            $code = $app->app->make(\Illuminate\Contracts\Console\Kernel::class)->handle(
                new \Symfony\Component\Console\Input\ArrayInput(['command'=>'schedule:list','--json'=>true]), $out);
            $json = $out->fetch();
            self::assertSame(0,$code,$json);
            $list = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('2026-03-03 00:00:00 +00:00', $list[0]['next_due_date']);
        } finally {
            $app->close();
        }
    }
}
