<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Execution;

use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\Exception\MutexException;
use SchedulerTest\Contract\Exception\MutexReleaseException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Execution\ExecutionResult;
use SchedulerTest\Execution\ExecutionStatus;
use SchedulerTest\Execution\TaskExecutionException;
use SchedulerTest\Execution\TaskExecutor;
use SchedulerTest\Tests\Execution\Fake\FakeMutex;
use SchedulerTest\Tests\Execution\Fake\FakeTask;
use SchedulerTest\Tests\Execution\Fake\RecordingReporter;

final class TaskExecutorTest extends TestCase
{
    private const KEY = 'scheduler:app:test:report.daily';

    private FakeMutex $mutex;
    private RecordingReporter $reporter;

    protected function setUp(): void
    {
        $this->mutex = new FakeMutex();
        $this->reporter = new RecordingReporter($this->mutex);
    }

    // --- preventOverlap = false ---

    public function testWithoutOverlapProtectionTaskRunsWithoutMutex(): void
    {
        $task = new FakeTask($this->mutex);

        $this->executor()->execute($this->scheduled($task, preventOverlap: false));

        self::assertSame(1, $task->calls);
        self::assertSame([], $this->mutex->acquiredKeys);
        self::assertSame(['task', 'report:success'], $this->mutex->log);
        $this->assertSingleResult(ExecutionStatus::SUCCESS);
    }

    public function testWithoutOverlapProtectionTaskErrorIsThrown(): void
    {
        $taskError = new \LogicException('boom');
        $task = new FakeTask($this->mutex, $taskError);

        $e = $this->expectFailure($this->scheduled($task, preventOverlap: false));

        self::assertSame(ExecutionStatus::FAILED_TASK, $e->status);
        self::assertSame($taskError, $e->getPrevious());
        self::assertNull($e->getCleanupError());
        self::assertSame([], $this->mutex->acquiredKeys);
        $result = $this->assertSingleResult(ExecutionStatus::FAILED_TASK);
        self::assertSame($taskError, $result->error);
    }

    // --- preventOverlap = true ---

    public function testAcquiredLockSuccess(): void
    {
        $task = new FakeTask($this->mutex);

        $this->executor()->execute($this->scheduled($task));

        self::assertSame(1, $task->calls);
        self::assertTrue($task->lockWasHeld, 'task must run while the lock is held');
        self::assertSame([], $task->receivedArgs, 'the lock handle is never handed to the task');
        self::assertSame([self::KEY], $this->mutex->acquiredKeys);
        self::assertSame(1, $this->mutex->locks[0]->releaseCalls);
        // order: acquire, task, release, then report
        self::assertSame(['acquire:' . self::KEY, 'task', 'release:' . self::KEY, 'report:success'], $this->mutex->log);
        $result = $this->assertSingleResult(ExecutionStatus::SUCCESS);
        self::assertNull($result->error);
        self::assertNull($result->cleanupError);
    }

    public function testBusyLockSkipsTaskAndReturnsNormally(): void
    {
        $this->mutex->busy = true;
        $task = new FakeTask($this->mutex);

        $this->executor()->execute($this->scheduled($task));

        self::assertSame(0, $task->calls);
        self::assertSame([], $this->mutex->locks);
        self::assertSame(['acquire:' . self::KEY, 'report:skipped_locked'], $this->mutex->log);
        $this->assertSingleResult(ExecutionStatus::SKIPPED_LOCKED);
    }

    public function testMutexExceptionOnAcquireDoesNotRunTask(): void
    {
        $mutexError = new MutexException('disk full');
        $this->mutex->acquireError = $mutexError;
        $task = new FakeTask($this->mutex);

        $e = $this->expectFailure($this->scheduled($task));

        self::assertSame(0, $task->calls);
        self::assertSame([], $this->mutex->locks, 'nothing acquired, nothing released');
        self::assertSame(ExecutionStatus::FAILED_MUTEX, $e->status);
        self::assertSame($mutexError, $e->getPrevious());
        self::assertNull($e->getCleanupError());
        self::assertSame(['acquire:' . self::KEY, 'report:failed_mutex'], $this->mutex->log);
        $result = $this->assertSingleResult(ExecutionStatus::FAILED_MUTEX);
        self::assertSame($mutexError, $result->error);
    }

