<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz;

use PHPUnit\Framework\Attributes\DataProvider;
use SchedulerTest\Tests\Laravel\CronDialectRunTest;
use SchedulerTest\Tests\Laravel\CronStepBoundaryTest;

/**
 * The cron dialect where it matters for crunz: does the real `crunz schedule:run` (no --force)
 * start the worker at a given minute or not. Same expectations as the Laravel suites, which were
 * written by hand per minute. crunz's clock is frozen the way crunz's own tests do it
 * (Fixtures/FrozenClock); crunz itself still evaluates every event.
 *
 * Cases at the same minute share one crunz run, each case being its own task.
 */
final class CrunzCronRunTest extends CrunzProcessTestCase
{
    /** @return array<string, array{string, list<array{string, bool, string}>}> utc minute => [cron, expected, case name] */
    private static function casesByMinute(): array
    {
        $byMinute = [];
        foreach (CronDialectRunTest::dueCases() as $name => [$cron, $utc, $expected]) {
            $byMinute[$utc][] = [$cron, $expected, $name];
        }
        foreach (CronStepBoundaryTest::dueCases() as $name => [$cron, $time, $expected]) {
            $byMinute["2026-03-02 $time"][] = [$cron, $expected, "steps: $name"];
        }

        $out = [];
        foreach ($byMinute as $minute => $cases) {
            $out[$minute . ' (' . count($cases) . ' cases)'] = [$minute, $cases];
        }

        return $out;
    }

    /** @return iterable<string, array{string, list<array{string, bool, string}>}> */
    public static function minutes(): iterable
    {
        yield from self::casesByMinute();
    }

    /** @param list<array{string, bool, string}> $cases */
    #[DataProvider('minutes')]
    public function testCrunzStartsExactlyTheDueTasks(string $utcMinute, array $cases): void
    {
        $tasks = [];
        $expected = [];
        foreach ($cases as $i => [$cron, $due]) {
            $tasks[] = ['id' => "case-$i", 'cron' => $cron];
            if ($due) {
                $expected[] = "case-$i";
            }
        }
        sort($expected);

        $crunz = $this->runCrunz($this->fixtureEnv($tasks, $utcMinute . ':00 UTC'), self::FIXTURE_DIR);

        self::assertSame(0, $this->exitCodeOf($crunz), $this->stdoutOf($crunz) . $this->stderrOf($crunz));
        $started = $this->fixtureStarted();
        foreach ($cases as $i => [$cron, $due, $name]) {
            self::assertSame($due, in_array("case-$i", $started, true), sprintf('%s: "%s" at %s', $name, $cron, $utcMinute));
        }
        self::assertSame($expected, $started);
        self::assertSame(count($expected), count($this->rows($this->dir . '/fixture-journal.jsonl')));
    }

    /** @return iterable<string, array{string, string, string, bool}> */
    public static function timezoneCases(): iterable
    {
        yield 'Prague new year, 23:00 UTC' => ['0 0 1 1 *', 'Europe/Prague', '2026-12-31 23:00:00 UTC', true];
        yield 'Prague new year, 00:00 UTC' => ['0 0 1 1 *', 'Europe/Prague', '2027-01-01 00:00:00 UTC', false];
        yield 'UTC new year, 00:00 UTC' => ['0 0 1 1 *', 'UTC', '2027-01-01 00:00:00 UTC', true];
        yield 'New York 9:00 in summer' => ['0 9 * * *', 'America/New_York', '2026-07-01 13:00:00 UTC', true];
        yield 'New York 9:00 in winter' => ['0 9 * * *', 'America/New_York', '2026-12-01 14:00:00 UTC', true];
        yield 'New York 9:00, summer offset in winter' => ['0 9 * * *', 'America/New_York', '2026-12-01 13:00:00 UTC', false];
        // a weekday in the task's zone, not in UTC: Monday 00:30 in Prague is still Sunday in UTC
        yield 'Monday in Prague, Sunday in UTC' => ['30 0 * * MON', 'Europe/Prague', '2026-03-01 23:30:00 UTC', true];
        yield 'Sunday rule at that moment' => ['30 0 * * SUN', 'Europe/Prague', '2026-03-01 23:30:00 UTC', false];
        // calendar boundary: Feb 29 exists in 2028 only
        yield 'leap day 2028' => ['0 12 29 2 *', 'UTC', '2028-02-29 12:00:00 UTC', true];
        yield 'last of month crossing into March' => ['0 12 29 2 *', 'UTC', '2027-03-01 12:00:00 UTC', false];
    }

    #[DataProvider('timezoneCases')]
    public function testTaskTimezoneDecidesDueness(string $cron, string $tz, string $now, bool $due): void
    {
        $crunz = $this->runCrunz($this->fixtureEnv([['id' => 'tz-case', 'cron' => $cron, 'tz' => $tz]], $now), self::FIXTURE_DIR);

        self::assertSame(0, $this->exitCodeOf($crunz));
        self::assertSame($due ? ['tz-case'] : [], $this->fixtureStarted());
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield from CronDialectRunTest::rejected();
    }

