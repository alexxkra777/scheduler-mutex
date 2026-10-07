<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz;

use PHPUnit\Framework\Assert;

/**
 * What one `crunz schedule:run` of the demo must leave in the journal, given when it ran.
 *
 * The three every-minute tasks run every time. `new-year-greeting` (`0 0 1 JAN *`, Europe/Prague)
 * is due only in the minute 00:00 of Jan 1 in Prague. A real-clock test knows only that crunz
 * decided somewhere between two clock readings, so:
 *   - the whole interval outside that minute -> exactly the three tasks;
 *   - the whole interval inside it           -> exactly four tasks;
 *   - the interval crossing its border       -> three or four; everything else stays exact.
 * This only classifies a time interval against one fixed minute - no cron evaluation here.
 */
final class DemoRunExpectation
{
    public const OUTSIDE = 'outside';
    public const INSIDE = 'inside';
    public const CROSSING = 'crossing';

    private const EVERY_MINUTE = ['failing-task' => 'failed_task', 'heartbeat' => 'success', 'slow-report' => 'success'];
    private const NEW_YEAR = 'new-year-greeting';

    /** @return self::OUTSIDE|self::INSIDE|self::CROSSING */
    public static function window(\DateTimeImmutable $before, \DateTimeImmutable $after): string
    {
        // the wall clock may step back between the two readings (NTP), so take the interval as it is
        [$before, $after] = $before <= $after ? [$before, $after] : [$after, $before];

        $prague = new \DateTimeZone('Europe/Prague');
        $years = array_unique([(int) $before->setTimezone($prague)->format('Y'), (int) $after->setTimezone($prague)->format('Y')]);
        $from = (float) $before->format('U.u');
        $to = (float) $after->format('U.u');

        foreach ($years as $year) {
            $start = (float) (new \DateTimeImmutable("$year-01-01 00:00:00", $prague))->format('U');
            $end = $start + 60; // the minute is [start, end)
            if ($from >= $start && $to < $end) {
                return self::INSIDE;
            }
            if ($from < $end && $to >= $start) {
                return self::CROSSING;
            }
        }

        return self::OUTSIDE;
    }

    /**
     * @param list<array<string, mixed>> $rows     the journal of that one run
     * @param self::OUTSIDE|self::INSIDE|self::CROSSING $window
     */
    public static function assertRun(array $rows, int $crunzPid, string $window): void
    {
        $byTask = [];
        foreach ($rows as $row) {
            Assert::assertIsString($row['task'] ?? null);
            Assert::assertArrayNotHasKey($row['task'], $byTask, sprintf('task "%s" has more than one journal line', $row['task']));
            $byTask[$row['task']] = $row;
        }

        // the three every-minute tasks: always, exactly once, with their known outcome
        foreach (self::EVERY_MINUTE as $task => $status) {
            Assert::assertArrayHasKey($task, $byTask, "every-minute task \"$task\" did not run");
            Assert::assertSame($status, $byTask[$task]['status'], "status of \"$task\"");
        }

        $newYearRan = isset($byTask[self::NEW_YEAR]);
        if ($window === self::OUTSIDE) {
            Assert::assertFalse($newYearRan, 'new-year-greeting ran outside 00:00 Jan 1 Prague');
        } elseif ($window === self::INSIDE) {
            Assert::assertTrue($newYearRan, 'new-year-greeting did not run at 00:00 Jan 1 Prague');
        }
        if ($newYearRan) {
            Assert::assertSame('success', $byTask[self::NEW_YEAR]['status']);
        }

        // nothing else ran
        Assert::assertSame([], array_values(array_diff(array_keys($byTask), [...array_keys(self::EVERY_MINUTE), self::NEW_YEAR])), 'unexpected tasks');
        Assert::assertCount($newYearRan ? 4 : 3, $rows);

        // one worker process per task, none of them crunz itself
        $pids = array_column($rows, 'pid');
        Assert::assertContainsOnlyInt($pids);
        Assert::assertCount(count($rows), array_unique($pids), 'every task ran in its own worker process');
        Assert::assertNotContains($crunzPid, $pids, 'a task ran inside crunz instead of a worker');
    }
}
