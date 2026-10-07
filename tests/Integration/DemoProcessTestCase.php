<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Runs the demo app's real `php artisan schedule:run` as separate OS processes.
 * Every test gets its own temp dir for locks, journal and task output.
 */
abstract class DemoProcessTestCase extends TestCase
{
    protected string $dir;

    /** @var array<int, array{proc: resource, exit: ?int, out: string, err: string}> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scheduler-test-int-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $pid => $p) {
            if ($p['exit'] === null && proc_get_status($p['proc'])['running']) {
                proc_terminate($p['proc'], 9);
            }
            $this->finish($pid, 5.0);
        }
        $this->processes = [];
        $this->removeDir($this->dir);
    }

    /**
     * @param array<string, string> $env
     *
     * @return int pid of the started php process
     */
    protected function startScheduleRun(array $env = []): int
    {
        return $this->startArtisan(['schedule:run'], $env);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    protected function startArtisan(array $args, array $env = []): int
    {
        return $this->startProcess([PHP_BINARY, dirname(__DIR__, 2) . '/examples/laravel/artisan', ...$args, '--no-interaction'], $env);
    }

    /** @param array<string, string> $env */
    protected function startPhpScript(string $script, array $env = []): int
    {
        return $this->startProcess([PHP_BINARY, $script], $env);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     * @param string|null           $cwd     working directory, the repository root by default
     */
    protected function startProcess(array $command, array $env, ?string $cwd = null): int
    {
        $n = count($this->processes);
        $out = $this->dir . "/proc-$n.out";
        $err = $this->dir . "/proc-$n.err";
        $proc = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            $cwd ?? dirname(__DIR__, 2),
            $this->env($env),
        );
        self::assertIsResource($proc, 'could not start ' . implode(' ', $command));

        $pid = proc_get_status($proc)['pid'];
        $this->processes[$pid] = ['proc' => $proc, 'exit' => null, 'out' => $out, 'err' => $err];

        return $pid;
    }

    /** Waits for the process to end and returns its exit code. */
    protected function finish(int $pid, float $timeout = 30.0): int
    {
        $p = &$this->processes[$pid];
        if ($p['exit'] !== null) {
            return $p['exit'];
        }

        $deadline = microtime(true) + $timeout;
        while (true) {
            $status = proc_get_status($p['proc']);
            if (!$status['running']) {
                // exitcode is only reported once, so keep it
                $p['exit'] = $status['termsig'] > 0 ? 128 + $status['termsig'] : $status['exitcode'];
                proc_close($p['proc']);

                return $p['exit'];
            }
            if (microtime(true) > $deadline) {
                proc_terminate($p['proc'], 9);
                self::fail(sprintf('schedule:run (pid %d) did not finish within %.0fs', $pid, $timeout));
            }
            usleep(20_000);
        }
    }

    protected function kill(int $pid): void
    {
        proc_terminate($this->processes[$pid]['proc'], 9);
        $this->finish($pid, 5.0);
    }

    protected function stdoutOf(int $pid): string
    {
        return (string) @file_get_contents($this->processes[$pid]['out']);
    }

    protected function stderrOf(int $pid): string
    {
        return (string) @file_get_contents($this->processes[$pid]['err']);
    }

    /** @param array<string, string> $env */
    protected function runScheduleOnce(array $env = []): int
    {
        $pid = $this->startScheduleRun($env);
        $this->finish($pid);

        return $pid;
    }

    protected function exitCodeOf(int $pid): ?int
    {
        return $this->processes[$pid]['exit'];
    }

    protected function waitUntil(callable $condition, float $timeout, string $what): void
    {
        $deadline = microtime(true) + $timeout;
        while (!$condition()) {
            if (microtime(true) > $deadline) {
                self::fail("Timed out waiting for: $what");
            }
            usleep(20_000);
        }
    }

    /** @return list<array<string, mixed>> */
    protected function journal(?int $pid = null): array
    {
        $file = $this->dir . '/journal.jsonl';
        if (!is_file($file)) {
            return [];
        }

        $rows = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($row);
            if ($pid === null || $row['pid'] === $pid) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, string> task id => status, for one process */
    protected function statuses(int $pid): array
    {
        $map = [];
        foreach ($this->journal($pid) as $row) {
            self::assertArrayNotHasKey($row['task'], $map, 'task reported twice by one process');
            $map[$row['task']] = $row['status'];
        }

        return $map;
    }

    /** @return list<string> */
    protected function outputLines(): array
    {
        $file = $this->dir . '/output.log';

        return is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    }

    protected function outputContains(string $needle): bool
    {
        foreach ($this->outputLines() as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function env(array $extra): array
    {
        $env = getenv();

        return array_merge($env, [
            'SCHEDULER_DEMO_STORAGE' => $this->dir . '/storage',
            'SCHEDULER_LOCK_DIR' => $this->dir . '/locks',
            'SCHEDULER_JOURNAL' => $this->dir . '/journal.jsonl',
            'DEMO_OUTPUT' => $this->dir . '/output.log',
            'DEMO_SLOW_SECONDS' => '0.2',
            'SCHEDULER_DEMO_FAKE_NOW' => '2026-10-05T10:00:00Z',
        ], $extra);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
