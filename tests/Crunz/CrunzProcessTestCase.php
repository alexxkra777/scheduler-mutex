<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz;

use SchedulerTest\Tests\Integration\DemoProcessTestCase;

/**
 * Runs the real `vendor/bin/crunz` as separate OS processes - the demo in examples/crunz or the
 * fixture app in Fixtures/app. crunz reads crunz.yml from its working directory, so every
 * process starts in the app's directory. Journal rows carry the worker's pid, not crunz's.
 */
abstract class CrunzProcessTestCase extends DemoProcessTestCase
{
    protected const ROOT = __DIR__ . '/../..';
    protected const DEMO_DIR = self::ROOT . '/examples/crunz';
    protected const FIXTURE_DIR = __DIR__ . '/Fixtures/app';

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    protected function startCrunz(array $args, array $env = [], string $cwd = self::DEMO_DIR): int
    {
        return $this->startProcess([PHP_BINARY, realpath(self::ROOT) . '/vendor/bin/crunz', ...$args], $env, $cwd);
    }

    /** @param array<string, string> $env */
    protected function runCrunz(array $env = [], string $cwd = self::DEMO_DIR): int
    {
        $pid = $this->startCrunz(['schedule:run'], $env, $cwd);
        $this->finish($pid);

        return $pid;
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    protected function startWorker(array $args, array $env = [], string $worker = self::DEMO_DIR . '/worker.php'): int
    {
        return $this->startProcess([PHP_BINARY, $worker, ...$args], $env);
    }

    /** @return array<string, list<string>> task id => statuses in journal order, all processes */
    protected function statusesByTask(?string $journal = null): array
    {
        $map = [];
        foreach ($this->rows($journal) as $row) {
            self::assertIsString($row['task']);
            self::assertIsString($row['status']);
            $map[$row['task']][] = $row['status'];
        }
        ksort($map);

        return $map;
    }

    /** @return list<array<string, mixed>> */
    protected function rows(?string $journal = null): array
    {
        if ($journal === null) {
            return $this->journal();
        }
        if (!is_file($journal)) {
            return [];
        }

        $rows = [];
        foreach (file($journal, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /** pid of the process that wrote the first line containing $marker to the demo output, waits for it */
    protected function waitForOutputPid(string $marker, float $timeout = 15.0, ?string $file = null): int
    {
        $file ??= $this->dir . '/output.log';
        $pid = null;
        $this->waitUntil(function () use ($marker, $file, &$pid): bool {
            foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
                if (str_contains($line, $marker) && preg_match('/pid=(\d+)/', $line, $m) === 1) {
                    $pid = (int) $m[1];

                    return true;
                }
            }

            return false;
        }, $timeout, "\"$marker\" in $file");
        self::assertIsInt($pid);

        return $pid;
    }

    protected static function parentPid(int $pid): ?int
    {
        // Linux: /proc/<pid>/stat, field 4 (the command in field 2 may contain spaces, so cut after ")")
        $stat = @file_get_contents("/proc/$pid/stat");
        if (is_string($stat) && preg_match('/\) \S+ (\d+) /', $stat, $m) === 1) {
            return (int) $m[1];
        }

        // macOS has no /proc
        $out = shell_exec('ps -o ppid= -p ' . $pid);

        return is_string($out) && trim($out) !== '' ? (int) trim($out) : null;
    }

    protected static function isAlive(int $pid): bool
    {
        return posix_kill($pid, 0);
    }

    protected function waitUntilDead(int $pid, float $timeout = 10.0): void
    {
        $this->waitUntil(static fn (): bool => !self::isAlive($pid), $timeout, "pid $pid to exit");
    }

    /**
     * Environment for the fixture app.
     *
     * @param list<array{id: string, cron: string, tz?: string, sleep?: float}> $cases
     * @param array<string, string>                                              $extra
     *
     * @return array<string, string>
     */
    protected function fixtureEnv(array $cases, ?string $now = null, array $extra = []): array
    {
        return array_merge([
            'FIXTURE_CASES' => json_encode($cases, JSON_THROW_ON_ERROR),
            'FIXTURE_OUTPUT' => $this->dir . '/fixture-output.log',
            'FIXTURE_JOURNAL' => $this->dir . '/fixture-journal.jsonl',
            'FIXTURE_LOCK_DIR' => $this->dir . '/fixture-locks',
            'FIXTURE_NOW' => $now ?? '',
        ], $extra);
    }

    /** @return list<string> ids of fixture tasks that started */
    protected function fixtureStarted(): array
    {
        $file = $this->dir . '/fixture-output.log';
        $ids = [];
        foreach (is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            if (preg_match('/^(\S+) started /', $line, $m) === 1) {
                $ids[] = $m[1];
            }
        }
        sort($ids);

        return $ids;
    }
}
