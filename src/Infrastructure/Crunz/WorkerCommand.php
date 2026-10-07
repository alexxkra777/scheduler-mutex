<?php

declare(strict_types=1);

namespace SchedulerTest\Infrastructure\Crunz;

use SchedulerTest\Bootstrap\ScheduleLoader;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\ScheduleProviderInterface;
use SchedulerTest\Execution\TaskExecutionException;
use SchedulerTest\Execution\TaskExecutor;

/**
 * `<worker> task:run <id>` - the process a crunz event starts for one due task.
 *
 * It loads the full set from the application's provider (the same one the crunz task file
 * registered), checks it the same way, picks the task by id and runs it through the shared
 * TaskExecutor. So the worker itself takes the mutex and holds it until the synchronous task is
 * done; the crunz parent never touches the lock.
 *
 * It does NOT ask whether the task is due: crunz already decided that, and the worker may start
 * a bit late. Re-checking would turn a late start into a silently skipped run.
 *
 * Exit codes:
 *   0  success, or skipped because the lock was busy (`skipped_locked` in the journal)
 *   1  the run failed: task error, mutex error or lock release error (TaskExecutionException)
 *   2  wrong arguments or unknown task id - nothing was run
 *   3  configuration or bootstrap failure (provider, duplicate id, bad cron, bad settings) - nothing was run
 * Every non-zero exit writes one line to stderr. crunz shows it (or logs it with `log_errors`),
 * but crunz's own exit code stays 0 - read the journal for the outcome.
 */
final class WorkerCommand
{
    public const EXIT_OK = 0;
    public const EXIT_TASK_FAILED = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_CONFIGURATION = 3;

    private const USAGE = 'usage: <worker> task:run <task-id>';

    /**
     * Both factories are called only after the arguments were checked.
     *
     * @param \Closure(): ScheduleProviderInterface $provider the application's provider, the same one the task file uses
     * @param \Closure(): TaskExecutor              $executor the application's shared executor (mutex, lock key parts, journal)
     */
    public function __construct(
        private readonly \Closure $provider,
        private readonly \Closure $executor,
    ) {
    }

    /**
     * @param list<string> $arguments command-line arguments without the script name
     * @param resource     $stderr    where error lines go
     *
     * @return int exit code, see the class doc
     */
    public function run(array $arguments, $stderr): int
    {
        if (count($arguments) !== 2 || $arguments[0] !== 'task:run' || $arguments[1] === '') {
            self::say($stderr, sprintf('wrong arguments %s; %s', self::show($arguments), self::USAGE));

            return self::EXIT_USAGE;
        }
        $id = $arguments[1];

        try {
            $tasks = (new ScheduleLoader())->load(($this->provider)());
            // the same cron checks the task file ran - a set crunz could not load is not run here either
            foreach ($tasks as $task) {
                NativeCron::numericFor($task);
            }
            $executor = ($this->executor)();
        } catch (\Throwable $e) {
            self::say($stderr, sprintf('configuration error, nothing was run: %s: %s', $e::class, $e->getMessage()));

            return self::EXIT_CONFIGURATION;
        }

        $task = self::find($tasks, $id);
        if ($task === null) {
            self::say($stderr, sprintf('unknown task id %s, nothing was run', self::show($id)));

            return self::EXIT_USAGE;
        }

        try {
            $executor->execute($task);
        } catch (TaskExecutionException $e) {
            // the message already names the task, the status and the cause
            self::say($stderr, $e->getMessage());

            return self::EXIT_TASK_FAILED;
        } catch (\Throwable $e) {
            // TaskExecutor reports everything as TaskExecutionException; this is just the safety net
            self::say($stderr, sprintf('task "%s" unexpected error: %s: %s', $id, $e::class, $e->getMessage()));

            return self::EXIT_CONFIGURATION;
        }

        return self::EXIT_OK;
    }

    /**
     * @param list<ScheduledTask> $tasks
     */
    private static function find(array $tasks, string $id): ?ScheduledTask
    {
        foreach ($tasks as $task) {
            if ($task->id === $id) {
                return $task;
            }
        }

        return null;
    }

    private static function show(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param resource $stderr
     */
    private static function say($stderr, string $message): void
    {
        // one line per error, whatever the exception message contains
        fwrite($stderr, 'scheduler-worker: ' . str_replace(["\r", "\n"], ' ', $message) . "\n");
    }
}
