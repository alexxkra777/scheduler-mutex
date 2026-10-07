<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * End-to-end over the real backend switch: the same provider, tasks, TaskExecutor, FileMutex and
 * journal as the Laravel demo, started by the real `crunz schedule:run` (no --force) in
 * examples/crunz. crunz decides what is due; a worker process per due task runs it.
 */
final class CrunzDemoTest extends CrunzProcessTestCase
{
    private const EVERY_MINUTE = ['failing-task' => ['failed_task'], 'heartbeat' => ['success'], 'slow-report' => ['success']];

    public function testDueTasksRunAndNotDueTaskDoesNotOnTheRealClock(): void
    {
        // no frozen clock at all: crunz's own `new DateTimeImmutable()` decides somewhere in between
        $before = new \DateTimeImmutable();
        $crunz = $this->runCrunz(['SCHEDULER_DEMO_FAKE_NOW' => '']);
        $after = new \DateTimeImmutable();

        self::assertSame(0, $this->exitCodeOf($crunz));
        // three tasks, or four in Prague's new-year minute; DemoRunExpectationTest pins the rule
        DemoRunExpectation::assertRun($this->journal(), $crunz, DemoRunExpectation::window($before, $after));
    }

    /** @return iterable<string, array{string, string}> */
    public static function controlledMinutes(): iterable
    {
        yield 'ordinary minute' => ['2026-10-05T10:00:00Z', DemoRunExpectation::OUTSIDE];
        yield 'new-year minute in Prague' => ['2026-12-31T23:00:00Z', DemoRunExpectation::INSIDE];
        yield 'last second of that minute' => ['2026-12-31T23:00:59Z', DemoRunExpectation::INSIDE];
        yield 'the minute after' => ['2026-12-31T23:01:00Z', DemoRunExpectation::OUTSIDE];
    }

    // the same checks as the real-clock test, at chosen minutes of crunz's (frozen) clock
    #[DataProvider('controlledMinutes')]
    public function testOneWorkerPerDueTaskAtAControlledMinute(string $now, string $window): void
    {
        $at = new \DateTimeImmutable($now);
        self::assertSame($window, DemoRunExpectation::window($at, $at));

        $crunz = $this->runCrunz(['SCHEDULER_DEMO_FAKE_NOW' => $now]);

        self::assertSame(0, $this->exitCodeOf($crunz));
        DemoRunExpectation::assertRun($this->journal(), $crunz, $window);
        self::assertSame($window === DemoRunExpectation::INSIDE, $this->outputContains('new-year-greeting'));
    }

    public function testFrozenCrunzClockAndTaskTimezoneDecideDueness(): void
    {
        // 2026-12-31 23:00 UTC is 2027-01-01 00:00 in Prague -> due
        $this->runCrunz(['SCHEDULER_DEMO_FAKE_NOW' => '2026-12-31T23:00:00Z']);
        self::assertSame(['success'], $this->statusesByTask()['new-year-greeting'] ?? null);

        // 2027-01-01 00:00 UTC is 01:00 in Prague -> not due, although it's midnight UTC
        unlink($this->dir . '/journal.jsonl');
        $this->runCrunz(['SCHEDULER_DEMO_FAKE_NOW' => '2027-01-01T00:00:00Z']);
        self::assertSame(self::EVERY_MINUTE, $this->statusesByTask());
    }

    public function testFailedTaskIsInTheJournalAndCrunzOutputWhileCrunzExitsZero(): void
    {
        $crunz = $this->runCrunz();

        $failed = array_values(array_filter($this->journal(), static fn (array $r): bool => $r['task'] === 'failing-task'));
        self::assertCount(1, $failed);
        self::assertSame('failed_task', $failed[0]['status']);
        self::assertSame('RuntimeException', $failed[0]['error']['class']);

        // native crunz behaviour: the worker's exit 1 doesn't change crunz's exit code, it only
        // prints what the worker wrote to stderr - so the exit code is no success signal here either
        self::assertSame(0, $this->exitCodeOf($crunz));
        self::assertStringContainsString(
            'scheduler-worker: Task "failing-task" failed (failed_task): RuntimeException: Demo failure',
            $this->stdoutOf($crunz),
        );
    }

