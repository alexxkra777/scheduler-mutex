# API reference (v1)

This is the single description of the public API. Code lives in `src/Contract/`.
Nothing in this namespace depends on Laravel, crunz or any other library.

```
SchedulerTest\Contract
├── TaskInterface               execute(): void
├── ScheduledTask               immutable schedule entry
├── ScheduleProviderInterface   tasks(): iterable<ScheduledTask>
├── SchedulerInterface          register(ScheduledTask): void
├── MutexInterface              tryAcquire(string): ?LockInterface
├── LockInterface               release(): void
└── Exception\…                 our own exception types
```

## TaskInterface

```php
interface TaskInterface
{
    /** @throws \Throwable when the work failed */
    public function execute(): void;
}
```

- No arguments, no return value. Inputs (services, ids, settings) go into the task's constructor.
- Returning normally = success. Any `Throwable` = failure. `false` is not a failure signal.
- Synchronous: when `execute()` returns, the work is over. Starting a detached process or pushing
  a queue message inside `execute()` is allowed, but the mutex then protects only the dispatch,
  not the work done elsewhere.
- Calling `execute()` yourself gives no lock protection. Protected runs go through the executor.
- Not supported inside a protected task: `pcntl_fork()` without `exec`. The forked child shares the lock's
  descriptor. `FileLock` refuses to unlock from the child (it throws `MutexReleaseException`), but a child that
  outlives a killed parent keeps the lock held until it exits. Starting external commands is fine.

## ScheduledTask

```php
final readonly class ScheduledTask
{
    public function __construct(
        string $id,
        TaskInterface $task,
        string $cronExpression,
        \DateTimeZone $timezone = new \DateTimeZone('UTC'),
        bool $preventOverlap = true,
    );

    public string $id;
    public TaskInterface $task;
    public string $cronExpression;   // whitespace-normalised
    public \DateTimeZone $timezone;
    public bool $preventOverlap;
}
```

| Parameter | Type / default | Rules |
|---|---|---|
| `id` | `string` | `^[a-z0-9][a-z0-9._-]{0,127}$`. Stable across deploys – it is part of the lock key and the name in logs. No colon. |
| `task` | `TaskInterface` | The same task object may be used by several entries; different ids mean different locks. |
| `cronExpression` | `string` | Five fields, dialect below. Runs of whitespace become one space. |
| `timezone` | `DateTimeZone`, default `UTC` | Wall clock the expression is read in. The system timezone is never used implicitly. |
| `preventOverlap` | `bool`, default `true` | `true`: the executor holds the mutex for this id while the task runs. `false`: no mutex call at all. |

**Cron dialect v1** – `minute hour day-of-month month day-of-week`:

- `*`, numbers, lists `1,15`, ranges `9-17`, steps on `*` or a range: `*/5`, `0-30/10`;
- month names `JAN`–`DEC` (field 4) and day names `SUN`–`SAT` (field 5), any letter case, also in ranges
  and with steps: `MON-FRI/2`, `JAN-DEC/3`;
- day of week `0`–`7`, both `0` and `7` are Sunday; a named range may end on Sunday: `MON-SUN`, `FRI-SUN`;
- if both day-of-month and day-of-week are restricted, either one matching is enough (classic cron OR rule).
  An explicit full range like `SUN-SAT` still counts as restricted – only `*` means "unrestricted";
- a day field that starts with `*` but is not plain `*` (`*/2`, `*,5`) is rejected when the other day field is
  restricted: Laravel/crunz would OR the two fields, classic cron (cronie) ANDs them. Write explicit values
  instead (`0 0 13 * 0,2,4,6`). With the other day field `*` it is fine (`0 0 * * */2`);
- a step must be a whole number from 1 to the field's maximum value: minute 59, hour 23, day of month 31,
  month 12, day of week 7. This is a v1 limit, not system cron: `*/60` or `*/61` in the minute field is rejected
  instead of being wrapped around. A step longer than its range is fine and selects the range start
  (`10-20/30` = minute 10).

Rejected: macros (`@daily`), a 6th field (seconds/year), a command part, the parser extensions
`L`, `W`, `#`, `?`, a step on a single value (`5/10`) and ranges that go backwards (`FRI-MON`, `5-1`).
Day-of-week values above 7 and out-of-range minute/hour endpoints in stepped items (`10-70/59`,
`1-24/23`) are rejected by the constructor too: expanding them must not hide invalid input. Other value
ranges (a plain minute 61, day 32, month 13) and dates that can never happen (`0 0 31 2 *`) are checked by
the backend's cron parser when the task is registered.

