<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Laravel;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\Exception\DuplicateTaskIdException;
use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Contract\Exception\SchedulerIntegrationException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\SchedulerInterface;
use SchedulerTest\Infrastructure\Laravel\LaravelScheduler;
use SchedulerTest\Tests\Laravel\Fixtures\NoopTask;
use SchedulerTest\Tests\Laravel\Fixtures\RecordingEventMutex;
use SchedulerTest\Tests\Laravel\Fixtures\RecordingSchedulingMutex;

/**
 * register() on its own, against a native Schedule; nothing is run here.
 */
final class LaravelSchedulerRegistrationTest extends TestCase
{
    private Schedule $schedule;

    /** @var list<string> */
    private array $ran = [];

    protected function setUp(): void
    {
        // Schedule pulls its mutexes from the global container; fakes avoid a cache setup
        $container = new Container();
        $container->instance(EventMutex::class, new RecordingEventMutex());
        $container->instance(SchedulingMutex::class, new RecordingSchedulingMutex());
        Container::setInstance($container);

        $this->schedule = new Schedule('UTC');
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function testRegisterBuildsPlainCallbackEvent(): void
    {
        $task = new ScheduledTask('report.daily', new NoopTask(), '0 9 * * mon-fri', new \DateTimeZone('Europe/Prague'));

        $this->adapter()->register($task);

        $events = $this->schedule->events();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(CallbackEvent::class, $event);
        // Laravel gets the numeric form; the parser mishandles names in stepped ranges
        self::assertSame('0 9 * * 1,2,3,4,5', $event->expression);
        self::assertSame('Europe/Prague', $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : $event->timezone);
        self::assertSame('report.daily', $event->description);
        self::assertSame('report.daily', $event->getSummaryForDisplay());
        self::assertFalse($event->withoutOverlapping);
        self::assertFalse($event->onOneServer);
        self::assertFalse($event->runInBackground);
        self::assertFalse($event->evenInMaintenanceMode);
        self::assertSame([], $event->environments);
        self::assertSame([], $this->ran, 'register must not run anything');
    }

    public function testRegisteredCallbackPassesTheTaskAndReturnsNothing(): void
    {
        $task = new ScheduledTask('one', new NoopTask(), '* * * * *');
        $this->adapter()->register($task);

        $callback = (fn () => $this->callback)->call($this->schedule->events()[0]);

        self::assertInstanceOf(\Closure::class, $callback);
        self::assertNull($callback(), 'false would be a failed run for Laravel');
        self::assertSame(['one'], $this->ran);
    }

    /** @return iterable<string, array{string}> */
    public static function badCron(): iterable
    {
        yield 'minute 61' => ['61 * * * *'];
        // step 0 and other dialect errors are refused earlier, by ScheduledTask (see CronDialectTest)
        yield 'hour 24' => ['0 24 * * *'];
        yield 'month 13' => ['0 0 1 13 *'];
        yield 'never matches (Feb 31)' => ['0 0 31 2 *'];
    }

    #[DataProvider('badCron')]
    public function testParserRejectionCreatesNoEvent(string $cron): void
    {
        $task = new ScheduledTask('bad-cron', new NoopTask(), $cron);

        try {
            $this->adapter()->register($task);
            self::fail('InvalidCronExpressionException expected');
        } catch (InvalidCronExpressionException $e) {
            self::assertStringContainsString('bad-cron', $e->getMessage());
            // the parser throws InvalidArgumentException for bad values, RuntimeException for impossible dates
            self::assertNotNull($e->getPrevious());
            self::assertNotInstanceOf(InvalidCronExpressionException::class, $e->getPrevious());
        }

        self::assertCount(0, $this->schedule->events());
    }

    public function testParserIsTheOneLaravelUses(): void
    {
        // guard against the adapter and Laravel drifting apart on what "valid" means
        $this->expectException(\InvalidArgumentException::class);
        new CronExpression('61 * * * *');
    }

    public function testInvalidCronDoesNotTakeTheId(): void
    {
        $adapter = $this->adapter();

        try {
            $adapter->register(new ScheduledTask('retry-me', new NoopTask(), '61 * * * *'));
        } catch (InvalidCronExpressionException) {
        }
        $adapter->register(new ScheduledTask('retry-me', new NoopTask(), '1 * * * *'));

        self::assertCount(1, $this->schedule->events());
    }

    public function testDuplicateIdIsRejectedBeforeLaravel(): void
    {
        $adapter = $this->adapter();
        $adapter->register(new ScheduledTask('dup', new NoopTask(), '* * * * *'));

        try {
            $adapter->register(new ScheduledTask('dup', new NoopTask(), '0 0 * * *'));
            self::fail('DuplicateTaskIdException expected');
        } catch (DuplicateTaskIdException $e) {
            self::assertStringContainsString('dup', $e->getMessage());
        }

        self::assertCount(1, $this->schedule->events());
        self::assertSame('* * * * *', $this->schedule->events()[0]->expression, 'no silent replacement');
    }

    public function testDuplicateWinsOverInvalidCron(): void
    {
        $adapter = $this->adapter();
        $adapter->register(new ScheduledTask('dup', new NoopTask(), '* * * * *'));

        $this->expectException(DuplicateTaskIdException::class);
        $adapter->register(new ScheduledTask('dup', new NoopTask(), '61 * * * *'));
    }

    /** @return iterable<string, array{string}> */
    public static function groupAttributes(): iterable
    {
        // Laravel itself throws: onOneServer() needs a name, which we set after call()
        yield 'onOneServer group' => ['onOneServer'];
        // Laravel accepts it, the adapter's own guard refuses
        yield 'evenInMaintenanceMode group' => ['evenInMaintenanceMode'];
    }

    #[DataProvider('groupAttributes')]
    public function testNativeFailureAfterCallIsWrappedAndReported(string $groupAttribute): void
    {
        $adapter = $this->adapter();
        $task = new ScheduledTask('grouped', new NoopTask(), '* * * * *');
        $caught = null;

        $this->schedule->{$groupAttribute}()->group(function () use ($adapter, $task, &$caught): void {
            try {
                $adapter->register($task);
            } catch (SchedulerIntegrationException $e) {
                $caught = $e;
            }
        });

        self::assertInstanceOf(SchedulerIntegrationException::class, $caught);
        self::assertInstanceOf(\LogicException::class, $caught->getPrevious());
        self::assertStringContainsString('grouped', $caught->getMessage());
        self::assertStringContainsString('half-built event', $caught->getMessage());
        self::assertStringNotContainsString('..', $caught->getMessage());
        // Schedule has no remove API: the broken event stays, the bootstrap must fail as a whole
        self::assertCount(1, $this->schedule->events());

        // and the id stays taken, a retry would just add a second event
        $this->expectException(DuplicateTaskIdException::class);
        $adapter->register($task);
    }

    /** @return iterable<string, array{\Closure(Schedule): \Illuminate\Console\Scheduling\PendingEventAttributes}> */
    public static function groupFilters(): iterable
    {
        yield 'when() group' => [fn (Schedule $s) => $s->when(fn () => false)];
        yield 'skip() group' => [fn (Schedule $s) => $s->skip(fn () => true)];
    }

    /** @param \Closure(Schedule): \Illuminate\Console\Scheduling\PendingEventAttributes $pending */
    #[DataProvider('groupFilters')]
    public function testGroupFiltersAreRefused(\Closure $pending): void
    {
        $adapter = $this->adapter();
        $caught = null;

        $pending($this->schedule)->group(function () use ($adapter, &$caught): void {
            try {
                $adapter->register(new ScheduledTask('filtered', new NoopTask(), '* * * * *'));
            } catch (SchedulerIntegrationException $e) {
                $caught = $e;
            }
        });

        // without the guard the task would silently never run and never show up in the journal
        self::assertInstanceOf(SchedulerIntegrationException::class, $caught);
        self::assertStringContainsString('filtered', $caught->getMessage());
    }

    public function testPublicApiLeaksNoLaravelTypes(): void
    {
        $class = new \ReflectionClass(LaravelScheduler::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->implementsInterface(SchedulerInterface::class));
        self::assertSame([], $class->getProperties(\ReflectionProperty::IS_PUBLIC));

        $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), $class->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);
        self::assertSame(['__construct', 'register'], $methods);

        $register = $class->getMethod('register');
        self::assertSame('void', (string) $register->getReturnType());
        self::assertCount(1, $register->getParameters());
        self::assertSame(ScheduledTask::class, (string) $register->getParameters()[0]->getType());

        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        $types = array_map(fn (\ReflectionParameter $p) => (string) $p->getType(), $constructor->getParameters());
        self::assertSame([Schedule::class, \Closure::class], $types, 'Laravel type only in the constructor');
    }

    private function adapter(): LaravelScheduler
    {
        return new LaravelScheduler($this->schedule, function (ScheduledTask $task): void {
            $this->ran[] = $task->id;
        });
    }
}
