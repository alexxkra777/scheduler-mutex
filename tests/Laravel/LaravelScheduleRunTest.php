<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Bootstrap\ScheduleLoader;
use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Contract\Exception\SchedulerIntegrationException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Infrastructure\Laravel\LaravelScheduler;
use SchedulerTest\Tests\Laravel\Fixtures\FixtureApp;
use SchedulerTest\Tests\Laravel\Fixtures\ListProvider;
use SchedulerTest\Tests\Laravel\Fixtures\NoopTask;
use SchedulerTest\Tests\Laravel\Fixtures\RecordingEventMutex;
use SchedulerTest\Tests\Laravel\Fixtures\RecordingSchedulingMutex;

/**
 * End-to-end through a real Laravel 12 app: provider -> loader -> adapter inside
 * withSchedule(), then the real `schedule:run`. Laravel alone decides what is due;
 * the tests only freeze the clock.
 */
final class LaravelScheduleRunTest extends TestCase
{
    /** @var list<string> ids the runner got, in call order */
    private array $ran = [];

    /** @var array<string, \Throwable> runner throws this for the given id */
    private array $failWith = [];

    /** @var list<FixtureApp> */
    private array $apps = [];

    protected function tearDown(): void
    {
        foreach ($this->apps as $app) {
            $app->close();
        }
        $this->apps = [];
    }

    public function testDueTaskRunsOnceAndNotDueTaskDoesNot(): void
    {
        $app = $this->appWith([
            $this->task('every-minute', '* * * * *'),
            $this->task('new-year-only', '0 0 1 JAN *'),
        ]);

        self::assertSame(0, $app->runScheduleAt('2026-10-03 12:34:00'));
        self::assertSame(['every-minute'], $this->ran);
        self::assertStringContainsString('Running [every-minute]', $app->output);
        self::assertSame([], $app->reported);
    }

