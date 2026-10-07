<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Execution;

use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\Exception\MutexReleaseException;
use SchedulerTest\Execution\ExecutionResult;
use SchedulerTest\Execution\ExecutionStatus;
use SchedulerTest\Execution\JsonLinesReporter;

final class JsonLinesReporterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/exec-journal-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
        rmdir($this->dir);
    }

    public function testWritesOneJsonObjectPerLineIntoNewDirectory(): void
    {
        $file = $this->dir . '/nested/journal.jsonl';
        $reporter = new JsonLinesReporter($file);
        $started = new \DateTimeImmutable('2026-10-03 12:00:00.123456', new \DateTimeZone('UTC'));

        $reporter->report(new ExecutionResult('report.daily', ExecutionStatus::SUCCESS, $started, 12.34567));
        $reporter->report(new ExecutionResult(
            'sync.users',
            ExecutionStatus::FAILED_TASK,
            $started,
            5.0,
            new \RuntimeException('task broke'),
            new MutexReleaseException('unlock failed'),
        ));
        $reporter->report(new ExecutionResult('report.daily', ExecutionStatus::SKIPPED_LOCKED, $started, 0.1));

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(3, $lines);
        self::assertStringEndsWith("\n", (string) file_get_contents($file));

        $rows = array_map(static fn (string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);

        self::assertSame([
            'time' => '2026-10-03T12:00:00.123Z',
            'task' => 'report.daily',
            'status' => 'success',
            'duration_ms' => 12.346,
            'pid' => getmypid(),
            'error' => null,
            'cleanup_error' => null,
        ], $rows[0]);

        self::assertSame('failed_task', $rows[1]['status']);
        self::assertSame(['class' => \RuntimeException::class, 'message' => 'task broke'], $rows[1]['error']);
        self::assertSame(['class' => MutexReleaseException::class, 'message' => 'unlock failed'], $rows[1]['cleanup_error']);
        self::assertSame('skipped_locked', $rows[2]['status']);
    }

    public function testTimeIsWrittenInUtc(): void
    {
        $file = $this->dir . '/journal.jsonl';
        $started = new \DateTimeImmutable('2026-10-03 14:00:00.5', new \DateTimeZone('Europe/Prague'));

        (new JsonLinesReporter($file))->report(new ExecutionResult('a', ExecutionStatus::SUCCESS, $started, 1.0));

        $row = json_decode(trim((string) file_get_contents($file)), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($row);
        self::assertSame('2026-10-03T12:00:00.500Z', $row['time']);
    }

    public function testAppendsToExistingFile(): void
    {
        mkdir($this->dir);
        $file = $this->dir . '/journal.jsonl';
        file_put_contents($file, "{\"old\":true}\n");

        (new JsonLinesReporter($file))->report(new ExecutionResult('a', ExecutionStatus::SUCCESS, new \DateTimeImmutable(), 1.0));

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        self::assertSame('{"old":true}', $lines[0]);
    }

    public function testThrowsWhenFileCannotBeWritten(): void
    {
        mkdir($this->dir);
        // the target path is a directory, so appending to it fails
        $file = $this->dir . '/journal.jsonl';
        mkdir($file);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot write journal');
        (new JsonLinesReporter($file))->report(new ExecutionResult('a', ExecutionStatus::SUCCESS, new \DateTimeImmutable(), 1.0));
    }

    public function testThrowsWhenDirectoryCannotBeCreated(): void
    {
        mkdir($this->dir);
        // a regular file sits where the directory should be
        file_put_contents($this->dir . '/blocker', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot create journal directory');
        (new JsonLinesReporter($this->dir . '/blocker/sub/journal.jsonl'))
            ->report(new ExecutionResult('a', ExecutionStatus::SUCCESS, new \DateTimeImmutable(), 1.0));
    }

    public function testRejectsEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JsonLinesReporter('');
    }

    // file_put_contents() throws a raw ValueError on NUL - the constructor has to stop it first
    public function testRejectsPathWithNulByte(): void
    {
        foreach ([$this->dir . "/journal\0.jsonl", $this->dir . "\0/journal.jsonl", "\0"] as $path) {
            try {
                new JsonLinesReporter($path);
                self::fail('Expected InvalidArgumentException for ' . json_encode($path));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('NUL', $e->getMessage());
            }
        }

        self::assertDirectoryDoesNotExist($this->dir);
    }

    public function testRelativePathWithSpacesIsAccepted(): void
    {
        mkdir($this->dir);
        $cwd = getcwd();
        self::assertIsString($cwd);
        chdir($this->dir);

        try {
            (new JsonLinesReporter('logs dir/"quoted" journal.jsonl'))
                ->report(new ExecutionResult('a', ExecutionStatus::SUCCESS, new \DateTimeImmutable(), 1.0));
        } finally {
            chdir($cwd);
        }

        self::assertFileExists($this->dir . '/logs dir/"quoted" journal.jsonl');
    }
}