    public function testAnyThrowableOnAcquireIsFailedMutex(): void
    {
        $mutexError = new \Error('backend crashed');
        $this->mutex->acquireError = $mutexError;
        $task = new FakeTask($this->mutex);

        $e = $this->expectFailure($this->scheduled($task));

        self::assertSame(0, $task->calls);
        self::assertSame(ExecutionStatus::FAILED_MUTEX, $e->status);
        self::assertSame($mutexError, $e->getPrevious());
        $this->assertSingleResult(ExecutionStatus::FAILED_MUTEX);
    }

    public function testTaskErrorReleasesLockAndThrows(): void
    {
        $taskError = new \RuntimeException('task broke');
        $task = new FakeTask($this->mutex, $taskError);

        $e = $this->expectFailure($this->scheduled($task));

        self::assertSame(1, $this->mutex->locks[0]->releaseCalls);
        self::assertSame(ExecutionStatus::FAILED_TASK, $e->status);
        self::assertSame($taskError, $e->getPrevious());
        self::assertNull($e->getCleanupError());
        self::assertSame(['acquire:' . self::KEY, 'task', 'release:' . self::KEY, 'report:failed_task'], $this->mutex->log);
        $result = $this->assertSingleResult(ExecutionStatus::FAILED_TASK);
        self::assertSame($taskError, $result->error);
        self::assertNull($result->cleanupError);
    }

    public function testReleaseErrorAfterSuccessfulTaskIsNotSuccess(): void
    {
        $releaseError = new MutexReleaseException('unlock failed');
        $this->mutex->releaseError = $releaseError;
        $task = new FakeTask($this->mutex);

        $e = $this->expectFailure($this->scheduled($task));

        self::assertSame(1, $task->calls);
        self::assertSame(1, $this->mutex->locks[0]->releaseCalls, 'no automatic retry');
        self::assertSame(ExecutionStatus::FAILED_CLEANUP, $e->status);
        self::assertSame($releaseError, $e->getPrevious());
        self::assertSame($releaseError, $e->getCleanupError());
        $result = $this->assertSingleResult(ExecutionStatus::FAILED_CLEANUP);
        self::assertNull($result->error);
        self::assertSame($releaseError, $result->cleanupError);
    }

    public function testTaskErrorAndReleaseErrorKeepBothCauses(): void
    {
        $taskError = new \RuntimeException('task broke');
        $releaseError = new MutexReleaseException('unlock failed');
        $this->mutex->releaseError = $releaseError;
        $task = new FakeTask($this->mutex, $taskError);

        $e = $this->expectFailure($this->scheduled($task));

        self::assertSame(1, $this->mutex->locks[0]->releaseCalls, 'no automatic retry');
        self::assertSame(ExecutionStatus::FAILED_TASK, $e->status);
        self::assertSame($taskError, $e->getPrevious(), 'primary error must not be lost');
        self::assertSame($releaseError, $e->getCleanupError());
        self::assertStringContainsString('unlock failed', $e->getMessage());
        $result = $this->assertSingleResult(ExecutionStatus::FAILED_TASK);
        self::assertSame($taskError, $result->error);
        self::assertSame($releaseError, $result->cleanupError);
    }

    public function testExceptionMessageNamesTaskAndStatus(): void
    {
        $task = new FakeTask($this->mutex, new \RuntimeException('task broke'));

        $e = $this->expectFailure($this->scheduled($task));

        self::assertSame('report.daily', $e->taskId);
        self::assertStringContainsString('report.daily', $e->getMessage());
        self::assertStringContainsString('failed_task', $e->getMessage());
        self::assertStringContainsString('task broke', $e->getMessage());
    }

    // --- reporter ---

    public function testResultHasUtcStartAndDuration(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->executor()->execute($this->scheduled(new FakeTask()));
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $result = $this->assertSingleResult(ExecutionStatus::SUCCESS);
        self::assertSame('report.daily', $result->taskId);
        self::assertSame('UTC', $result->startedAt->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before, $result->startedAt);
        self::assertLessThanOrEqual($after, $result->startedAt);
        self::assertGreaterThanOrEqual(0.0, $result->durationMs);
    }

    public function testWorksWithoutReporter(): void
    {
        $task = new FakeTask();

        (new TaskExecutor($this->mutex, 'app', 'test'))->execute($this->scheduled($task));

        self::assertSame(1, $task->calls);
        self::assertSame(1, $this->mutex->locks[0]->releaseCalls);
    }