    public function testScheduleListShowsIdsNumericCronAndTheWorkerCommand(): void
    {
        $list = $this->startCrunz(['schedule:list', '--format', 'json']);
        self::assertSame(0, $this->finish($list));

        $rows = json_decode($this->stdoutOf($list), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertSame(
            ['heartbeat' => '* * * * *', 'failing-task' => '* * * * *', 'slow-report' => '* * * * *', 'new-year-greeting' => '0 0 1 1 *'],
            array_column($rows, 'expression', 'task'),
        );
        $worker = realpath(self::DEMO_DIR . '/worker.php');
        self::assertSame("exec '" . PHP_BINARY . "' '$worker' 'task:run' 'heartbeat'", $rows[0]['command']);
        self::assertSame([], $this->journal(), 'listing runs nothing');
    }

    public function testTaskDebugUsesTheTaskTimezoneForNextRuns(): void
    {
        $debug = $this->startCrunz(['task:debug', '4']);
        self::assertSame(0, $this->finish($debug));
        $out = $this->stdoutOf($debug);

        self::assertStringContainsString('new-year-greeting', $out);
        self::assertMatchesRegularExpression('/Comparisons timezone\s*\|\s*Europe\/Prague \(from task\)/', $out);
        self::assertSame(5, preg_match_all('/#\d\s*\|\s*\d{4}-01-01 00:00:00 Europe\/Prague/', $out));
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function brokenConfigurations(): iterable
    {
        yield 'cron the parser rejects' => [['DEMO_INCLUDE_BROKEN_TASK' => '1'], 'broken-task'];
        yield 'provider failure' => [['DEMO_PROVIDER_FAILS' => '1'], 'Demo provider failure'];
        // the workers would all exit 3 and crunz 0 with an empty journal - the task file must fail instead
        yield 'invalid application name for the lock key' => [['SCHEDULER_APP' => 'bad app'], 'bad app'];
        yield 'invalid environment name for the lock key' => [['SCHEDULER_ENV' => 'prod:eu'], 'prod:eu'];
    }

    /** @param array<string, string> $env */
    #[DataProvider('brokenConfigurations')]
    public function testBrokenConfigurationFailsTheWholeRunBeforeAnyTask(array $env, string $message): void
    {
        $crunz = $this->runCrunz($env);

        self::assertNotSame(0, $this->exitCodeOf($crunz));
        self::assertStringContainsString($message, $this->stdoutOf($crunz) . $this->stderrOf($crunz));
        // the valid tasks yielded before the broken entry did not run either
        self::assertSame([], $this->journal());
        self::assertSame([], $this->outputLines());
    }

    /** @return iterable<string, array{list<string>, int, string}> */
    public static function workerCalls(): iterable
    {
        yield 'no arguments' => [[], 2, 'usage'];
        yield 'missing id' => [['task:run'], 2, 'usage'];
        yield 'wrong command' => [['run', 'heartbeat'], 2, 'usage'];
        yield 'extra argument' => [['task:run', 'heartbeat', 'now'], 2, 'usage'];
        yield 'unknown id' => [['task:run', 'nope'], 2, 'unknown task id "nope"'];
        yield 'id with shell characters' => [['task:run', 'heartbeat; touch x'], 2, 'unknown task id'];
    }

    /** @param list<string> $args */
    #[DataProvider('workerCalls')]
    public function testWorkerRejectsWrongCallsWithoutRunningAnything(array $args, int $exit, string $message): void
    {
        $worker = $this->startWorker($args);

        self::assertSame($exit, $this->finish($worker));
        self::assertStringContainsString($message, $this->stderrOf($worker));
        self::assertSame([], $this->journal());
        self::assertSame([], $this->outputLines());
    }

    public function testWorkerReportsConfigurationErrorsWithExit3(): void
    {
        $worker = $this->startWorker(['task:run', 'heartbeat'], ['DEMO_INCLUDE_BROKEN_TASK' => '1']);

        self::assertSame(3, $this->finish($worker));
        self::assertStringContainsString('configuration error, nothing was run', $this->stderrOf($worker));
        self::assertStringContainsString('broken-task', $this->stderrOf($worker));
        self::assertSame([], $this->outputLines());
    }

    public function testWorkerStartedLateStillRunsTheTask(): void
    {
        // crunz chose the task at its minute; the worker starts whenever it starts and doesn't
        // re-check. The yearly task is not due now on the real clock - it runs anyway.
        $worker = $this->startWorker(['task:run', 'new-year-greeting'], ['SCHEDULER_DEMO_FAKE_NOW' => '']);

        self::assertSame(0, $this->finish($worker), $this->stderrOf($worker));
        self::assertSame(['new-year-greeting' => ['success']], $this->statusesByTask());
        self::assertTrue($this->outputContains('new-year-greeting'));
    }

    public function testRunFromAnotherDirectoryFindsNoTasks(): void
    {
        // native crunz behaviour, the reason the docs say "cd examples/crunz" first: no crunz.yml
        // and no tasks/ there, so crunz prints a notice and exits 0 without running anything
        $crunz = $this->runCrunz([], realpath(self::ROOT) ?: self::ROOT);

        self::assertSame(0, $this->exitCodeOf($crunz));
        self::assertStringContainsString('No task found', $this->stdoutOf($crunz));
        self::assertSame([], $this->journal());
    }

    // --- mutex across processes and backends ---

    public function testTwoCrunzRunnersRunTheSlowTaskOnlyOnce(): void
    {
        $first = $this->startCrunz(['schedule:run'], ['DEMO_SLOW_SECONDS' => '3']);
        $owner = $this->waitForOutputPid('slow-report started');

        // readiness barrier passed: the first runner's worker holds the lock right now
        $second = $this->runCrunz(['DEMO_SLOW_SECONDS' => '3']);
        self::assertSame(0, $this->exitCodeOf($second));
        self::assertTrue(self::isAlive($owner), 'the owner was still running when the second runner finished');
        self::assertSame(0, $this->finish($first));

        $statuses = $this->statusesByTask();
        self::assertSame(['skipped_locked', 'success'], $statuses['slow-report']);
        self::assertSame(['success', 'success'], $statuses['heartbeat']);
        self::assertSame(['failed_task', 'failed_task'], $statuses['failing-task'], 'a failed run leaves the lock free');
        self::assertSame(1, $this->countOutput('slow-report started'));

        // after the owner finished, the lock is free again
        $this->runCrunz(['DEMO_SLOW_SECONDS' => '0.1']);
        self::assertSame(['skipped_locked', 'success', 'success'], $this->statusesByTask()['slow-report']);
    }

    public function testCrunzWorkerBlocksLaravelOnTheSameTask(): void
    {
        $crunz = $this->startCrunz(['schedule:run'], ['DEMO_SLOW_SECONDS' => '3']);
        $owner = $this->waitForOutputPid('slow-report started');

        $laravel = $this->runScheduleOnce(['DEMO_SLOW_SECONDS' => '3']);

        self::assertSame(['heartbeat' => 'success', 'failing-task' => 'failed_task', 'slow-report' => 'skipped_locked'], $this->statuses($laravel));
        self::assertTrue(self::isAlive($owner));
        self::assertSame(0, $this->finish($crunz));
        self::assertSame(['skipped_locked', 'success'], $this->statusesByTask()['slow-report']);
        self::assertSame(1, $this->countOutput('slow-report started'));
    }

    public function testLaravelBlocksTheCrunzWorkerOnTheSameTask(): void
    {
        $laravel = $this->startScheduleRun(['DEMO_SLOW_SECONDS' => '3']);
        self::assertSame($laravel, $this->waitForOutputPid('slow-report started'), 'Laravel runs the task in schedule:run itself');

        $crunz = $this->runCrunz(['DEMO_SLOW_SECONDS' => '3']);
        self::assertSame(0, $this->exitCodeOf($crunz));
        self::assertSame(0, $this->finish($laravel));

        $crunzRows = array_values(array_filter($this->journal(), static fn (array $r): bool => $r['pid'] !== $laravel));
        self::assertSame(['slow-report' => 'skipped_locked'], array_column(
            array_filter($crunzRows, static fn (array $r): bool => $r['task'] === 'slow-report'),
            'status',
            'task',
        ));
        self::assertSame('success', $this->statuses($laravel)['slow-report']);
        self::assertSame(1, $this->countOutput('slow-report started'));
    }

    public function testSigkillOfTheWorkerFreesTheLock(): void
    {
        $crunz = $this->startCrunz(['schedule:run'], ['DEMO_SLOW_SECONDS' => '60']);
        $worker = $this->waitForOutputPid('slow-report started');

        // the lock holder is the worker, a direct child of crunz (exec, no shell in between)
        self::assertNotSame($crunz, $worker);
        self::assertSame($crunz, self::parentPid($worker));

        posix_kill($worker, SIGKILL);
        $this->waitUntilDead($worker);
        // crunz sees a failed process, prints it and still exits 0
        self::assertSame(0, $this->finish($crunz, 15.0));

        $this->runCrunz(['DEMO_SLOW_SECONDS' => '0.1']);
        // the killed run left no journal line (killed before reporting), the next one got the lock
        self::assertSame(['success'], $this->statusesByTask()['slow-report']);
        self::assertSame(2, $this->countOutput('slow-report started'));
    }

    public function testKillingCrunzDoesNotFreeTheLockWhileTheWorkerRuns(): void
    {
        // the lock belongs to the worker, not to the crunz parent: killing crunz leaves the
        // worker running (it is not crunz's lock to give back), and the task stays protected
        $crunz = $this->startCrunz(['schedule:run'], ['DEMO_SLOW_SECONDS' => '60']);
        $worker = $this->waitForOutputPid('slow-report started');

        $this->kill($crunz);
        try {
            self::assertTrue(self::isAlive($worker));
            $this->runCrunz(['DEMO_SLOW_SECONDS' => '0.1']);
            self::assertSame(['skipped_locked'], $this->statusesByTask()['slow-report']);
        } finally {
            posix_kill($worker, SIGKILL);
            $this->waitUntilDead($worker);
        }

        $this->runCrunz(['DEMO_SLOW_SECONDS' => '0.1']);
        self::assertSame(['skipped_locked', 'success'], $this->statusesByTask()['slow-report']);
    }

    public function testRelativeLockDirectoryIsTheSameForLaravelAndCrunz(): void
    {
        // artisan starts in the project root, crunz in examples/crunz: a relative setting must still
        // point both at one directory, or the two backends silently stop excluding each other
        $env = [
            'SCHEDULER_LOCK_DIR' => self::relativeFromRoot($this->dir . '/relative-locks'),
            'DEMO_SLOW_SECONDS' => '3',
        ];
        $crunz = $this->startCrunz(['schedule:run'], $env);
        $this->waitForOutputPid('slow-report started');

        $laravel = $this->runScheduleOnce($env);
        self::assertSame(0, $this->finish($crunz));

        self::assertSame('skipped_locked', $this->statuses($laravel)['slow-report']);
        self::assertSame(1, $this->countOutput('slow-report started'));
        self::assertDirectoryDoesNotExist(self::DEMO_DIR . '/' . $env['SCHEDULER_LOCK_DIR']);
    }

    private static function relativeFromRoot(string $absolute): string
    {
        $root = (string) realpath(self::ROOT);
        $target = (string) realpath(dirname($absolute)) . '/' . basename($absolute);

        return str_repeat('../', substr_count(trim($root, '/'), '/') + 1) . ltrim($target, '/');
    }

    private function countOutput(string $needle): int
    {
        return count(array_filter($this->outputLines(), static fn (string $l): bool => str_contains($l, $needle)));
    }
}
