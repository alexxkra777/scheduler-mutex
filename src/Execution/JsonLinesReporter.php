<?php

declare(strict_types=1);

namespace SchedulerTest\Execution;

/**
 * Appends one JSON object per attempt to a file:
 *
 *   {"time":"2026-10-03T12:00:00.123Z","task":"report.daily","status":"success",
 *    "duration_ms":12.5,"pid":4242,"error":null,"cleanup_error":null}
 *
 * Errors are written as {"class": ..., "message": ...}. Task parameters are never
 * written - the task object itself is not looked at.
 */
final class JsonLinesReporter implements ExecutionReporterInterface
{
    /**
     * Nothing is created here; the directory and the file appear on the first report().
     * A relative path is resolved against the working directory at that moment.
     *
     * @throws \InvalidArgumentException when the path is empty or contains a NUL byte
     */
    public function __construct(private readonly string $file)
    {
        if ($file === '') {
            throw new \InvalidArgumentException('Journal file path must not be empty.');
        }
        // file_put_contents() would throw a raw ValueError later, catch the config mistake here
        if (str_contains($file, "\0")) {
            throw new \InvalidArgumentException('Journal file path must not contain a NUL byte.');
        }
    }

    /**
     * @throws \RuntimeException when the line cannot be written
     */
    public function report(ExecutionResult $result): void
    {
        $line = json_encode([
            'time' => $result->startedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'task' => $result->taskId,
            'status' => $result->status->value,
            'duration_ms' => round($result->durationMs, 3),
            'pid' => getmypid(),
            'error' => self::describe($result->error),
            'cleanup_error' => self::describe($result->cleanupError),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        $dir = dirname($this->file);
        // the second is_dir() covers another process creating it at the same moment
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create journal directory "%s": %s', $dir, self::lastError()));
        }

        if (@file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Cannot write journal "%s": %s', $this->file, self::lastError()));
        }
    }

    /**
     * @return array{class: class-string, message: string}|null
     */
    private static function describe(?\Throwable $error): ?array
    {
        return $error === null ? null : ['class' => $error::class, 'message' => $error->getMessage()];
    }

    private static function lastError(): string
    {
        return error_get_last()['message'] ?? 'unknown error';
    }
}
