<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Integration;

/**
 * End-to-end: provider -> LaravelScheduler -> real `schedule:run` -> TaskExecutor -> FileMutex -> task.
 * Laravel decides what is due; the clock is frozen per process via SCHEDULER_DEMO_FAKE_NOW.
 */
final class LaravelDemoTest extends DemoProcessTestCase
{
    public function testDueTasksRunAndNotDueTaskDoesNot(): void
    {
        // Monday 2026-10-05 10:00 UTC: every-minute tasks are due, the Jan 1 one is not
        $pid = $this->runScheduleOnce();

        self::assertSame([
            'heartbeat' => 'success',
            'failing-task' => 'failed_task',
            'slow-report' => 'success',
        ], $this->statuses($pid));

        self::assertTrue($this->outputContains('heartbeat'));
        self::assertTrue($this->outputContains('slow-report finished'), 'task after the failing one still ran');
        self::assertFalse($this->outputContains('new-year-greeting'));
    }

    public function testFailureIsInTheJournalWhileLaravelExitCodeStaysZero(): void
    {
        $pid = $this->runScheduleOnce();

        $failed = array_values(array_filter($this->journal($pid), fn (array $r) => $r['task'] === 'failing-task'));
        self::assertCount(1, $failed);
        self::assertSame('failed_task', $failed[0]['status']);
        self::assertSame('RuntimeException', $failed[0]['error']['class']);
        self::assertStringContainsString('Demo failure', $failed[0]['error']['message']);

        // native behaviour from A0: schedule:run reports the error and still exits 0,
        // which is exactly why the exit code is not our success signal
        self::assertSame(0, $this->exitCodeOf($pid));
        self::assertStringContainsString('Demo failure', $this->stderrOf($pid), 'Laravel reported it too');
    }

    public function testTaskTimezoneDecidesDueness(): void
    {
        // 2026-12-31 23:00 UTC is 2027-01-01 00:00 in Prague -> due
        $due = $this->runScheduleOnce(['SCHEDULER_DEMO_FAKE_NOW' => '2026-12-31T23:00:00Z']);
        self::assertSame('success', $this->statuses($due)['new-year-greeting'] ?? null);

        // 2027-01-01 00:00 UTC is 01:00 in Prague -> not due, even though it's midnight UTC
        $notDue = $this->runScheduleOnce(['SCHEDULER_DEMO_FAKE_NOW' => '2027-01-01T00:00:00Z']);
        self::assertArrayNotHasKey('new-year-greeting', $this->statuses($notDue));
        self::assertArrayHasKey('heartbeat', $this->statuses($notDue));
    }

    public function testConcurrentSchedulerProcessesDoNotOverlapTheSlowTask(): void
    {
        $env = ['DEMO_SLOW_SECONDS' => '3'];

        $first = $this->startScheduleRun($env);
        $this->waitUntil(fn () => $this->outputContains("pid=$first slow-report started"), 15, 'first process to start slow-report');

        // second scheduler process while the first one is inside slow-report
        $second = $this->startScheduleRun($env);
        $this->finish($second);
        self::assertTrue(
            !$this->outputContains("pid=$first slow-report finished"),
            'second process must have finished while the first one still held the lock',
        );

        $this->finish($first);

        self::assertSame('skipped_locked', $this->statuses($second)['slow-report']);
        self::assertSame('success', $this->statuses($second)['heartbeat'], 'other tasks are not blocked');
        self::assertSame('success', $this->statuses($first)['slow-report']);
        self::assertFalse($this->outputContains("pid=$second slow-report started"));

        // lock is free again after a normal finish
        $third = $this->runScheduleOnce(['DEMO_SLOW_SECONDS' => '0.2']);
        self::assertSame('success', $this->statuses($third)['slow-report']);

        $this->assertStartFinishPairsNeverInterleave();
    }

    public function testKilledOwnerDoesNotBlockTheNextRun(): void
    {
        $owner = $this->startScheduleRun(['DEMO_SLOW_SECONDS' => '60']);
        $this->waitUntil(fn () => $this->outputContains("pid=$owner slow-report started"), 15, 'owner to start slow-report');

        $this->kill($owner); // SIGKILL: no finally, no destructor

        $next = $this->runScheduleOnce();
        self::assertSame('success', $this->statuses($next)['slow-report']);
        self::assertArrayNotHasKey('slow-report', $this->statuses($owner), 'killed process never reported');
    }