    #[DataProvider('rejected')]
    public function testRejectedCronFailsTheRunAndNothingRuns(string $cron): void
    {
        // a valid every-minute task comes first: it must not run from a half-built schedule
        $crunz = $this->runCrunz($this->fixtureEnv([
            ['id' => 'canary', 'cron' => '* * * * *'],
            ['id' => 'bad', 'cron' => $cron],
        ], '2026-03-01 00:00:00 UTC'), self::FIXTURE_DIR);

        self::assertNotSame(0, $this->exitCodeOf($crunz), sprintf('"%s" was accepted', $cron));
        self::assertSame([], $this->fixtureStarted());
        self::assertSame([], $this->rows($this->dir . '/fixture-journal.jsonl'));
    }

    /** @return iterable<string, array{list<array{id: string, cron: string}>, array<string, string>, string}> */
    public static function brokenSchedules(): iterable
    {
        yield 'duplicate id' => [[['id' => 'canary', 'cron' => '* * * * *'], ['id' => 'canary', 'cron' => '0 * * * *']], [], 'canary'];
        yield 'date that never happens' => [[['id' => 'canary', 'cron' => '* * * * *'], ['id' => 'feb-30', 'cron' => '0 0 30 2 *']], [], 'feb-30'];
        yield 'provider failure' => [[['id' => 'canary', 'cron' => '* * * * *']], ['FIXTURE_PROVIDER_FAILS' => '1'], 'fixture provider failure'];
        yield 'invalid timezone' => [[['id' => 'canary', 'cron' => '* * * * *'], ['id' => 'tz', 'cron' => '* * * * *', 'tz' => 'Mars/Base']], [], 'Mars/Base'];
    }

    /**
     * @param list<array{id: string, cron: string}> $cases
     * @param array<string, string>                  $extra
     */
    #[DataProvider('brokenSchedules')]
    public function testBrokenScheduleFailsTheRunAndNothingRuns(array $cases, array $extra, string $message): void
    {
        $crunz = $this->runCrunz($this->fixtureEnv($cases, '2026-03-01 00:00:00 UTC', $extra), self::FIXTURE_DIR);

        self::assertNotSame(0, $this->exitCodeOf($crunz));
        self::assertStringContainsString($message, $this->stdoutOf($crunz) . $this->stderrOf($crunz));
        self::assertSame([], $this->fixtureStarted());
    }

    /**
     * crunz's `task:debug` lists the next five run dates. It uses the real clock, so the checks are
     * properties every listed date must have, plus the spacing between them.
     *
     * @return iterable<string, array{string, string, \Closure(\DateTimeImmutable): bool, list<int>|null}>
     */
    public static function nextRuns(): iterable
    {
        $minute = static fn (int ...$m): \Closure => static fn (\DateTimeImmutable $d): bool => in_array((int) $d->format('i'), $m, true) && $d->format('s') === '00';
        $at = static fn (string $hm, int ...$dows): \Closure => static fn (\DateTimeImmutable $d): bool => $d->format('H:i:s') === "$hm:00"
            && ($dows === [] || in_array((int) $d->format('w'), $dows, true));

        yield '*/59: minutes 0 and 59 only' => ['*/59 * * * *', 'UTC', $minute(0, 59), null];
        yield '10-20/59: minute 10, hourly' => ['10-20/59 * * * *', 'UTC', $minute(10), [3600, 3600, 3600, 3600]];
        yield 'hour 1-2/23: 01:00 daily' => ['0 1-2/23 * * *', 'UTC', $at('01:00'), [86400, 86400, 86400, 86400]];
        yield 'MON-FRI/2: Mon, Wed, Fri' => ['0 0 * * MON-FRI/2', 'UTC', $at('00:00', 1, 3, 5), null];
        yield 'SUN-SUN: Sundays, weekly' => ['0 0 * * SUN-SUN', 'UTC', $at('00:00', 0), [604800, 604800, 604800, 604800]];
        yield '7-7: Sundays' => ['0 0 * * 7-7', 'UTC', $at('00:00', 0), null];
        yield 'DOM 13 OR Monday' => ['0 0 13 * MON', 'UTC', static fn (\DateTimeImmutable $d): bool => $d->format('H:i') === '00:00'
            && ($d->format('d') === '13' || $d->format('w') === '1'), null];
        yield 'Prague 09:30 on weekdays' => ['30 9 * * MON-FRI', 'Europe/Prague', $at('09:30', 1, 2, 3, 4, 5), null];
    }

