<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The real-clock demo test can only be green or red on the day it runs. These checks pin its
 * rule for every calendar case, independent of today's date.
 */
final class DemoRunExpectationTest extends TestCase
{
    private const CRUNZ_PID = 100;

    /** @return iterable<string, array{string, string, string}> */
    public static function windows(): iterable
    {
        // Prague is UTC+1 in winter: the new-year minute is 2026-12-31 23:00:00 - 23:00:59 UTC
        yield 'ordinary minute' => ['2026-10-05T10:00:00.100Z', '2026-10-05T10:00:00.900Z', DemoRunExpectation::OUTSIDE];
        yield 'the minute before' => ['2026-12-31T22:59:00Z', '2026-12-31T22:59:59.999Z', DemoRunExpectation::OUTSIDE];
        yield 'inside' => ['2026-12-31T23:00:00Z', '2026-12-31T23:00:00.800Z', DemoRunExpectation::INSIDE];
        yield 'inside, last second' => ['2026-12-31T23:00:59Z', '2026-12-31T23:00:59.999Z', DemoRunExpectation::INSIDE];
        yield 'into the minute' => ['2026-12-31T22:59:59.500Z', '2026-12-31T23:00:00.300Z', DemoRunExpectation::CROSSING];
        // the interval ends exactly when the minute starts: crunz may have decided at 00:00:00.000
        yield 'ends on the first instant' => ['2026-12-31T22:59:59.500Z', '2026-12-31T23:00:00Z', DemoRunExpectation::CROSSING];
        yield 'starts on the first instant after' => ['2026-12-31T23:01:00Z', '2026-12-31T23:01:00.200Z', DemoRunExpectation::OUTSIDE];
        // the wall clock may step back between the readings (NTP); the interval is still the same
        yield 'readings swapped' => ['2026-12-31T23:00:00.300Z', '2026-12-31T22:59:59.500Z', DemoRunExpectation::CROSSING];
        yield 'out of the minute' => ['2026-12-31T23:00:59.500Z', '2026-12-31T23:01:00.300Z', DemoRunExpectation::CROSSING];
        yield 'the minute after' => ['2026-12-31T23:01:00Z', '2026-12-31T23:01:00.800Z', DemoRunExpectation::OUTSIDE];
        yield 'midnight UTC is 01:00 in Prague' => ['2027-01-01T00:00:00Z', '2027-01-01T00:00:00.500Z', DemoRunExpectation::OUTSIDE];
        yield 'other year' => ['2027-12-31T23:00:10Z', '2027-12-31T23:00:20Z', DemoRunExpectation::INSIDE];
        yield 'same reading inside' => ['2026-12-31T23:00:30Z', '2026-12-31T23:00:30Z', DemoRunExpectation::INSIDE];
        yield 'same reading in summer' => ['2026-07-01T00:00:00Z', '2026-07-01T00:00:00Z', DemoRunExpectation::OUTSIDE];
    }

    #[DataProvider('windows')]
    public function testClassifiesTheClockInterval(string $before, string $after, string $expected): void
    {
        self::assertSame($expected, DemoRunExpectation::window(new \DateTimeImmutable($before), new \DateTimeImmutable($after)));
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function acceptedRuns(): iterable
    {
        yield 'three tasks outside' => [self::rows(false), DemoRunExpectation::OUTSIDE];
        yield 'four tasks inside' => [self::rows(true), DemoRunExpectation::INSIDE];
        yield 'three tasks crossing' => [self::rows(false), DemoRunExpectation::CROSSING];
        yield 'four tasks crossing' => [self::rows(true), DemoRunExpectation::CROSSING];
    }

    /** @param list<array<string, mixed>> $rows */
    #[DataProvider('acceptedRuns')]
    public function testAcceptsTheExpectedRuns(array $rows, string $window): void
    {
        DemoRunExpectation::assertRun($rows, self::CRUNZ_PID, $window);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function rejectedRuns(): iterable
    {
        $three = self::rows(false);
        $four = self::rows(true);

        yield 'empty journal' => [[], DemoRunExpectation::OUTSIDE];
        yield 'empty journal crossing' => [[], DemoRunExpectation::CROSSING];
        yield 'base task missing' => [array_slice($three, 1), DemoRunExpectation::OUTSIDE];
        yield 'base task missing inside' => [array_slice($four, 1), DemoRunExpectation::INSIDE];
        yield 'yearly task outside its minute' => [$four, DemoRunExpectation::OUTSIDE];
        yield 'yearly task missing inside its minute' => [$three, DemoRunExpectation::INSIDE];
        yield 'unknown task' => [[...$three, self::row('broken-task', 'success', 9)], DemoRunExpectation::CROSSING];
        yield 'task twice' => [[...$three, self::row('heartbeat', 'success', 9)], DemoRunExpectation::OUTSIDE];
        yield 'wrong status' => [[self::row('heartbeat', 'failed_task', 1), ...array_slice($three, 1)], DemoRunExpectation::OUTSIDE];
        yield 'yearly task failed' => [[...$three, self::row('new-year-greeting', 'failed_task', 9)], DemoRunExpectation::INSIDE];
        yield 'two tasks in one process' => [[self::row('heartbeat', 'success', 2), ...array_slice($three, 1)], DemoRunExpectation::OUTSIDE];
        yield 'task in the crunz process' => [[self::row('heartbeat', 'success', self::CRUNZ_PID), ...array_slice($three, 1)], DemoRunExpectation::OUTSIDE];
        // what the old test asserted unconditionally: three pids. Four workers inside the minute are right.
        yield 'three workers for four tasks' => [[...$three, self::row('new-year-greeting', 'success', 3)], DemoRunExpectation::INSIDE];
    }

    /** @param list<array<string, mixed>> $rows */
    #[DataProvider('rejectedRuns')]
    public function testRejectsWrongRuns(array $rows, string $window): void
    {
        $this->expectException(AssertionFailedError::class);
        DemoRunExpectation::assertRun($rows, self::CRUNZ_PID, $window);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(bool $newYear): array
    {
        $rows = [
            self::row('heartbeat', 'success', 1),
            self::row('failing-task', 'failed_task', 2),
            self::row('slow-report', 'success', 3),
        ];
        if ($newYear) {
            $rows[] = self::row('new-year-greeting', 'success', 4);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function row(string $task, string $status, int $pid): array
    {
        return ['task' => $task, 'status' => $status, 'pid' => $pid];
    }
}