    public function testBrokenScheduleRunsNothing(): void
    {
        $pid = $this->runScheduleOnce(['DEMO_INCLUDE_BROKEN_TASK' => '1']);

        self::assertNotSame(0, $this->exitCodeOf($pid));
        self::assertStringContainsString('broken-task', $this->stderrOf($pid));
        self::assertSame([], $this->journal(), 'no task may run from a half-registered schedule');
        self::assertSame([], $this->outputLines());
    }

    public function testBrokenScheduleDoesNotBreakOtherArtisanCommands(): void
    {
        $env = ['DEMO_INCLUDE_BROKEN_TASK' => '1'];

        $list = $this->startArtisan(['list'], $env);
        self::assertSame(0, $this->finish($list), 'artisan list must not build the schedule');

        $scheduleList = $this->startArtisan(['schedule:list'], $env);
        self::assertNotSame(0, $this->finish($scheduleList));
        self::assertStringContainsString('broken-task', $this->stderrOf($scheduleList));
    }

    /** @return iterable<string, array{list<string>}> */
    public static function scheduleRunInvocations(): iterable
    {
        yield 'plain' => [['schedule:run']];
        yield '--env before' => [['--env=local', 'schedule:run']];
        yield '--env after' => [['schedule:run', '--env=local']];
        yield '-v before' => [['-v', 'schedule:run']];
        yield '-vvv after' => [['schedule:run', '-vvv']];
        yield 'both before' => [['--env=local', '-v', 'schedule:run']];
    }

    /** @param list<string> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('scheduleRunInvocations')]
    public function testGlobalOptionsInAnyPositionSeeTheSameSchedule(array $args): void
    {
        $pid = $this->startArtisan($args);
        self::assertSame(0, $this->finish($pid));

        self::assertSame([
            'heartbeat' => 'success',
            'failing-task' => 'failed_task',
            'slow-report' => 'success',
        ], $this->statuses($pid), 'artisan ' . implode(' ', $args));
    }

    /** @param list<string> $args */
    #[\PHPUnit\Framework\Attributes\TestWith([['schedule:list']])]
    #[\PHPUnit\Framework\Attributes\TestWith([['-v', 'schedule:list']])]
    #[\PHPUnit\Framework\Attributes\TestWith([['--env=local', 'schedule:list']])]
    #[\PHPUnit\Framework\Attributes\TestWith([['schedule:list', '-v']])]
    public function testScheduleListShowsTheTasks(array $args): void
    {
        $pid = $this->startArtisan($args);
        self::assertSame(0, $this->finish($pid));

        $out = $this->stdoutOf($pid);
        foreach (['heartbeat', 'failing-task', 'slow-report', 'new-year-greeting'] as $id) {
            self::assertStringContainsString($id, $out, 'artisan ' . implode(' ', $args));
        }
    }

    public function testBrokenScheduleStopsScheduleCommandsWhateverTheOptionOrder(): void
    {
        $env = ['DEMO_INCLUDE_BROKEN_TASK' => '1'];

        foreach ([['-v', 'schedule:run'], ['--env=local', 'schedule:list'], ['schedule:run', '-v']] as $args) {
            $pid = $this->startArtisan($args, $env);
            self::assertNotSame(0, $this->finish($pid), 'artisan ' . implode(' ', $args));
            self::assertStringContainsString('broken-task', $this->stderrOf($pid) . $this->stdoutOf($pid));
        }

        foreach ([['list'], ['-v', 'list'], ['--env=local', 'about']] as $args) {
            $pid = $this->startArtisan($args, $env);
            self::assertSame(0, $this->finish($pid), 'artisan ' . implode(' ', $args) . ' must not build the schedule');
        }

        self::assertSame([], $this->journal(), 'nothing may run from a broken schedule');
        self::assertSame([], $this->outputLines());
    }

    public function testProgrammaticArtisanCallRunsTheSchedule(): void
    {
        $pid = $this->startPhpScript(dirname(__DIR__) . '/Fixtures/call-schedule-run.php');
        self::assertSame(0, $this->finish($pid), $this->stderrOf($pid));

        self::assertSame('success', $this->statuses($pid)['heartbeat'] ?? null);
    }

    private function assertStartFinishPairsNeverInterleave(): void
    {
        $open = null;
        foreach ($this->outputLines() as $line) {
            if (str_contains($line, 'slow-report started')) {
                self::assertNull($open, "slow-report started twice without finishing:\n" . implode("\n", $this->outputLines()));
                $open = $line;
            } elseif (str_contains($line, 'slow-report finished')) {
                self::assertNotNull($open);
                $open = null;
            }
        }
        self::assertNull($open);
    }
}