`ScheduledTask::$cronExpression` keeps the expression as written. Adapters register its plain numeric
form, built by `SchedulerTest\Cron\CronDialect::toNumeric()`, and validate that very string with the backend
parser. Names become numbers (`JAN-DEC/2` → `1-12/2`); the day-of-week field becomes an explicit list with
Sunday as `0` (`MON-FRI/2` → `1,3,5`, `MON-SUN` → `0,1,2,3,4,5,6`, `7-7` → `0`), except a plain `*` or `*/n`.
Minute/hour fields containing a stepped item also become explicit lists (`*/59` → `0,59`,
`10-20/59` → `10`, `1-2/23` in the hour field → `1`); mixed lists keep the union of their values.
Reason: dragonmantank/cron-expression 3.x, used by Laravel 12 and crunz, mishandles names inside stepped ranges
(`MON-FRI/2` ran on Sundays), rewrites day ranges touching 7 (`7-7`, `SUN-SUN` ran every day), rejects some valid
ones (`SUN-SAT/7`), and disagrees between due checks and next-date searches for minute/hour steps equal
to the field maximum (`*/59`, `10-20/59`, `1-2/23`). An explicit day list stays "restricted", so the OR rule
above is unchanged. `CronDialect` only translates syntax – whether a minute is due is decided by the backend.

Errors from the constructor:

| Case | Exception |
|---|---|
| bad id, empty expression, not exactly 5 fields | `InvalidTaskDefinitionException` |
| syntax outside the dialect, a step out of 1..max, a backwards range, a day-of-week value above 7, or an out-of-range endpoint in a stepped minute/hour item | `InvalidCronExpressionException` |
| unknown timezone name | PHP's own `\Exception` from `new DateTimeZone(...)`, before our code runs |

## ScheduleProviderInterface

```php
interface ScheduleProviderInterface
{
    /** @return iterable<ScheduledTask> */
    public function tasks(): iterable;
}
```

- Returns the complete, finite task set for the current configuration. A new generator per call is fine.
- Called by every process that needs the schedule. Task objects and closures never travel between processes.
- Must not write or publish anything. Building task dependencies is fine.
- Exceptions are wrapped by the loader into `ScheduleConfigurationException`.

## ScheduleLoader (bootstrap helper)

```php
final class SchedulerTest\Bootstrap\ScheduleLoader
{
    /** @return list<ScheduledTask> */
    public function load(ScheduleProviderInterface $provider): array;
    public function registerAll(ScheduleProviderInterface $provider, SchedulerInterface $scheduler): void;
}
```

`load()` reads the provider once and checks the whole set before anything is registered:
non-`ScheduledTask` element or provider failure → `ScheduleConfigurationException` (cause kept as
`previous`); repeated id → `DuplicateTaskIdException`. `registerAll()` then calls `register()` for
each task in order and lets its exceptions escape, so a backend that rejects one entry makes the
whole bootstrap fail instead of running a partial schedule.

## SchedulerInterface

```php
interface SchedulerInterface
{
    public function register(ScheduledTask $task): void;
}
```

- Adds one task to the native schedule **of the current process**. Nothing runs immediately, nothing is
  written to a database or the system crontab.