    public function testReporterFailureDoesNotChangeSuccess(): void
    {
        $reporter = new RecordingReporter($this->mutex, new \RuntimeException("journal\nbroken"));
        $task = new FakeTask($this->mutex);

        $logged = $this->captureErrorLog(function () use ($reporter, $task): void {
            (new TaskExecutor($this->mutex, 'app', 'test', $reporter))->execute($this->scheduled($task));
        });

        self::assertSame(1, $this->mutex->locks[0]->releaseCalls);
        self::assertSame(['acquire:' . self::KEY, 'task', 'release:' . self::KEY, 'report:success'], $this->mutex->log);
        self::assertCount(1, $reporter->results);
        self::assertCount(1, $logged, 'one error_log line');
        self::assertStringContainsString('report.daily', $logged[0]);
        self::assertStringContainsString('journal broken', $logged[0]);
        self::assertStringContainsString('RuntimeException', $logged[0]);
    }

    public function testReporterFailureDoesNotChangeSkip(): void
    {
        $this->mutex->busy = true;
        $reporter = new RecordingReporter($this->mutex, new \RuntimeException('journal broken'));

        $logged = $this->captureErrorLog(function () use ($reporter): void {
            (new TaskExecutor($this->mutex, 'app', 'test', $reporter))->execute($this->scheduled(new FakeTask()));
        });

        self::assertCount(1, $logged);
        self::assertStringContainsString('skipped_locked', $logged[0]);
    }

    public function testReporterFailureDoesNotReplaceTaskError(): void
    {
        $taskError = new \RuntimeException('task broke');
        $reporter = new RecordingReporter($this->mutex, new \RuntimeException('journal broken'));
        $task = new FakeTask($this->mutex, $taskError);

        $thrown = null;
        $logged = $this->captureErrorLog(function () use ($reporter, $task, &$thrown): void {
            try {
                (new TaskExecutor($this->mutex, 'app', 'test', $reporter))->execute($this->scheduled($task));
            } catch (TaskExecutionException $e) {
                $thrown = $e;
            }
        });

        self::assertInstanceOf(TaskExecutionException::class, $thrown);
        self::assertSame($taskError, $thrown->getPrevious());
        self::assertSame(ExecutionStatus::FAILED_TASK, $thrown->status);
        self::assertSame(1, $this->mutex->locks[0]->releaseCalls);
        self::assertCount(1, $logged);
        self::assertStringContainsString('journal broken', $logged[0]);
    }

    // --- keys ---

    public function testDifferentApplicationsAndEnvironmentsUseDifferentKeys(): void
    {
        $task = $this->scheduled(new FakeTask());

        (new TaskExecutor($this->mutex, 'shop', 'prod'))->execute($task);
        (new TaskExecutor($this->mutex, 'shop', 'staging'))->execute($task);
        (new TaskExecutor($this->mutex, 'blog', 'prod'))->execute($task);

        self::assertSame([
            'scheduler:shop:prod:report.daily',
            'scheduler:shop:staging:report.daily',
            'scheduler:blog:prod:report.daily',
        ], $this->mutex->acquiredKeys);
    }

    public function testConstructorRejectsColonInApplication(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskExecutor($this->mutex, 'app:x', 'test');
    }

    public function testConstructorRejectsColonInEnvironment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskExecutor($this->mutex, 'app', 'prod:1');
    }

    // --- helpers ---

    private function executor(): TaskExecutor
    {
        return new TaskExecutor($this->mutex, 'app', 'test', $this->reporter);
    }

    private function scheduled(FakeTask $task, bool $preventOverlap = true): ScheduledTask
    {
        return new ScheduledTask('report.daily', $task, '* * * * *', preventOverlap: $preventOverlap);
    }

    private function expectFailure(ScheduledTask $task): TaskExecutionException
    {
        try {
            $this->executor()->execute($task);
        } catch (TaskExecutionException $e) {
            return $e;
        }

        self::fail('TaskExecutionException was expected');
    }

    private function assertSingleResult(ExecutionStatus $status): ExecutionResult
    {
        self::assertCount(1, $this->reporter->results, 'reporter is called exactly once');
        $result = $this->reporter->results[0];
        self::assertSame($status, $result->status);

        return $result;
    }

    /**
     * Runs $fn with error_log() pointed at a temp file and returns the logged lines.
     *
     * @return list<string>
     */
    private function captureErrorLog(\Closure $fn): array
    {
        $file = tempnam(sys_get_temp_dir(), 'exec-log-');
        self::assertIsString($file);
        $previous = ini_set('error_log', $file);

        try {
            $fn();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        unlink($file);
        self::assertIsArray($lines);

        return array_values($lines);
    }
}