    /**
     * @param \Closure(\DateTimeImmutable): bool $matches
     * @param list<int>|null                     $gaps    expected seconds between consecutive dates (no skipped run)
     */
    #[DataProvider('nextRuns')]
    public function testTaskDebugShowsNextRunsInTheTaskTimezone(string $cron, string $tz, \Closure $matches, ?array $gaps): void
    {
        $debug = $this->startCrunz(['task:debug', '1'], $this->fixtureEnv([['id' => 'next', 'cron' => $cron, 'tz' => $tz]]), self::FIXTURE_DIR);
        $before = new \DateTimeImmutable();
        self::assertSame(0, $this->finish($debug), $this->stdoutOf($debug));
        $out = $this->stdoutOf($debug);

        self::assertMatchesRegularExpression('/Comparisons timezone\s*\|\s*' . preg_quote($tz, '/') . ' \(from task\)/', $out);
        self::assertSame(5, preg_match_all('/#\d\s*\|\s*(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) (\S+)/', $out, $m));
        $dates = [];
        foreach ($m[1] as $i => $date) {
            self::assertSame($tz, $m[2][$i]);
            $dates[] = $d = new \DateTimeImmutable($date, new \DateTimeZone($tz));
            self::assertTrue($matches($d), sprintf('"%s": %s does not fit', $cron, $date));
            self::assertGreaterThan($before->getTimestamp() - 60, $d->getTimestamp(), 'a future run');
        }
        for ($i = 1; $i < 5; $i++) {
            self::assertGreaterThan($dates[$i - 1], $dates[$i]);
            if ($gaps !== null) {
                self::assertSame($gaps[$i - 1], $dates[$i]->getTimestamp() - $dates[$i - 1]->getTimestamp());
            }
        }
        self::assertSame([], $this->fixtureStarted(), 'task:debug runs nothing');
    }

    /** @return iterable<string, array{string, string, ?string}> app dir, php dir, AUDIT_PATH value */
    public static function literalPaths(): iterable
    {
        // Symfony Process replaces "${:NAME}" in a shell command line even inside single quotes,
        // so these names must reach the shell untouched whether AUDIT_PATH exists or not
        $placeholder = 'quoted "${:AUDIT_PATH}" dir';
        $nasty = "dir with 'single' and \"double\" quotes \u{017E}lu\u{0165}ou\u{010D}k\u{00FD} \u{65E5}\u{672C}";
        foreach (['AUDIT_PATH not set' => null, 'AUDIT_PATH set' => 'ordinary'] as $env => $value) {
            yield "worker path with a placeholder, $env" => [$placeholder, 'php bin', $value];
            yield "php path with a placeholder, $env" => ['app', $placeholder, $value];
            yield "both paths with a placeholder, $env" => [$placeholder, $placeholder, $value];
            yield "spaces, quotes and unicode, $env" => [$nasty, $nasty . ' php', $value];
        }
    }

    #[DataProvider('literalPaths')]
    public function testLiteralPathsReachTheWorkerUnchanged(string $appDir, string $phpDir, ?string $auditPath): void
    {
        self::assertFalse(getenv('AUDIT_PATH'), 'the test controls AUDIT_PATH itself');

        // the whole app - task file, worker - and the php binary live under the given names
        $base = $this->dir . '/' . $appDir;
        mkdir($base . '/tasks', 0775, true);
        mkdir($base . '/' . $phpDir);
        copy(self::FIXTURE_DIR . '/crunz.yml', $base . '/crunz.yml');
        copy(self::FIXTURE_DIR . '/tasks/FixtureTasks.php', $base . '/tasks/FixtureTasks.php');
        copy(self::FIXTURE_DIR . '/worker.php', $base . '/worker.php');
        $php = $base . '/' . $phpDir . "/php 'x'";
        self::assertTrue(symlink(PHP_BINARY, $php));

        $extra = [
            'FIXTURE_PHP' => $php,
            'FIXTURE_AUTOLOAD' => (string) realpath(self::ROOT . '/vendor/autoload.php'),
            'FIXTURE_ARGV_LOG' => $this->dir . '/argv.jsonl',
        ];
        if ($auditPath !== null) {
            $extra['AUDIT_PATH'] = $auditPath;
        }
        $crunz = $this->runCrunz($this->fixtureEnv([['id' => 'literal.path', 'cron' => '* * * * *']], '2026-03-02 12:00:00 UTC', $extra), $base);

        self::assertSame(0, $this->exitCodeOf($crunz), $this->stdoutOf($crunz) . $this->stderrOf($crunz));
        self::assertSame(['literal.path'], $this->fixtureStarted());
        self::assertSame(['literal.path' => ['success']], $this->statusesByTask($this->dir . '/fixture-journal.jsonl'));
        self::assertSame('', trim($this->stdoutOf($crunz)), 'no shell or php error in crunz output');

        // exactly one worker, with exactly these arguments
        $calls = $this->rows($this->dir . '/argv.jsonl');
        self::assertCount(1, $calls);
        // crunz loads the task file by its real path, so the worker path is the resolved one
        $worker = (string) realpath($base) . '/worker.php';
        self::assertSame([$worker, 'task:run', 'literal.path'], $calls[0]['argv']);
        self::assertSame($worker, $calls[0]['file']);
    }
}