- Returns nothing – no library event object or fluent API leaks out.
- Each new process registers again (that is how Laravel's `schedule:run` works anyway).

| Case | Exception |
|---|---|
| backend parser rejects the expression (e.g. `61 * * * *`, `0 0 1 13 *`) or it can never match (`0 0 31 2 *`) – checked before a native event exists. Dialect errors such as `*/0` are already refused by the `ScheduledTask` constructor | `InvalidCronExpressionException` |
| the same id was already registered through this scheduler | `DuplicateTaskIdException` |
| the backend fails while creating the event | `SchedulerIntegrationException` (cause as `previous`) |

The adapter sets cron, timezone and a readable name (the id) on the native event, and its action calls
the shared executor. The backend's own overlap lock is **not** used.

## MutexInterface and LockInterface

```php
interface MutexInterface
{
    public function tryAcquire(string $key): ?LockInterface;
}

interface LockInterface
{
    public function release(): void;
}
```

`tryAcquire(string $key)`:

| Situation | Result |
|---|---|
| key free | takes the lock atomically, returns a new `LockInterface` |
| key held by anyone (another process **or** a live handle in this process) | returns `null` immediately – no waiting, no retry |
| key not 1–256 chars of `[A-Za-z0-9._:-]` | `\InvalidArgumentException` |
| backend / file system failure | `MutexException` – never reported as `null` |

`release()`:

- frees only the ownership this handle represents; there is no release-by-key and no force release;
- after a successful release further calls do nothing; an old handle can never free a lock taken later by someone else;
- failure → `MutexReleaseException` (extends `MutexException`); the state is then unknown, an explicit
  retry on the same handle is allowed and stays owner-safe;
- the handle cannot be cloned or serialized and is never given to task code;
- only the process that acquired the lock may release it; `release()` in another process (a fork) throws
  `MutexReleaseException` and leaves the owner's lock alone.

There is no TTL. A lock lives until `release()` or until the owning process ends (provided the task
didn't fork without exec, see `TaskInterface`). A backend with
expiring leases (Redis with TTL and similar) gives weaker guarantees and would need a new version of
this contract, not a silent swap.

## Exceptions

| Class (`SchedulerTest\Contract\Exception\`) | Extends |
|---|---|
| `InvalidTaskDefinitionException` | `\InvalidArgumentException` |
| `InvalidCronExpressionException` | `\InvalidArgumentException` |
| `DuplicateTaskIdException` | `\InvalidArgumentException` |
| `ScheduleConfigurationException` | `\RuntimeException` |
| `SchedulerIntegrationException` | `\RuntimeException` |
| `MutexException` | `\RuntimeException` |
| `MutexReleaseException` | `MutexException` |

None of them extends a library class.

## Implementations shipped (infrastructure, not contract)

### TaskExecutor – `SchedulerTest\Execution`

```php
final class TaskExecutor
{
    public function __construct(
        MutexInterface $mutex,
        string $application,      // 1-48 chars [a-zA-Z0-9._-]
        string $environment,      // 1-48 chars [a-zA-Z0-9._-]
        ?ExecutionReporterInterface $reporter = null,
    );

    public function execute(ScheduledTask $task): void;
}
```

The one place where scheduled work actually runs. Adapters get `$executor->execute(...)` as a
`Closure(ScheduledTask): void`.

Lock key: `scheduler:<application>:<environment>:<task-id>`. None of the parts may contain `:`, so the
key splits back unambiguously.

| Outcome | Status in journal | `execute()` |
|---|---|---|
| task finished | `success` | returns |
| lock busy – task not called | `skipped_locked` | returns |
| task threw | `failed_task` | throws `TaskExecutionException` (previous = task error) |
| `tryAcquire()` failed – task not called, nothing to release | `failed_mutex` | throws `TaskExecutionException` (previous = mutex error) |
| task ok, `release()` failed | `failed_cleanup` | throws `TaskExecutionException` (previous = release error) |
| task threw **and** `release()` failed | `failed_task` | throws; previous = task error, `getCleanupError()` = release error |

`release()` is called exactly once per acquired lock, in a `finally` path, before the reporter.
A failing reporter never prevents the release and doesn't change the outcome; its error goes to PHP's `error_log()`.

Reporters: `NullReporter`, `JsonLinesReporter(string $file)` – one JSON line per attempt with
`time`, `task`, `status`, `duration_ms`, `pid`, `error`, `cleanup_error`. Task parameters are never logged.
The constructor throws `\InvalidArgumentException` for an empty path or one containing a NUL byte and touches
nothing on disk; the directory and file are created on the first `report()`, which throws `\RuntimeException`
when they cannot be written. A relative path is resolved against the working directory at that moment.

### FileMutex – `SchedulerTest\Infrastructure\Mutex`

```php
final class FileMutex implements MutexInterface
{
    public function __construct(string $directory);
}
```

`flock(LOCK_EX | LOCK_NB)` on one file per key (`sha256(key).lock`) in `$directory`. The constructor throws
`\InvalidArgumentException` for an empty path or one containing a NUL byte and touches nothing on disk; the
directory is created on the first `tryAcquire()` (a relative path is resolved against the working directory
at that moment), and failures there are `MutexException`.
The handle keeps the descriptor open in the process that runs the task; the file is opened close-on-exec, so
commands a task starts (`exec`, `proc_open`) don't inherit the lock. Lock files are never deleted.
If the process dies – even with `SIGKILL` – the kernel drops the lock with the descriptor.

Fork: `FileLock` remembers the pid that acquired it and never unlocks from another pid. A child forked with
`pcntl_fork()` therefore cannot free the parent's lock by `release()`, by the destructor or by exiting (checked
with real forks). It still shares the descriptor: if the parent is killed while such a child lives, the lock
stays held until the child exits. Fork without exec inside a protected task is unsupported.

Scope: separate processes on **one host** sharing a **local** file system. Not for several servers and not
for network file systems.

### LaravelScheduler – `SchedulerTest\Infrastructure\Laravel`

```php
final class LaravelScheduler implements SchedulerInterface
{
    /** @param \Closure(ScheduledTask): void $runner */
    public function __construct(\Illuminate\Console\Scheduling\Schedule $schedule, \Closure $runner);
}
```

Registers each task as `$schedule->call(...)->cron(<numeric form>)->timezone(...)->name(<id>)`. It validates the
numeric form with `Cron\CronExpression` (the parser Laravel itself uses, including `getNextRunDate()` for
never-matching dates) before creating the event and never calls
`withoutOverlapping()`, `onOneServer()`, `runInBackground()` or `evenInMaintenanceMode()`. While the Laravel
app is in maintenance mode, tasks are not due (Laravel's default).
If such attributes or `when()`/`skip()` filters sneak in anyway (e.g. `register()` called inside `Schedule::group()`), registration fails with
`SchedulerIntegrationException`. Laravel's `Schedule` has no remove API, so after that exception the schedule
may hold a half-built event: let the exception escape the bootstrap – `artisan` then fails before any event runs.
Where to register: Laravel calls `withSchedule()` for every artisan command, so one broken task definition
would break `migrate`, `down` and friends. Register in `$app->extend(Schedule::class, ...)` instead, with a
callback returning the completed `Schedule` (see the [README recipe](../README.md#how-it-fits-together)).
The container runs this hook before caching the singleton. If loading or registration throws, the failed
schedule is not cached: a repeated `Artisan::call('schedule:run')` in the same application fails again and
runs nothing. After a successful build, repeated calls reuse the same four demo events without duplicates
and run the due tasks on every call; the mutex does not deduplicate periods.

The hook does not depend on command-line option order (`artisan --env=prod -v schedule:run`). In the demo,
only schedule commands resolve `Schedule`. If your own code resolves it on every command (e.g. the `Schedule`
facade in `routes/console.php`), the schedule is built there too. Do not use an `afterResolving` callback for
registration: it runs after singleton caching, so a failed build can leave a partial schedule behind.

### CrunzScheduler – `SchedulerTest\Infrastructure\Crunz`

```php
final class CrunzScheduler implements SchedulerInterface
{
    public function __construct(\Crunz\Schedule $schedule, string $workerScript, ?string $phpBinary = null);
}
```

| Parameter | Rules |
|---|---|
| `$schedule` | the `Crunz\Schedule` the crunz task file returns |
| `$workerScript` | absolute path of an existing file; it is called as `<php> <worker> task:run <id>` |
| `$phpBinary` | absolute path of an executable php, default `PHP_BINARY` of the process that registers |

Constructor errors (`\InvalidArgumentException`): empty path, NUL byte, relative path, worker not a file, php not
an executable file. Checked at bootstrap because crunz would otherwise report every run as a failed command and
still exit 0.

`register()` creates `$schedule->run("exec '<php>' '<worker>' 'task:run' '<id>'")` with the numeric cron,
the task's timezone name and the id as description. The numeric form is validated first with the parser crunz
uses (`Cron\CronExpression`, including `getNextRunDate()`); errors are the ones in the `SchedulerInterface`
table: `InvalidCronExpressionException`, `DuplicateTaskIdException`, `SchedulerIntegrationException` (the
event list is then put back, no half-built event stays). `preventOverlapping()` is never called.
Every part of the command is single-quoted with `$` written as `\$` outside the quotes, so neither the shell nor
Symfony Process's `"${:NAME}"` placeholder replacement (applied by crunz before the shell) changes a path.

### WorkerCommand – `SchedulerTest\Infrastructure\Crunz`

```php
final class WorkerCommand
{
    /**
     * @param \Closure(): ScheduleProviderInterface $provider
     * @param \Closure(): TaskExecutor              $executor
     */
    public function __construct(\Closure $provider, \Closure $executor);

    /** @param list<string> $arguments argv without the script name; @param resource $stderr */
    public function run(array $arguments, $stderr): int;
}
```

The body of a crunz worker script: `exit($command->run(array_slice($argv, 1), STDERR));`. Accepts exactly
`task:run <id>`. Arguments are checked before either factory is called. Then the provider is loaded with
`ScheduleLoader::load()` and every task's cron is checked like in `CrunzScheduler`; only then the task is looked
up and run with `TaskExecutor::execute()`. The worker never checks whether the task is due.

| Exit | Meaning | Task ran? |
|---|---|---|
| `0` | `success` or `skipped_locked` | yes / no (lock busy) |
| `1` | `TaskExecutionException`: `failed_task`, `failed_mutex` or `failed_cleanup` | as recorded in the journal |
| `2` | wrong arguments or unknown id | no |
| `3` | configuration error: provider failure, wrong element, duplicate id, rejected cron, bad executor settings | no |

Every non-zero exit writes one line `scheduler-worker: ...` to `$stderr`.
