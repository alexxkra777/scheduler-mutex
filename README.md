# Scheduler & Mutex

Library-neutral task scheduling with Laravel 12 and crunz adapters and a custom file mutex.

**PHP 8.2+ · Laravel 12 · crunz 3.9 · PHPStan level 8**

[![CI](https://github.com/alexxkra777/scheduler-mutex/actions/workflows/ci.yml/badge.svg)](https://github.com/alexxkra777/scheduler-mutex/actions/workflows/ci.yml)

**Problem.** The application should schedule cron jobs through Laravel Scheduler today, without being tied
to it – switching to [crunz](https://github.com/crunzphp/crunz) later must stay possible – and it needs its
own mutex instead of Laravel's.

**Result.** A small Composer module:

- our own typed interfaces for tasks, the scheduler and the mutex (`src/Contract`, no third-party types);
- a working **Laravel 12** adapter – Laravel's own `schedule:run` decides what is due;
- a working **crunz 3.9** adapter – `crunz schedule:run` decides, a worker process runs each due task;
- one shared executor that runs every task under **our own mutex** (`flock`-based `FileMutex`);
- two demos (Laravel and crunz) over the very same tasks, 698 tests and a CI workflow.

| Requirement (from the assignment) | Where | Evidence |
|---|---|---|
| Own detailed interfaces for Scheduler and Mutex, with parameter types | `src/Contract`, [docs/api.md](docs/api.md) | `tests/Contract` (incl. a check that no Laravel/crunz/Symfony type leaks in) |
| Implementation swappable, system not bound to Laravel | only `src/Infrastructure/Laravel` knows Laravel | tasks and provider in `examples/tasks` import our contracts only |
| Laravel Scheduler used now | `LaravelScheduler` + `examples/laravel` | `tests/Laravel` and `tests/Integration` run the real `schedule:run` |
| Open path to crunz | `CrunzScheduler` + worker + `examples/crunz`, [docs/portability.md](docs/portability.md) | `tests/Crunz` runs the real `crunz schedule:run` over the same tasks, executor and mutex, also against a parallel Laravel run |
| Own mutex instead of Laravel's | `FileMutex`, `TaskExecutor` | `tests/Mutex` (separate processes, races, `SIGKILL`, fork), recording fakes prove Laravel's mutex is never touched |
| Plan and run jobs by crontab expressions | `ScheduledTask`, `CronDialect` | `tests/Laravel/CronDialectRunTest`: due / not due per expression through the real runner |

## Requirements

| | |
|---|---|
| PHP | **8.2 or newer** (checked on 8.2 and 8.5) |
| PHP extensions | the ones Laravel 12 needs; `pcntl` and `posix` for the test suite (process and fork tests) |
| Composer | 2.x |
| OS | POSIX (Linux, macOS) – `FileMutex` relies on `flock()` on a local file system; Windows is not supported |
| Laravel | `laravel/framework ^12` (a dev dependency here; a real app already has it) |
| crunz | `crunzphp/crunz ^3.9.4` – only for the crunz adapter (a dev dependency here) |

PHP 8.2 is our own choice as the lower bound, to keep the module usable on older but still supported
runtimes. `composer.json` pins dependency resolution to PHP 8.2 (`config.platform.php`), so the committed
`composer.lock` installs on 8.2 as well as on 8.5.

## Quick start

```bash
composer install
composer check                                   # PHPStan level 8 + all 698 PHPUnit tests

php examples/laravel/artisan schedule:list       # the demo schedule
php examples/laravel/artisan schedule:run        # run what is due now (takes ~5 s, see slow-report)

cat examples/storage/logs/scheduler.jsonl        # journal: one line per attempt
cat examples/storage/demo-output.log             # what the tasks wrote
```

The same tasks with crunz instead of Laravel – crunz reads `crunz.yml` from the directory it starts in:

```bash
cd examples/crunz
php ../../vendor/bin/crunz schedule:list         # same ids, numeric cron, the worker command per task
php ../../vendor/bin/crunz schedule:run          # one worker process per due task, in parallel
php ../../vendor/bin/crunz task:debug 4          # next runs of new-year-greeting, in Europe/Prague
cd ../..
```

Both demos write the same journal and use the same lock files (`examples/storage`).

No PHP 8.2 at hand? The same checks in a throwaway container, from a clean checkout:

```bash
git archive HEAD | docker build --build-arg PHP_VERSION=8.2 -t scheduler-test:8.2 -f docker/Dockerfile -
docker run --rm scheduler-test:8.2               # validate, install from lock, platform check, composer check
```

### What you should see

The demo schedule (`examples/tasks/DemoScheduleProvider.php`):

| id | cron | timezone | what it does | on a normal `schedule:run` |
|---|---|---|---|---|
| `heartbeat` | `* * * * *` | UTC | writes one line | `success` |
| `failing-task` | `* * * * *` | UTC | throws on purpose | `failed_task` – shows how a failure looks |
| `slow-report` | `* * * * *` | UTC | sleeps `DEMO_SLOW_SECONDS` (5) between two lines | `success` |
| `new-year-greeting` | `0 0 1 JAN *` | Europe/Prague | writes one line | not due, no journal line |

```json
{"time":"2026-10-05T10:00:00.123Z","task":"failing-task","status":"failed_task","duration_ms":0.4,"pid":4242,"error":{"class":"RuntimeException","message":"Demo failure: the upstream API did not answer."},"cleanup_error":null}
```

**`schedule:run` exits with 0 even though `failing-task` failed**, and its console line says `DONE`. That is
Laravel's behaviour: it catches the task's exception, reports it (`ScheduledTaskFailed` event + exception
handler, here printed to stderr) and continues with the next task. So neither the exit code nor the console
output tells you that all tasks succeeded – the journal does. Statuses: `success`, `skipped_locked`,
`failed_task`, `failed_mutex`, `failed_cleanup`; `time` is the start of the attempt.

Try the mutex: run `php examples/laravel/artisan schedule:run` in two terminals a second apart. The second
process logs `slow-report` as `skipped_locked`; `heartbeat` and `failing-task` run in both. It works across
backends too: start the crunz demo in one terminal and the Laravel one in the other.

crunz behaves the same way here: it exits with 0 when a worker fails and prints the worker's stderr line
(`scheduler-worker: Task "failing-task" failed (failed_task): ...`). Started outside `examples/crunz` it
finds no config and no tasks, prints `No task found!` and also exits with 0 – so the cron line must `cd` first.

Demo settings (environment variables, all optional, read by both demos through
[examples/app/DemoApplication.php](examples/app/DemoApplication.php); relative paths are taken from the project
root, so both backends resolve them the same way): `SCHEDULER_APP`, `SCHEDULER_ENV`,
`SCHEDULER_LOCK_DIR`, `SCHEDULER_JOURNAL`, `SCHEDULER_DEMO_STORAGE`, `DEMO_OUTPUT`, `DEMO_SLOW_SECONDS`, and
`DEMO_INCLUDE_BROKEN_TASK=1`, which adds a task with an invalid cron. `DEMO_PROVIDER_FAILS=1` simulates a
configuration source failure. In either case, every command that needs the schedule
(`schedule:run`, `schedule:list`, `schedule:test`, `schedule:clear-cache`; for crunz `schedule:run`,
`schedule:list`, `task:debug`) then fails before any task runs; other artisan commands keep working.
`SCHEDULER_DEMO_FAKE_NOW=2026-12-31T23:00:00Z` freezes the backend's clock – a test hook of the demos only
(Laravel: `Carbon::setTestNow()`, crunz: its private `Event::$clock`, the way crunz's own tests set it). The
backend still makes the due decision.

## How it fits together

```
system cron ──> artisan schedule:run
                  │  Schedule is resolved ──> provider->tasks() ──> ScheduleLoader (checks the whole set)
                  │                          ──> LaravelScheduler::register() ──> native callback events
                  │                              (cron in numeric form via CronDialect, task timezone)
                  ▼
        Laravel decides which events are due  (native, not our code)
                  │
                  ▼
        TaskExecutor::execute(task)
           FileMutex::tryAcquire("scheduler:<app>:<env>:<task-id>")
             ├─ null  ──────────────────────────────> journal: skipped_locked
             └─ lock ─> task->execute() ─> release() ─> journal: success / failed_task / failed_cleanup
```

With crunz, only the top half changes; the executor part is the same code:

```
system cron ──> cd examples/crunz && crunz schedule:run
                  │  tasks/SchedulerTasks.php ──> provider->tasks() ──> ScheduleLoader
                  │                             ──> CrunzScheduler::register() ──> native events
                  ▼
        crunz decides which events are due  (native, not our code)
                  │  one process per due event, in parallel:  exec php worker.php task:run <id>
                  ▼
        worker: provider->tasks() ──> ScheduleLoader ──> task by id ──> TaskExecutor::execute(task)
                (no second "is it due?" check; the worker holds the mutex, not crunz)
```

Business code depends only on `SchedulerTest\Contract`:

```php
final class SendInvoices implements TaskInterface
{
    public function __construct(private InvoiceMailer $mailer) {}

    public function execute(): void          // return = success, any Throwable = failure
    {
        $this->mailer->sendPending();
    }
}

final class AppSchedule implements ScheduleProviderInterface
{
    public function __construct(private InvoiceMailer $mailer) {}

    public function tasks(): iterable
    {
        yield new ScheduledTask(
            id: 'invoices.send',                 // stable: part of the lock key and the journal
            task: new SendInvoices($this->mailer),
            cronExpression: '*/15 8-18 * * MON-FRI',
            timezone: new \DateTimeZone('Europe/Prague'),
            preventOverlap: true,                // default
        );
    }
}
```

Wiring in a Laravel 12 app's `bootstrap/app.php` – the same pattern the demo uses and the tests run
([examples/laravel/bootstrap/app.php](examples/laravel/bootstrap/app.php)):

```php
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use SchedulerTest\Bootstrap\ScheduleLoader;
use SchedulerTest\Execution\JsonLinesReporter;
use SchedulerTest\Execution\TaskExecutor;
use SchedulerTest\Infrastructure\Laravel\LaravelScheduler;
use SchedulerTest\Infrastructure\Mutex\FileMutex;

$app = Application::configure(basePath: dirname(__DIR__))
    // ...
    ->withExceptions(function ($exceptions) {})   // schedule:run needs an exception handler
    ->create();

// Not withSchedule(): Laravel runs that for every artisan command. Schedule is resolved only by the
// schedule:* commands, whatever options come first (`artisan --env=prod -v schedule:run`) and for
// Artisan::call() too - and a broken task definition can't break migrate, down, queue:work...
// extend() completes registration before the container caches the Schedule singleton.
// If a definition fails, the next resolution starts fresh instead of running a partial schedule.
$app->extend(Schedule::class, function (Schedule $schedule) use ($app): Schedule {
    $executor = new TaskExecutor(
        new FileMutex($app->storagePath('scheduler/locks')),
        'billing',                                // application name, part of the lock key
        $app->environment(),                      // environment, part of the lock key
        new JsonLinesReporter($app->storagePath('logs/scheduler.jsonl')),
    );

    (new ScheduleLoader())->registerAll(
        $app->make(AppSchedule::class),
        new LaravelScheduler($schedule, $executor->execute(...)),
    );

    return $schedule;
});

return $app;
```

System cron, as usual for Laravel: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`.

The full API – every parameter, default, exception and guarantee – is in [docs/api.md](docs/api.md).

## Cron expressions

Five fields with explicit per-task timezone (UTC by default): numbers, `*`, lists, ranges, steps, month names
`JAN`–`DEC`, day names `SUN`–`SAT` (any case, also `MON-FRI/2`), Sunday as `0` or `7`, `MON-SUN`. A step must be
1..the field's maximum (59, 23, 31, 12, 7) – `*/60` is rejected rather than silently wrapped. Macros (`@daily`)
and `L`, `W`, `#`, `?` are rejected; impossible dates like `0 0 31 2 *` fail at registration. `*/n` in one day
field together with a restricted other day field (`0 0 13 * */2`) is rejected because cron implementations
disagree on it.

Laravel 12 and crunz both use dragonmantank/cron-expression 3.x. `CronDialect` translates the input into a
numeric form to avoid parser inconsistencies: day-of-week ranges become explicit lists (`MON-FRI/2` →
`1,3,5`); minute/hour steps also become lists (`*/59` → `0,59`, `1-2/23` in the hour field → `1`). This keeps
both the due decision and `schedule:list`'s next-run date correct at the field boundaries. A range's start
and end must still be valid: `10-70/59` is rejected, not reduced to minute 10. Validation and registration
use the same translated string. Whether a minute is due is still decided by the backend (Laravel or crunz).
Details: [docs/api.md#scheduledtask](docs/api.md#scheduledtask).

## Guarantees and limits

With `preventOverlap: true` and `FileMutex`:

- two runs of the same task id never execute at the same time – in one process or in separate processes on
  the same host;
- a run that finds the lock busy is skipped (not queued, not retried) and logged as `skipped_locked`;
  "busy" and a technical lock failure (`failed_mutex`) are different outcomes;
- the lock is released after success and after failure; a cleanup failure never hides the task's own error;
- if the process is killed (even `SIGKILL`) the OS drops the lock; lock files are opened close-on-exec, so
  commands a task starts don't inherit the lock.

Deliberately **not** provided – and why:

- **synchronous, single host.** `FileMutex` protects processes on one machine with a local file system. Not
  several servers, not NFS. A distributed lock would be a different `MutexInterface` implementation with its
  own guarantees; a Redis lock with a TTL is weaker (it can expire while the task still runs), so it would
  need a new contract version, not a silent swap.
- **no TTL.** A hanging task keeps its lock; every later run is `skipped_locked`. Alert on a task that stays
  `skipped_locked` too long rather than letting a timeout start a second copy.
- **no deduplication per period, no catch-up, no retries, no exactly-once.** The mutex prevents overlap: two
  scheduler processes starting a short task one after another run it twice.
- **no fork without exec inside a protected task.** A child created by `pcntl_fork()` shares the lock
  descriptor. `FileLock` refuses to unlock from the child, but if the parent is killed while the child lives,
  the lock stays held until the child exits. Starting external commands is fine.
- **queues / background work** started by a task are protected only up to the hand-off.
- **daylight saving** follows the cron parser: in `Europe/Prague`, `30 2 * * *` runs twice on the autumn change
  day and not at all on the spring one – use UTC or avoid 02:00–03:00 for such tasks.
- **Laravel runs due callbacks one after another** in one process; a slow task delays the next ones in that
  minute. **crunz starts every due task in its own process at once.** No ordering between different tasks is
  promised by either.
- **crunz exit code.** `crunz schedule:run` exits with 0 even when a worker failed; the journal (or crunz's
  output / `log_errors`) shows it. A worker killed by a signal (`SIGKILL`, OOM killer) leaves **no journal
  line**; crunz's console output stays empty, and with `log_errors` on crunz logs only the task id and the
  command, no reason. The lock is freed. Alert on expected journal lines that don't show up. Killing the crunz parent does not stop a running
  worker – the worker keeps the lock until its task ends.

We do not know the concrete problem with the Laravel mutex in your setup, so this project does not claim to fix
a specific incident. It replaces the lock with one whose behaviour is spelled out above and covered by tests.

Assumptions we made because the assignment left them open (change them if your situation differs): a job is
a synchronous PHP object; scheduler processes share one host; "mutex" means "no two runs at the same time",
not "one run per period"; standard five-field cron with an explicit timezone.

## Switching to crunz

The switch is implemented and checked with real crunz processes ([docs/portability.md](docs/portability.md)).

| Changes (infrastructure) | Stays as it is (application) |
|---|---|
| adapter: `LaravelScheduler` → `CrunzScheduler` | task classes (`TaskInterface`) |
| bootstrap: `extend(Schedule::class, ...)` → crunz task file `tasks/*Tasks.php` + `crunz.yml` | `ScheduledTask` definitions: ids, cron, timezones |
| who runs a due task: callback in `schedule:run` → `worker.php task:run <id>` (`WorkerCommand`) | the provider (`DemoScheduleProvider`) |
| system cron: `artisan schedule:run` → `cd <dir> && vendor/bin/crunz schedule:run` | `TaskExecutor`, `FileMutex`, lock keys, journal format |

In the demo both backends share one wiring class, `examples/app/DemoApplication.php`, so app name, environment,
lock directory and journal are identical – a crunz worker and a Laravel `schedule:run` exclude each other on
the same task (tested in both directions).

## Checks

| Suite | What it covers |
|---|---|
| `Contract` | `ScheduledTask`, cron dialect, loader, no framework types in neutral code |
| `Mutex` | separate processes, 8-process races, `SIGKILL`, close-on-exec, fork guard, busy vs error |
| `Execution` | every executor branch incl. release failure and broken reporter (fakes) |
| `Laravel` | real `schedule:run` in-process with a frozen clock: due/not due, timezones, cron dialect, errors, native mutex untouched |
| `Integration` | the demo app as separate OS processes: concurrency, `SIGKILL`, option order, `Artisan::call()`, repeated calls after broken config, no duplicate events |
| `Crunz` | real `crunz schedule:run` (no `--force`): all cron dialect cases, timezones, `task:debug` next runs, broken config, worker exit codes, two runners, Laravel + crunz on one lock, `SIGKILL` of the worker, paths with spaces and quotes |

`vendor/bin/phpunit --testsuite Mutex` runs one suite. Where and how this was run (PHP 8.2/8.5 on Linux,
PHP 8.5 on macOS): [docs/verification.md](docs/verification.md). CI for GitHub Actions:
[.github/workflows/ci.yml](.github/workflows/ci.yml).

## Project layout

```
src/Contract/               public API: interfaces, ScheduledTask, exceptions - no framework types
src/Cron/                   CronDialect: v1 cron syntax -> plain numeric form
src/Bootstrap/              ScheduleLoader: provider -> checked task set -> register()
src/Execution/              TaskExecutor, lock keys, statuses, journal reporters
src/Infrastructure/Mutex/   FileMutex / FileLock
src/Infrastructure/Laravel/ LaravelScheduler - the only class that knows Laravel
src/Infrastructure/Crunz/   CrunzScheduler (the only class that knows crunz), WorkerCommand for its worker
examples/tasks/             demo tasks and provider - no framework types
examples/app/               DemoApplication: settings, executor and provider shared by both demos
examples/laravel/           minimal Laravel 12 app
examples/crunz/             crunz.yml, task file and worker
tests/                      Contract, Mutex, Execution, Laravel, Integration, Crunz
docs/                       API reference, portability, verification, initial environment probes
docker/                     Dockerfile for running the checks on a chosen PHP version
```
