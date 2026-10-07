<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Contract;

use PHPUnit\Framework\TestCase;
use SchedulerTest\Bootstrap\ScheduleLoader;
use SchedulerTest\Contract\Exception\DuplicateTaskIdException;
use SchedulerTest\Contract\Exception\ScheduleConfigurationException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\ScheduleProviderInterface;
use SchedulerTest\Contract\SchedulerInterface;
use SchedulerTest\Contract\TaskInterface;

final class ScheduleLoaderTest extends TestCase
{
    public function testRegistersTheWholeSetInOrder(): void
    {
        $scheduler = new RecordingScheduler();
        (new ScheduleLoader())->registerAll(self::provider(fn () => [self::task('a'), self::task('b')]), $scheduler);

        self::assertSame(['a', 'b'], $scheduler->ids);
    }

    public function testGeneratorProviderIsReadOnce(): void
    {
        $calls = 0;
        $provider = self::provider(function () use (&$calls) {
            $calls++;
            yield self::task('a');
            yield self::task('b');
        });

        self::assertCount(2, (new ScheduleLoader())->load($provider));
        self::assertSame(1, $calls);
    }

    public function testDuplicateStopsBeforeAnyRegistration(): void
    {
        $scheduler = new RecordingScheduler();

        try {
            (new ScheduleLoader())->registerAll(
                self::provider(fn () => [self::task('a'), self::task('b'), self::task('a')]),
                $scheduler,
            );
            self::fail('Expected DuplicateTaskIdException');
        } catch (DuplicateTaskIdException $e) {
            self::assertStringContainsString('"a"', $e->getMessage());
        }

        self::assertSame([], $scheduler->ids);
    }

    public function testWrongElementTypeIsConfigurationError(): void
    {
        $scheduler = new RecordingScheduler();

        try {
            (new ScheduleLoader())->registerAll(self::provider(fn () => [self::task('a'), 'not a task']), $scheduler);
            self::fail('Expected ScheduleConfigurationException');
        } catch (ScheduleConfigurationException $e) {
            self::assertStringContainsString('string', $e->getMessage());
        }

        self::assertSame([], $scheduler->ids);
    }

    public function testProviderFailureKeepsTheCause(): void
    {
        $cause = new \LogicException('db config missing');

        try {
            (new ScheduleLoader())->load(self::provider(function () use ($cause) {
                yield self::task('a');
                throw $cause;
            }));
            self::fail('Expected ScheduleConfigurationException');
        } catch (ScheduleConfigurationException $e) {
            self::assertSame($cause, $e->getPrevious());
        }
    }

    public function testInvalidDefinitionInsideProviderIsConfigurationError(): void
    {
        $this->expectException(ScheduleConfigurationException::class);
        (new ScheduleLoader())->load(self::provider(fn () => [self::task('Bad Id')]));
    }

    public function testRegisterErrorsEscapeUnchanged(): void
    {
        $scheduler = new RecordingScheduler(failOn: 'b');

        try {
            (new ScheduleLoader())->registerAll(self::provider(fn () => [self::task('a'), self::task('b'), self::task('c')]), $scheduler);
            self::fail('Expected exception');
        } catch (\DomainException $e) {
            self::assertSame('backend refused b', $e->getMessage());
        }

        self::assertSame(['a'], $scheduler->ids);
    }

    /** @param \Closure(): iterable<mixed> $tasks */
    private static function provider(\Closure $tasks): ScheduleProviderInterface
    {
        return new class ($tasks) implements ScheduleProviderInterface {
            /** @param \Closure(): iterable<mixed> $tasks */
            public function __construct(private \Closure $tasks)
            {
            }

            public function tasks(): iterable
            {
                /** @var iterable<ScheduledTask> */
                return ($this->tasks)();
            }
        };
    }

    private static function task(string $id): ScheduledTask
    {
        return new ScheduledTask($id, new class () implements TaskInterface {
            public function execute(): void
            {
            }
        }, '* * * * *');
    }
}

final class RecordingScheduler implements SchedulerInterface
{
    /** @var list<string> */
    public array $ids = [];

    public function __construct(private ?string $failOn = null)
    {
    }

    public function register(ScheduledTask $task): void
    {
        if ($task->id === $this->failOn) {
            throw new \DomainException('backend refused ' . $task->id);
        }
        $this->ids[] = $task->id;
    }
}
