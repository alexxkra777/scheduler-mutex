# A0 – environment and integration probes

Date: 2025-10-03. Machine: macOS (Darwin 27.0.0, APFS), single host.

> **Historical record.** These are the first probes, kept as they were. Later changes:
> the lock now resolves for PHP 8.2 (Symfony 7.4 instead of 8.1), the cron dialect is passed to the parser in
> numeric form with a step limit, and the Laravel registration moved from `withSchedule()` to
> `extend(Schedule::class, ...)`. The crunz adapter is shipped and builds the whole worker command itself –
> the `run(escapeshellarg(PHP_BINARY), [...])` probe below is not enough because of Symfony's `"${:NAME}"`
> placeholders, see [portability.md](portability.md). Current, verified facts: [verification.md](verification.md)
> (2025-10-04).
These are throwaway probes run in `work/` (git-ignored, deleted afterwards). They
check that the planned integration points really exist in the installed
versions. They are not the project's test suite.

## Versions found

| Component | Version | Note |
|---|---|---|
| PHP CLI | 8.5.10 (Homebrew, NTS) | `date.timezone=UTC`, ext `pcntl`/`posix` present |
| Composer | 2.10.3 | |
| laravel/framework | 12.69.3 | resolved from `^12.0` |
| dragonmantank/cron-expression | 3.6.0 | cron parser used by both Laravel 12 and crunz |
| crunzphp/crunz | 3.9.4 | latest **stable** tag; `3.10` exists only as `3.10.x-dev` |
| symfony/console | 7.4.20 (with Laravel) | `laravel/framework ^12` + `crunzphp/crunz ^3.9` resolve together (dry run) |

## Laravel 12: registration -> `schedule:run` -> execution

Minimal app built with `Application::configure()->withSchedule(...)->withExceptions(...)->create()`
and a 5-line `artisan` file.

- `php artisan schedule:run` called `Schedule::call(...)` callbacks that were due:
  `* * * * *` ran, `0 0 1 JAN *` did not.
- The `withSchedule` callback runs again in every artisan process (seen via pid),
  so a new process rebuilds the schedule from code. Nothing is persisted.
- Without `->withExceptions(...)` `schedule:run` dies with
  `Target [Illuminate\Contracts\Debug\ExceptionHandler] is not instantiable`.
- A callback that throws: Laravel catches it in `ScheduleRunCommand::runEvent()`,
  dispatches `ScheduledTaskFailed`, calls `ExceptionHandler::report()` (it went to the
  log channel) and **continues** with the next due event. The process exit code was **0**.
  So the exit code of `schedule:run` says nothing about individual tasks.
- `CallbackEvent::execute()` treats a callback return value of `false` as exit code 1.
  Our registered closure must return nothing.
- The native overlap mutex is only touched when `withoutOverlapping()` is called
  (`Event::shouldSkipDueToOverlapping()`); `onOneServer()` is the only other mutex user.
  We call neither.
- `Event::isDue()` returns false in maintenance mode unless `evenInMaintenanceMode()`.
  We keep the native default.
- `CallbackEvent::runInBackground()` throws – callbacks are always in-process and synchronous.

## Timezone

With Prague hour = 23 and UTC hour = 21, the expression `* 23 * * *`:
- Laravel: event with `->timezone(new DateTimeZone('Europe/Prague'))` ran, same event without timezone (app tz UTC) did not.
- crunz: same result with `->timezone(new DateTimeZone('Europe/Prague'))` and `timezone: UTC` in `crunz.yml`.

## Cron dialect (dragonmantank/cron-expression 3.6.0)

| Expression | Parser |
|---|---|
| `0 0 1 JAN *`, `0 0 1 jan *`, `0 0 * * mon-fri`, `0 0 * * SUN-SAT` | valid (names are case-insensitive) |
| `0 0 * * 0`, `0 0 * * 7` | valid (both Sunday) |
| `61 * * * *`, `*/0 * * * *`, `0 0 1 FOO *` | `InvalidArgumentException` |
| `* * * * * *` | invalid |
| `@daily`, `0 0 L * *`, `0 0 * * 5#2`, `0 0 ? * *` | valid – library extensions |

Day-of-month and day-of-week combine with OR: `0 0 1 * mon` is due on Monday 2026-10-05
and on Thursday 2026-10-01, not on Tuesday 2026-10-06.

Decision for API v1 (2025-10-03; amended 2025-10-04 – see [api.md](api.md#scheduledtask): names are also
allowed in stepped ranges and converted to numbers, steps are limited to the field maximum): five fields; `*`, numbers, lists, ranges, steps; names `JAN`–`DEC`
and `SUN`–`SAT` in any case; day of week `0`–`7`. Macros and the extensions `L`, `W`, `#`, `?`
are rejected by `ScheduledTask` so the dialect stays the plain one every cron understands.
Numeric ranges are checked by the parser in the adapter, not by our own code.

## crunz 3.9.4

Probe: a `tasks/ProbeTasks.php` file returning a `Crunz\Schedule`, events built as
`$schedule->run(escapeshellarg(PHP_BINARY), [$worker, 'task:run', $id])->cron(...)->description($id)`.

- `crunz schedule:run` needs a global `timezone` in `crunz.yml` (`EmptyTimezoneException` otherwise);
  a per-event `->timezone()` overrides it (see above).
- Parameters are escaped via `Process::fromArrayCommand()`; the command itself is not, so the
  binary is passed through `escapeshellarg()`.
- Due events are started as **separate parallel processes** (two workers had different pids);
  `0 0 1 JAN *` was skipped.
- A worker exiting with code 3 triggered `Schedule::onError()`; `crunz schedule:run` still exited 0.
- crunz assigns its own generated event ids, so the stable task id is passed as an argument.
- Unknown config keys are rejected (`errors_only` is not an option in 3.9.4).

## File locking (`flock`) on this Mac

- Two `fopen()` handles of the same file in **one** process: the second `LOCK_EX|LOCK_NB`
  fails with `wouldblock=1` – flock is per open file, so there is no accidental re-entrancy.
- Another process trying while the holder sleeps: `false`, `wouldblock=1`.
- After `kill -9` of the holder the next attempt succeeds – the kernel drops the lock with the descriptor.

Conclusion: a `flock`-based file mutex fits the demo scope (separate CLI processes, one host,
shared local file system). Network file systems and several hosts are out of scope; nothing here
was tested on Linux.
