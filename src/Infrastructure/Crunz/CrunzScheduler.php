<?php

declare(strict_types=1);

namespace SchedulerTest\Infrastructure\Crunz;

use Crunz\Schedule;
use SchedulerTest\Contract\Exception\DuplicateTaskIdException;
use SchedulerTest\Contract\Exception\SchedulerIntegrationException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\SchedulerInterface;

/**
 * crunz 3.9 adapter: turns each ScheduledTask into a native crunz event that starts one worker
 * process for that task id.
 *
 * crunz decides what is due (cron + timezone) in `crunz schedule:run`, then starts every due event
 * as its own shell process, in parallel, and waits for them. Our event's command is
 *
 *   exec '<php>' '<worker>' 'task:run' '<id>'
 *
 * with every part quoted for sh and for Symfony's placeholder replacement (see quote()).
 * The worker rebuilds the task from the same provider and runs it through the shared TaskExecutor,
 * which owns the mutex - nothing is serialized, no closures travel. `exec` makes the shell replace
 * itself with php, so the process crunz watches is the worker itself (no shell left in between).
 *
 * crunz's own overlap lock (preventOverlapping()) is never switched on, our FileMutex does that job.
 *
 * crunz's `schedule:run` exits with 0 even when a worker fails. It prints the worker's output
 * (our stderr message) or writes it to its error log when `log_errors` is on. The journal is the
 * reliable result, as with Laravel.
 */
final class CrunzScheduler implements SchedulerInterface
{
    /** @var array<string, true> ids that already went to the native schedule */
    private array $registered = [];

    private readonly string $workerScript;
    private readonly string $phpBinary;

    /**
     * @param string      $workerScript absolute path of the worker script (`<worker> task:run <id>`); must be an existing file
     * @param string|null $phpBinary    absolute path of the php executable that runs the worker; null = PHP_BINARY of this process
     *
     * @throws \InvalidArgumentException when a path is empty, has a NUL byte or is relative, the worker is not a file
     *                                   or the php binary is not an executable file
     */
    public function __construct(
        private readonly Schedule $schedule,
        string $workerScript,
        ?string $phpBinary = null,
    ) {
        $phpBinary ??= PHP_BINARY;

        foreach (['Worker script' => $workerScript, 'PHP binary' => $phpBinary] as $what => $path) {
            if ($path === '') {
                throw new \InvalidArgumentException("$what path must not be empty.");
            }
            if (str_contains($path, "\0")) {
                throw new \InvalidArgumentException("$what path must not contain a NUL byte.");
            }
        }

        // crunz starts the command in its own working directory, so a relative path would depend on
        // where cron happened to start crunz (and a bare "php" on cron's PATH)
        foreach (['Worker script' => $workerScript, 'PHP binary' => $phpBinary] as $what => $path) {
            if (!str_starts_with($path, '/')) {
                throw new \InvalidArgumentException(sprintf('%s path must be absolute, got "%s".', $what, $path));
            }
        }
        // both checked now, at bootstrap: later crunz would just report every run as a failed shell
        // command and still exit 0, with nothing in the journal
        if (!is_file($workerScript)) {
            throw new \InvalidArgumentException(sprintf('Worker script "%s" is not a file.', $workerScript));
        }
        if (!is_file($phpBinary) || !is_executable($phpBinary)) {
            throw new \InvalidArgumentException(sprintf('PHP binary "%s" is not an executable file.', $phpBinary));
        }

        $this->workerScript = $workerScript;
        $this->phpBinary = $phpBinary;
    }

    /**
     * @throws \SchedulerTest\Contract\Exception\InvalidCronExpressionException when the expression is rejected or can never match
     * @throws DuplicateTaskIdException                                          when the id was already registered here
     * @throws SchedulerIntegrationException                                     when crunz fails to create the event
     */
    public function register(ScheduledTask $task): void
    {
        if (isset($this->registered[$task->id])) {
            throw DuplicateTaskIdException::forId($task->id);
        }

        // the very string checked here is the one crunz gets below
        $expression = NativeCron::numericFor($task);

        // the id is taken from here on, even if crunz fails below
        $this->registered[$task->id] = true;
        $eventsBefore = $this->schedule->events();

        try {
            // The whole command line is built here and run() gets no parameters: crunz would quote
            // them with Symfony's plain single quoting, which isn't enough (see quote()).
            $this->schedule
                ->run(self::commandLine($this->phpBinary, $this->workerScript, $task->id))
                ->cron($expression)
                // the name, not the object: crunz's task:debug only uses a string timezone for its next run dates
                ->timezone($task->timezone->getName())
                ->description($task->id);
        } catch (\Throwable $e) {
            // crunz lets us put the event list back, so no half-built event stays behind
            $this->schedule->events($eventsBefore);

            throw new SchedulerIntegrationException(
                sprintf('Task "%s": crunz failed to register the event: %s', $task->id, $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * `exec '<php>' '<worker>' 'task:run' '<id>'`, every part a literal argument for sh.
     */
    private static function commandLine(string $php, string $worker, string $taskId): string
    {
        return 'exec ' . implode(' ', array_map(self::quote(...), [$php, $worker, 'task:run', $taskId]));
    }

    /**
     * POSIX single quoting with every `$` moved outside the quotes as `\$`.
     *
     * Why not plain single quotes (escapeArgument() in Symfony, escapeshellarg()): crunz runs the
     * command through Symfony Process::fromShellCommandline(), and Process::start() first replaces
     * every `"${:NAME}"` in the command line with the env var NAME (replacePlaceholders(), symfony/process
     * 7.4) - inside single quotes too. A path like `a "${:X}" b` would then change, or fail when X is
     * not set. With `$` written as `'\$'` the pattern can't appear; sh still sees the same literal text.
     * escapeshellarg() is also locale dependent and may drop non-ASCII bytes.
     */
    private static function quote(string $value): string
    {
        return "'" . str_replace(["'", '$'], ["'\\''", "'\\$'"], $value) . "'";
    }
}
