# Switching the backend: Laravel and crunz

Both backends are implemented and run the same application code. This page maps every part of our API to
Laravel 12 and to crunz 3.9 and lists what differs. Versions checked: laravel/framework 12.69.3,
crunzphp/crunz **v3.9.4** (latest stable on 2025-10-04; the 3.10 line was dev only).

## Mapping

| Our API | Laravel 12 | crunz 3.9 |
|---|---|---|
| `ScheduleProviderInterface::tasks()` | called in `extend(Schedule::class, ...)` in `bootstrap/app.php`, before the built singleton is cached | called in the crunz task file (`tasks/*Tasks.php`) and again in the worker |
| `SchedulerInterface::register()` | `LaravelScheduler`: `$schedule->call(fn)->cron(numeric)->timezone(tz)->name(id)` | `CrunzScheduler`: `$schedule->run("exec '<php>' '<worker>' 'task:run' '<id>'")->cron(numeric)->timezone(tz name)->description(id)`, command line built by the adapter |
| cron handed to the backend | `CronDialect::toNumeric()` | the same `CronDialect::toNumeric()` – crunz uses the same parser (dragonmantank 3.x) |
| cron validation before the native event | `Cron\CronExpression` incl. `getNextRunDate()` | the same check (`NativeCron`); crunz's `Event::cron()` itself only counts the fields |
| `ScheduledTask::$timezone` | `Event::timezone(DateTimeZone)` | `Event::timezone(<name>)` – a string, because crunz's `task:debug` ignores a `DateTimeZone` object for its next run dates. crunz also needs a global `timezone:` in `crunz.yml` |
| who decides "is due" | `php artisan schedule:run` | `vendor/bin/crunz schedule:run` |
| where the task runs | callback in the `schedule:run` process, one after another | one worker process per due task, all started at once |
| who holds the mutex | `TaskExecutor` inside `schedule:run` | `TaskExecutor` inside the worker, until the task is done |
| native overlap lock | not used (`withoutOverlapping()` never called) | not used (`preventOverlapping()` never called) |
| task failure | executor throws → Laravel reports it, continues, exits 0 | worker exits 1 with one stderr line → crunz prints it (or logs it with `log_errors`), exits 0 |
| result you can rely on | our journal (`JsonLinesReporter`) | the same journal, written by the worker |
| next runs | `schedule:list` | `task:debug <n>` (in the task's timezone) |

What does **not** change: `TaskInterface` implementations, `ScheduledTask` definitions, the provider,
`TaskExecutor`, `FileMutex`, lock keys and the journal format.

What differs by design: order of different tasks (Laravel sequential, crunz parallel) and process layout.
Neither is promised by our API.

## The crunz side

Three pieces; only they know crunz ([api.md](api.md)).

1. **`CrunzScheduler`** registers each task as a shell event `exec '<php>' '<worker>' 'task:run' '<id>'`.
   The adapter builds the whole command line and passes no `run()` parameters. Plain POSIX single quoting is
   **not** enough here: crunz starts the command with Symfony `Process::fromShellCommandline()`, and
   `Process::start()` replaces every `"${:NAME}"` in the line with the environment variable `NAME` – inside
   single quotes too (symfony/process 7.4, `replacePlaceholders()`). A directory named `a "${:X}" b` would
   change or make crunz fail. So every `$` is written outside the quotes as `\$`; the shell still gets the same
   literal arguments (tested with such names in the php and worker paths, with `X` set and not set).
   `exec` makes the shell replace itself with php: the process crunz watches is the worker. With Debian's
   `dash` and no `exec`, a shell stays in between (checked).
2. **Task file** ([examples/crunz/tasks/SchedulerTasks.php](../examples/crunz/tasks/SchedulerTasks.php)): builds
   a `Crunz\Schedule` from the application's provider with `ScheduleLoader` + `CrunzScheduler`. Any exception
   leaves the file; crunz then exits 1 before starting any event from any task file.
3. **Worker** ([examples/crunz/worker.php](../examples/crunz/worker.php)) – `WorkerCommand`: loads the same
   provider, checks the set the same way, runs one task by id through `TaskExecutor`. It does not check
   whether the task is due: crunz already decided, a late start must not become a silent skip.

Worker exit codes: `0` success or `skipped_locked`; `1` task / mutex / release failure; `2` wrong arguments or
unknown id; `3` configuration error. Nothing runs for `2` and `3`.

Config and system cron (from [examples/crunz/crunz.yml](../examples/crunz/crunz.yml)):

```yaml
source: tasks
suffix: Tasks.php
timezone: UTC        # required by crunz; each task still uses its own ScheduledTask timezone
```

```
* * * * * cd /path/to/app/examples/crunz && php ../../vendor/bin/crunz schedule:run
```

crunz looks for `crunz.yml` and the task directory **in its working directory only**. Started elsewhere it
prints `No task found!` and exits 0 – a silent empty run. Keep the `cd` in the cron line.

## Checked native crunz behaviour (v3.9.4, installed)

| Behaviour | Where it shows |
|---|---|
| due decision by `crunz schedule:run` without `--force`, per task timezone | `CrunzDemoTest`, `CrunzCronRunTest` |
| every dialect case of the Laravel suites gives the same due result | `CrunzCronRunTest::testCrunzStartsExactlyTheDueTasks` |
| crunz has no public clock API; the due check reads the private static `Event::$clock` | tests freeze it the way crunz's own `EventTest::setClockNow()` does; one demo test uses the real clock |
| exception in a task file → exit 1, no event started | broken cron / provider / duplicate / impossible date tests |
| failed worker → crunz exit 0, worker stderr in crunz output | `testFailedTaskIsInTheJournalAndCrunzOutputWhileCrunzExitsZero` |
| `task:debug` uses the task timezone only when it is a string | `testTaskDebugUsesTheTaskTimezoneForNextRuns` |
| started outside the config directory → `No task found!`, exit 0 | `testRunFromAnotherDirectoryFindsNoTasks` |
| killing the crunz parent leaves the worker (and its lock) alive | `testKillingCrunzDoesNotFreeTheLockWhileTheWorkerRuns` |

## Switch checklist

1. `composer require crunzphp/crunz:^3.9.4` (resolves together with `laravel/framework ^12` and PHP 8.2).
2. Add a `crunz.yml`, a task file and a worker like the ones in `examples/crunz`, using the application's own
   bootstrap for the provider and the executor (in the demo: `DemoApplication`).
3. Use the same application name, environment and lock directory as before, so the old and the new runner
   exclude each other while both are installed. Give the lock directory as an absolute path (or resolve it from
   the project root, like `DemoApplication`): artisan and crunz start in different working directories.
4. Build the executor in the task file too, even though only the worker uses it, so a bad setting stops
   `crunz schedule:run` instead of making every worker fail behind crunz's exit code 0.
5. Point the system cron at `cd <dir> && vendor/bin/crunz schedule:run` instead of `artisan schedule:run`.
6. Remove the `extend(Schedule::class, ...)` registration from the Laravel bootstrap (or keep the Laravel app for other things).
7. Keep task classes, provider, executor, mutex and journal untouched.