    public function testNewYearTaskIsDueAtItsMinute(): void
    {
        $app = $this->appWith([$this->task('new-year-only', '0 0 1 JAN *')]);

        self::assertSame(0, $app->runScheduleAt('2027-01-01 00:00:00'));
        self::assertSame(['new-year-only'], $this->ran);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function pragueNineOClock(): iterable
    {
        yield 'summer 07:00 UTC = 09:00 CEST' => ['2026-07-15 07:00:00', true];
        yield 'summer 09:00 UTC = 11:00 CEST' => ['2026-07-15 09:00:00', false];
        yield 'winter 08:00 UTC = 09:00 CET' => ['2026-01-15 08:00:00', true];
        yield 'winter 07:00 UTC = 08:00 CET' => ['2026-01-15 07:00:00', false];
    }

    #[DataProvider('pragueNineOClock')]
    public function testTimezoneIsEvaluatedByLaravel(string $utcNow, bool $expectRun): void
    {
        $app = $this->appWith([$this->task('prague-nine', '0 9 * * *', 'Europe/Prague')]);

        self::assertSame(0, $app->runScheduleAt($utcNow));
        self::assertSame($expectRun ? ['prague-nine'] : [], $this->ran);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function weekdaysInJanuary(): iterable
    {
        yield 'Wed 2026-01-14' => ['2026-01-14 06:30:00', true];
        yield 'Sat 2026-01-17' => ['2026-01-17 06:30:00', false];
        yield 'Wed 2026-02-11' => ['2026-02-11 06:30:00', false];
    }

    #[DataProvider('weekdaysInJanuary')]
    public function testMonthAndDayNamesAreEvaluatedByLaravel(string $utcNow, bool $expectRun): void
    {
        $app = $this->appWith([$this->task('jan-workdays', '30 6 * JAN mon-fri')]);

        self::assertSame(0, $app->runScheduleAt($utcNow));
        self::assertSame($expectRun ? ['jan-workdays'] : [], $this->ran);
    }

    public function testRunnerExceptionReachesLaravelAndNextTaskStillRuns(): void
    {
        $boom = new \RuntimeException('task blew up');
        $this->failWith['broken'] = $boom;

        $app = $this->appWith([
            $this->task('broken', '* * * * *'),
            $this->task('healthy', '* * * * *'),
        ]);

        $exitCode = $app->runScheduleAt('2026-10-03 12:34:00');

        self::assertSame(0, $exitCode, 'schedule:run keeps going and exits 0 after a failed task');
        self::assertSame(['broken', 'healthy'], $this->ran);
        self::assertCount(1, $app->reported);
        self::assertSame($boom, $app->reported[0], 'the handler must get the runner\'s own exception, not a wrapper');
        self::assertCount(1, $app->failedEvents);
        self::assertSame($boom, $app->failedEvents[0]->exception);
        self::assertSame('broken', $app->failedEvents[0]->task->description);
        // Laravel 12.69.3 quirk: runEvent() hands a bool to the console Task component, which
        // matches ints strictly, so the failed run is still printed as DONE. Don't trust the output.
        self::assertMatchesRegularExpression('/Running \[broken\].*DONE/', $app->output);
    }

    public function testNativeMutexesAreNeverTouched(): void
    {
        $eventMutex = new RecordingEventMutex();
        $schedulingMutex = new RecordingSchedulingMutex();
        $this->failWith['broken'] = new \RuntimeException('task blew up');

        $app = $this->appWith([
            $this->task('ok', '* * * * *'),
            $this->task('broken', '* * * * *'),
            $this->task('not-due', '0 0 1 JAN *'),
        ]);
        $app->bindRecordingMutexes($eventMutex, $schedulingMutex);

        self::assertSame(0, $app->runScheduleAt('2026-10-03 12:34:00'));
        self::assertSame(['ok', 'broken'], $this->ran);
        self::assertNotNull($app->schedule);
        foreach ($app->schedule->events() as $event) {
            // make sure the fake really is the mutex Laravel would have used
            self::assertSame($eventMutex, $event->mutex);
        }
        self::assertSame([], $eventMutex->calls);
        self::assertSame([], $schedulingMutex->calls);
    }

    public function testInvalidCronInProviderFailsTheWholeRun(): void
    {
        $app = $this->appWith([
            $this->task('first-valid', '* * * * *'),
            $this->task('second-invalid', '61 * * * *'),
        ]);

        $exitCode = $app->runScheduleAt('2026-10-03 12:34:00');

        self::assertSame(1, $exitCode);
        self::assertSame([], $this->ran, 'nothing may run from a half-registered set');
        self::assertCount(1, $app->reported);
        self::assertInstanceOf(InvalidCronExpressionException::class, $app->reported[0]);
        self::assertStringContainsString('second-invalid', $app->reported[0]->getMessage());
        self::assertStringContainsString('second-invalid', $app->output);
        self::assertStringNotContainsString('Running [', $app->output);
        self::assertSame([], $app->failedEvents);
    }

    public function testHalfBuiltNativeEventIsNeverRun(): void
    {
        // A group with onOneServer() makes Laravel throw inside Schedule::call(),
        // after the event was already appended.
        $this->apps[] = $app = new FixtureApp(function (Schedule $schedule): void {
            $schedule->onOneServer()->group(function (Schedule $schedule): void {
                (new ScheduleLoader())->registerAll(
                    new ListProvider([$this->task('grouped', '* * * * *')]),
                    new LaravelScheduler($schedule, $this->runner()),
                );
            });
        });

        $exitCode = $app->runScheduleAt('2026-10-03 12:34:00');

        self::assertSame(1, $exitCode);
        self::assertSame([], $this->ran);
        self::assertNotNull($app->schedule);
        self::assertCount(1, $app->schedule->events(), 'the half-built event is still in the native schedule');
        self::assertCount(1, $app->reported);
        self::assertInstanceOf(SchedulerIntegrationException::class, $app->reported[0]);
        self::assertInstanceOf(\LogicException::class, $app->reported[0]->getPrevious());
    }

    public function testDueTaskDoesNotRunInMaintenanceMode(): void
    {
        $app = $this->appWith([$this->task('every-minute', '* * * * *')]);
        // what FileBasedMaintenanceMode::activate() (`php artisan down`) writes; the driver
        // itself is only bound once providers are registered, i.e. inside the command
        file_put_contents($app->app->storagePath('framework/down'), json_encode(['time' => 0]));

        self::assertSame(0, $app->runScheduleAt('2026-10-03 12:34:00'));
        self::assertTrue($app->app->isDownForMaintenance(), 'the configured file driver sees the app as down');
        self::assertSame([], $this->ran);
        self::assertStringContainsString('No scheduled commands are ready to run.', $app->output);
    }

    /** @param list<ScheduledTask> $tasks */
    private function appWith(array $tasks): FixtureApp
    {
        // same shape a real bootstrap/app.php would have
        $this->apps[] = $app = new FixtureApp(function (Schedule $schedule) use ($tasks): void {
            (new ScheduleLoader())->registerAll(new ListProvider($tasks), new LaravelScheduler($schedule, $this->runner()));
        });

        return $app;
    }

    /** @return \Closure(ScheduledTask): void */
    private function runner(): \Closure
    {
        return function (ScheduledTask $task): void {
            $this->ran[] = $task->id;
            if (isset($this->failWith[$task->id])) {
                throw $this->failWith[$task->id];
            }
        };
    }

    private function task(string $id, string $cron, string $timezone = 'UTC'): ScheduledTask
    {
        return new ScheduledTask($id, new NoopTask(), $cron, new \DateTimeZone($timezone));
    }
}
