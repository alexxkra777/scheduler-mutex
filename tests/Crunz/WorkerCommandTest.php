<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\Exception\MutexException;
use SchedulerTest\Contract\Exception\MutexReleaseException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\ScheduleProviderInterface;
use SchedulerTest\Execution\ExecutionStatus;
use SchedulerTest\Execution\TaskExecutor;
use SchedulerTest\Infrastructure\Crunz\WorkerCommand;
use SchedulerTest\Tests\Execution\Fake\FakeMutex;
use SchedulerTest\Tests\Execution\Fake\FakeTask;
use SchedulerTest\Tests\Execution\Fake\RecordingReporter;
use SchedulerTest\Tests\Laravel\Fixtures\ListProvider;

/**
 * The worker's decisions with the real TaskExecutor over the existing fakes. These fakes stand in for
 * the mutex only to reach branches a real file system won't give on demand (release failure);
 * the real FileMutex and real processes are in CrunzDemoTest / CrunzCronRunTest.
 */
final class WorkerCommandTest extends TestCase
{
    private FakeMutex $mutex;
    private RecordingReporter $reporter;
    private int $providerCalls = 0;
    private int $executorCalls = 0;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->mutex = new FakeMutex();
        $this->reporter = new RecordingReporter();
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stderr);
        $this->stderr = $stderr;
    }

    /** @return iterable<string, array{list<string>}> */
    public static function wrongArguments(): iterable
    {
        yield 'none' => [[]];
        yield 'only the command' => [['task:run']];
        yield 'unknown command' => [['task:start', 'a']];
        yield 'empty id' => [['task:run', '']];
        yield 'extra argument' => [['task:run', 'a', 'b']];
        yield 'option instead of command' => [['--force', 'a']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('wrongArguments')]
    public function testWrongArgumentsExitWith2AndRunNothing(array $arguments): void
    {
        $task = new FakeTask($this->mutex);

        self::assertSame(2, $this->command([new ScheduledTask('a', $task, '* * * * *')])->run($arguments, $this->stderr));

        self::assertSame(0, $task->calls);
        self::assertSame(0, $this->providerCalls, 'the application is not even bootstrapped');
        self::assertSame([], $this->reporter->results);
        self::assertStringContainsString('usage: <worker> task:run <task-id>', $this->stderrText());
    }

    public function testUnknownIdExitsWith2AndRunsNothing(): void
    {
        $task = new FakeTask($this->mutex);

        self::assertSame(2, $this->command([new ScheduledTask('a', $task, '* * * * *')])->run(['task:run', 'b'], $this->stderr));

        self::assertSame(0, $task->calls);
        self::assertSame([], $this->mutex->log);
        self::assertSame([], $this->reporter->results);
        self::assertStringContainsString('unknown task id "b"', $this->stderrText());
    }

    public function testRunsTheTaskThroughTheExecutorUnderTheMutex(): void
    {
        $task = new FakeTask($this->mutex);
        $other = new FakeTask($this->mutex);

        $exit = $this->command([
            new ScheduledTask('other', $other, '* * * * *'),
            new ScheduledTask('report.daily', $task, '* * * * *'),
        ])->run(['task:run', 'report.daily'], $this->stderr);

        self::assertSame(0, $exit);
        self::assertSame(1, $task->calls);
        self::assertSame(0, $other->calls);
        self::assertTrue($task->lockWasHeld);
        self::assertSame(['acquire:scheduler:app:test:report.daily', 'task', 'release:scheduler:app:test:report.daily'], $this->mutex->log);
        self::assertSame([ExecutionStatus::SUCCESS], $this->statuses());
        self::assertSame('', $this->stderrText());
    }

    public function testDoesNotCheckWhetherTheTaskIsDue(): void
    {
        // Feb 29 at 00:00 is not "now" for this test run; crunz already decided, the worker just runs it
        $task = new FakeTask($this->mutex);

        self::assertSame(0, $this->command([new ScheduledTask('leap', $task, '0 0 29 2 *')])->run(['task:run', 'leap'], $this->stderr));
        self::assertSame(1, $task->calls);
    }

    public function testBusyLockIsSuccessExitAndSkippedInTheJournal(): void
    {
        $this->mutex->busy = true;
        $task = new FakeTask($this->mutex);

        self::assertSame(0, $this->command([new ScheduledTask('a', $task, '* * * * *')])->run(['task:run', 'a'], $this->stderr));
        self::assertSame(0, $task->calls);
        self::assertSame([ExecutionStatus::SKIPPED_LOCKED], $this->statuses());
    }

    public function testTaskFailureExitsWith1(): void
    {
        $task = new FakeTask($this->mutex, new \RuntimeException('upstream down'));

        self::assertSame(1, $this->command([new ScheduledTask('a', $task, '* * * * *')])->run(['task:run', 'a'], $this->stderr));
        self::assertSame([ExecutionStatus::FAILED_TASK], $this->statuses());
        self::assertStringContainsString('Task "a" failed (failed_task): RuntimeException: upstream down', $this->stderrText());
        self::assertNull($this->mutex->heldLock(), 'lock released after the failure');
    }

    public function testMutexFailureExitsWith1(): void
    {
        $this->mutex->acquireError = new MutexException('disk gone');
        $task = new FakeTask($this->mutex);

        self::assertSame(1, $this->command([new ScheduledTask('a', $task, '* * * * *')])->run(['task:run', 'a'], $this->stderr));
        self::assertSame(0, $task->calls);
        self::assertSame([ExecutionStatus::FAILED_MUTEX], $this->statuses());
        self::assertStringContainsString('failed_mutex', $this->stderrText());
    }

    public function testReleaseFailureIsNotHidden(): void
    {
        $this->mutex->releaseError = new MutexReleaseException('flock(LOCK_UN) failed');
        $task = new FakeTask($this->mutex);

        self::assertSame(1, $this->command([new ScheduledTask('a', $task, '* * * * *')])->run(['task:run', 'a'], $this->stderr));
        self::assertSame(1, $task->calls);
        self::assertSame([ExecutionStatus::FAILED_CLEANUP], $this->statuses());
        self::assertStringContainsString('flock(LOCK_UN) failed', $this->stderrText());
    }

    public function testTaskAndReleaseFailureKeepBothErrors(): void
    {
        $this->mutex->releaseError = new MutexReleaseException('release broke');
        $task = new FakeTask($this->mutex, new \RuntimeException('task broke'));

        self::assertSame(1, $this->command([new ScheduledTask('a', $task, '* * * * *')])->run(['task:run', 'a'], $this->stderr));
        self::assertSame([ExecutionStatus::FAILED_TASK], $this->statuses());
        self::assertStringContainsString('task broke', $this->stderrText());
        self::assertStringContainsString('release broke', $this->stderrText());
    }

    /** @return iterable<string, array{\Closure(FakeTask): ScheduleProviderInterface, string}> */
    public static function brokenConfigurations(): iterable
    {
        yield 'provider throws' => [
            static fn (FakeTask $t): ScheduleProviderInterface => new class() implements ScheduleProviderInterface {
                public function tasks(): iterable
                {
                    throw new \RuntimeException('config source down');
                }
            },
            'config source down',
        ];
        yield 'duplicate id' => [
            static fn (FakeTask $t): ScheduleProviderInterface => new ListProvider([
                new ScheduledTask('a', $t, '* * * * *'),
                new ScheduledTask('a', $t, '0 * * * *'),
            ]),
            'DuplicateTaskIdException',
        ];
        yield 'not a ScheduledTask' => [
            static fn (FakeTask $t): ScheduleProviderInterface => new ListProvider([new ScheduledTask('a', $t, '* * * * *'), 'oops']),
            'ScheduleConfigurationException',
        ];
        yield 'another task has a cron the parser rejects' => [
            static fn (FakeTask $t): ScheduleProviderInterface => new ListProvider([
                new ScheduledTask('a', $t, '* * * * *'),
                new ScheduledTask('b', $t, '61 * * * *'),
            ]),
            'InvalidCronExpressionException',
        ];
        yield 'another task can never run' => [
            static fn (FakeTask $t): ScheduleProviderInterface => new ListProvider([
                new ScheduledTask('a', $t, '* * * * *'),
                new ScheduledTask('b', $t, '0 0 31 2 *'),
            ]),
            'InvalidCronExpressionException',
        ];
    }

    /** @param \Closure(FakeTask): ScheduleProviderInterface $provider */
    #[DataProvider('brokenConfigurations')]
    public function testBrokenConfigurationExitsWith3BeforeAnyTask(\Closure $provider, string $message): void
    {
        $task = new FakeTask($this->mutex);
        $command = new WorkerCommand(
            fn (): ScheduleProviderInterface => $provider($task),
            fn (): TaskExecutor => $this->executor(),
        );

        self::assertSame(3, $command->run(['task:run', 'a'], $this->stderr));

        self::assertSame(0, $task->calls);
        self::assertSame([], $this->mutex->log);
        self::assertSame([], $this->reporter->results);
        self::assertStringContainsString('configuration error, nothing was run', $this->stderrText());
        self::assertStringContainsString($message, $this->stderrText());
    }

    public function testBadExecutorSettingsExitWith3(): void
    {
        $task = new FakeTask($this->mutex);
        $command = new WorkerCommand(
            fn (): ScheduleProviderInterface => new ListProvider([new ScheduledTask('a', $task, '* * * * *')]),
            fn (): TaskExecutor => new TaskExecutor($this->mutex, 'bad:app', 'test'),
        );

        self::assertSame(3, $command->run(['task:run', 'a'], $this->stderr));
        self::assertSame(0, $task->calls);
    }

    public function testErrorMessagesStayOnOneLine(): void
    {
        $task = new FakeTask($this->mutex, new \RuntimeException("line one\nline two"));

        $this->command([new ScheduledTask('a', $task, '* * * * *')])->run(['task:run', 'a'], $this->stderr);

        self::assertSame(1, substr_count($this->stderrText(), "\n"));
        self::assertStringStartsWith('scheduler-worker: ', $this->stderrText());
    }

    /** @param list<ScheduledTask> $tasks */
    private function command(array $tasks): WorkerCommand
    {
        return new WorkerCommand(
            function () use ($tasks): ScheduleProviderInterface {
                $this->providerCalls++;

                return new ListProvider($tasks);
            },
            function (): TaskExecutor {
                $this->executorCalls++;

                return $this->executor();
            },
        );
    }

    private function executor(): TaskExecutor
    {
        return new TaskExecutor($this->mutex, 'app', 'test', $this->reporter);
    }

    /** @return list<ExecutionStatus> */
    private function statuses(): array
    {
        return array_map(static fn ($r) => $r->status, $this->reporter->results);
    }

    private function stderrText(): string
    {
        rewind($this->stderr);

        return (string) stream_get_contents($this->stderr);
    }
}
