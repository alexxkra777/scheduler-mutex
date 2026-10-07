# Verification log

What was actually run, where and on which commit. Newest first. Earlier probes that shaped the design are in
[a0-environment.md](a0-environment.md).

## 2025-10-05 – GitHub Actions on the published crunz work (`6fbf14e`)

The commits below were pushed to `main` together with a docs-only fix (`6fbf14e`: a link anchor in
portability.md and one sentence in the entry below). The
CI workflow run started by that push ran
on `6fbf14e6fdba78cca634701017801eebb2aedc94`:

| Job | Runner | PHP | Composer | Result |
|---|---|---|---|---|
| PHP 8.2 on Ubuntu | ubuntu-24.04 | 8.2.34 | 2.10.3 | success – PHPStan no errors, 698 tests, 3018 assertions |
| PHP 8.5 on Ubuntu | ubuntu-24.04 | 8.5.11 | 2.10.3 | success – PHPStan no errors, 698 tests, 3018 assertions |

In both jobs the logs show `composer validate --strict` (valid), `composer install` from the lock file
(108 packages), `composer check-platform-reqs` (no failed requirement) and `composer check` (PHPStan level 8 +
PHPUnit). The same commit also passed `composer check` in a clean clone on macOS, PHP 8.5.10.

## 2025-10-04 – literal crunz command paths and the new-year real-clock test (`5349f05`)

Two fixes found by an independent acceptance review of the crunz backend, checked on the code at commit
`5349f05`.

**Literal paths through Symfony placeholders.** crunz starts an event with Symfony
`Process::fromShellCommandline()`; `Process::start()` replaces every `"${:NAME}"` in the command line with the
environment variable `NAME`, also inside single quotes (`replacePlaceholders()`, symfony/process 7.4.19). A worker
or php path containing such text changed (crunz still exited 0, the worker never ran) or crunz failed when `NAME`
was unset. The adapter now builds the whole command and writes `$` outside the quotes as `\$`.

| Regression | Before the fix (`40aed9b`) | After |
|---|---|---|
| `CrunzSchedulerTest::testCommandSurvivesSymfonyPlaceholdersAndTheShell` – the event command through Symfony Process and sh, worker / php / both paths, variable set and unset | 8 of 8 failed (4 errors "missing a value for parameter", 4 changed paths) | pass |
| `CrunzCronRunTest::testLiteralPathsReachTheWorkerUnchanged` – real `crunz schedule:run`, exact worker argv recorded | 6 of 8 failed; the 2 controls (spaces, quotes, Unicode) passed | pass |
| reversing only the `$` handling, Linux PHP 8.2 with `dash` | – | 14 of 16 fail, the 2 controls pass |

The previous single `testPathsWithSpacesAndQuotesWork` became the control cases of the parameterized process test.

**Real-clock demo test in the new-year minute.** The test allowed `new-year-greeting` at 00:00 Jan 1 in Prague but
still required exactly three worker pids. It now classifies the clock interval around the crunz run: entirely
outside that minute exactly three tasks, entirely inside exactly four, crossing its border three or four;
statuses, one journal line per task and one worker pid per task stay exact. The rule is pinned for every calendar
case by `DemoRunExpectationTest` and by real crunz runs at frozen minutes (`testOneWorkerPerDueTaskAtAControlledMinute`).

| Check | Old test | New test |
|---|---|---|
| the real-clock test with its clock readings and crunz's clock set to 00:00 Jan 1 Prague (temporary edit in an isolated copy, a simulation – not a run on Jan 1) | 1 failure: 4 pids, expected 3 | pass |
| the old unconditional "three pids" put back into the helper | – | 4 failures (2 real crunz runs in the new-year minute, 2 unit cases) |
| the window border `>=` changed to `>` (found by the review) | – | the case "ends on the first instant" fails |

If the wall clock steps back between the two readings (NTP), the interval is taken in order instead of failing.

| Environment | PHP | Composer | crunz | PHPUnit result |
|---|---|---|---|---|
| macOS 27, arm64, clean clone | 8.5.10 | 2.10.3 | 3.9.4 | 698 tests, 3018 assertions, 0 skipped |
| Debian 13, Linux `7.0.12-linuxkit`, aarch64, `/bin/sh` = dash | 8.2.34 | 2.10.3 | 3.9.4 | 698 tests, 3018 assertions, 0 skipped |
| Debian 13, Linux `7.0.12-linuxkit`, aarch64, `/bin/sh` = dash | 8.5.11 | 2.10.3 | 3.9.4 | 698 tests, 3018 assertions, 0 skipped |

Linux images were built from `git archive HEAD` with [docker/Dockerfile](../docker/Dockerfile) as a non-root
user (uid 1000) on the container's overlayfs, no host bind mount for the mutex tests. Everywhere
`composer validate --strict`, `composer install` from the lock, `composer check-platform-reqs` and `composer check`
(PHPStan level 8 + PHPUnit) exited with 0. The Laravel and crunz demo commands from the README gave the same
results as in the entry below on macOS and on Linux PHP 8.2 / 8.5 (Linux demos run at `c6fb13a`, which differs
from `5349f05` only in tests). Dependencies are unchanged. The external
reproduction of the placeholder problem (control plus worker / php path, variable unset and set) gave five
worker runs and exit 0 on macOS.

An independent reviewer (not the author of the fixes) checked `c6fb13a` in its own clean clone on macOS:
60 real `crunz schedule:run` runs over 15 hostile directory names (placeholders with and without quotes,
`$`, backslashes, newline, tab, Unicode, shell metacharacters) with the variable unset, set, set to a quote and
empty – all ran the worker once with exactly the expected argv; 2400 random strings through Symfony Process and
`sh` under `LC_ALL=C` and `en_US.UTF-8` – no differences. It found no P1/P2 issue; its two P3 notes (the window
border case above, an outdated line in [a0-environment.md](a0-environment.md)) are fixed.

Not verified: GitHub Actions on these commits (not pushed yet; the README badge shows the last pushed version),
Linux x86_64, Windows, several hosts, network file systems, a real file-system failure in `FileLock::release()`.

## 2025-10-04 – working crunz backend and NUL path checks (`63e6494`)

Checks on the code at commit `63e6494` (Linux) and `28052fb` (macOS clean clone; it only adds README wording and
a code comment on top). An independent reviewer, who did not write the code, also ran `composer check` in its
own clean clone on macOS at `fe550ec` and again at `63e6494` and reproduced its findings before and after the
fixes. Linux images were built from `git archive HEAD` with the same
[Dockerfile](../docker/Dockerfile): non-root user (uid 1000), `/bin/sh` is `dash`, dependencies, lock files and
test temp files on the container's overlayfs – no host bind mount for the mutex tests.

| Environment | PHP | Composer | crunz | PHPUnit result |
|---|---|---|---|---|
| macOS 27, arm64, clean clone | 8.5.10 | 2.10.3 | 3.9.4 | 648 tests, 2513 assertions, 0 skipped |
| Debian 13, Linux `7.0.12-linuxkit`, aarch64 | 8.2.34 | 2.10.3 | 3.9.4 | 648 tests, 2513 assertions, 0 skipped |
| Debian 13, Linux `7.0.12-linuxkit`, aarch64 | 8.5.11 | 2.10.3 | 3.9.4 | 648 tests, 2513 assertions, 0 skipped |

Everywhere `composer validate --strict`, `composer install` from the committed lock, `composer check-platform-reqs`
and `composer check` (PHPStan level 8 + PHPUnit) exited with 0. The lock adds crunzphp/crunz 3.9.4 and six
Symfony 7.4 packages (config, dependency-injection, filesystem, lock, var-exporter, yaml); nothing else changed.

Demo commands, same results on Linux PHP 8.2 / 8.5 and macOS PHP 8.5 (`DEMO_SLOW_SECONDS=1`):

| Command | Result |
|---|---|
| `php examples/laravel/artisan schedule:list` / `schedule:run` | exit 0 / exit 0; journal: heartbeat success, failing-task failed_task, slow-report success, one pid |
| `cd examples/crunz && php ../../vendor/bin/crunz schedule:list` | exit 0, four tasks, numeric cron, `exec '<php>' '<worker>' 'task:run' '<id>'` |
| `php ../../vendor/bin/crunz schedule:run` | exit 0; the same three journal lines in the same file, three different worker pids; the worker's `failed_task` line in crunz's output |
| `php ../../vendor/bin/crunz task:debug 4` | five next runs at `01-01 00:00:00 Europe/Prague` |
| `DEMO_INCLUDE_BROKEN_TASK=1 php ../../vendor/bin/crunz schedule:run` | exit 1, nothing runs |

Regressions written first, failing on the code before the fix:

| Change | Regression | Before the fix |
|---|---|---|
| NUL byte in `FileMutex` / `JsonLinesReporter` paths gave a raw `ValueError` later | `FileMutexTest`, `JsonLinesReporterTest` NUL cases | 4 of 6 failed (the relative-path cases passed already) |
| php binary of crunz events not checked (independent review) | `CrunzSchedulerTest::testConstructorRejectsUnusablePaths` | 4 cases failed |
| crunz task file didn't build the executor: bad app/env name → crunz exit 0, every worker exit 3 (review) | `CrunzDemoTest::testBrokenConfigurationFailsTheWholeRunBeforeAnyTask` | 2 cases failed |
| relative `SCHEDULER_LOCK_DIR` resolved from different working directories (review) | `CrunzDemoTest::testRelativeLockDirectoryIsTheSameForLaravelAndCrunz` | failed: both backends ran `slow-report` |

Mutations in an isolated copy, each reverted afterwards (macOS PHP 8.5.10 unless noted):

| Mutation | Failing tests |
|---|---|
| mutex off (`TaskExecutor` treats every task as `preventOverlap: false`) | 4 in `CrunzDemoTest`: two crunz runners, crunz→Laravel, Laravel→crunz, killed crunz parent |
| crunz event gets the raw expression instead of the numeric form | 12 `CrunzCronRunTest` minutes (e.g. `MON-FRI/2` ran on Sunday) |
| worker checks again whether the task is due | 9 (late worker, frozen-clock timezone cases) |
| timezone passed as an object | 3 (`task:debug` next runs, registration) |
| no `exec` before php | macOS: only the registration test (bash execs the last command itself); Linux `dash`: the `SIGKILL` test too – the worker's parent is the shell, not crunz |

How the due decision is tested: crunz 3.9.4 has no public clock API; `Event::isDue()` reads the private static
`Event::$clock`. The process tests set it in the task file the way crunz's own `tests/Unit/EventTest.php`
(`setClockNow()`) does; crunz still evaluates every event. One demo test runs on the real clock, nothing uses
`--force`. `task:debug` next runs use the real clock and are checked by properties (minutes, weekdays, spacing).

Not verified: GitHub Actions on these commits (it runs on push, nothing was pushed yet), Linux x86_64, Windows, several hosts, network
file systems, a real file-system failure inside `FileLock::release()` (release errors are covered with fakes).

## 2025-10-04 – boundary steps and repeated schedule calls (`9dd32cc`)

Independent checks on the code at commit `9dd32cc`, from clean checkouts / Git archive build contexts.
The Linux images use the same [Dockerfile](../docker/Dockerfile), a non-root user (uid 1000), and overlayfs
for dependencies, lock files and temporary test files. No host bind mount is used for mutex tests.

| Environment | PHP | Composer | PHPUnit result |
|---|---|---|---|
| macOS 27, arm64 | 8.5.10 | 2.10.3 | 477 tests, 1255 assertions, 0 skipped |
| Debian 13, Linux `7.0.12-linuxkit`, aarch64 | 8.2.34 | 2.10.3 | 477 tests, 1255 assertions, 0 skipped |
| Debian 13, Linux `7.0.12-linuxkit`, aarch64 | 8.5.11 | 2.10.3 | 477 tests, 1255 assertions, 0 skipped |

In every environment, `composer validate --strict`, `composer install` from the committed lock,
`composer check-platform-reqs`, and `composer check` exited with 0. `composer check` includes PHPStan
level 8 and the full PHPUnit suite. Dependency versions are unchanged from the previous run below.

The latest fixes were also checked by reversing them in an isolated copy on macOS PHP 8.5.10:

| Regression | Fixed code | Reversed fix |
|---|---|---|
| `AuditCronBoundaryTest` + `CronStepBoundaryTest`: due times and `schedule:list --json` next dates for minute/hour steps, including 59 and 23 | 52 tests pass | old `CronDialect` from `ed2c012`: 20 failures (16 in `CronStepBoundaryTest`, 4 in the audit regression) |
| `AuditRepeatedScheduleCallTest` + `RepeatedScheduleCallTest`: two calls in the same application after a bad cron, after a provider failure, and with valid configuration | 4 tests pass | `extend` changed back to `afterResolving`: 3 failures; valid configuration control passes |

An additional numeric-step probe checked 328 valid expressions against independently calculated value
sets: 16368 due checks and 1312 next-date checks, no discrepancies. Eleven invalid mixed lists / ranges
were rejected. This supplements the native Laravel command regressions; it is not another scheduler engine.

The demo was run again: `schedule:list` exits 0; `schedule:run` exits 0 and records success / failed_task /
success for the three due tasks. With a broken cron, `schedule:run` exits 1 without task output, while
`artisan list` still exits 0. Repeated-call integration tests confirm a failed schedule build is never
cached and a valid build adds no duplicate events.

GitHub Actions had not run at the time of these local checks. Its live result is shown by the repository's
CI badge / Actions page once published. Linux x86_64, Windows, multi-host locks and network file systems
remain outside these results. Earlier entries below describe historical runs, not the current test count.

## 2025-10-04 – Linux PHP 8.2 / 8.5 and macOS PHP 8.5

### Linux (Docker, final run on commit `7b8f641`; first run on `eaa2dbe` had the same results with 378 tests)

Images built from a clean checkout with [docker/Dockerfile](../docker/Dockerfile)
(`git archive HEAD | docker build --build-arg PHP_VERSION=<v> -f docker/Dockerfile -`). Dependencies, lock files
and test temp dirs live inside the container (overlayfs), not on a host bind mount. The checks run as a
non-root user, so permission tests are not skipped. Host: Docker Desktop, kernel `7.0.12-linuxkit`, **aarch64**.

| | PHP 8.2 | PHP 8.5 |
|---|---|---|
| PHP | 8.2.34 (cli, NTS) | 8.5.11 (cli, NTS) |
| OS | Debian GNU/Linux 13 (trixie) | Debian GNU/Linux 13 (trixie) |
| Composer | 2.10.3 | 2.10.3 |
| extensions checked | pcntl, posix (+ Laravel's, via check-platform-reqs) | same |
| `composer validate --strict` | exit 0 | exit 0 |
| `composer install --no-interaction --no-progress --prefer-dist` | exit 0, 101 packages from lock | exit 0 |
| `composer check-platform-reqs` | exit 0 | exit 0 |
| `composer check` (PHPStan level 8 + PHPUnit) | exit 0, 412 tests, 1101 assertions, 0 skipped | exit 0, 412 tests, 1101 assertions, 0 skipped |

These are exactly the `run:` steps of [.github/workflows/ci.yml](../.github/workflows/ci.yml).

Demo commands in the same containers (both versions, same results):

| Command | Result |
|---|---|
| `php examples/laravel/artisan schedule:list` | exit 0, four tasks listed |
| `DEMO_SLOW_SECONDS=1 php examples/laravel/artisan --env=local -v schedule:run` | exit 0; journal: `heartbeat` success, `failing-task` failed_task, `slow-report` success |
| `DEMO_INCLUDE_BROKEN_TASK=1 php examples/laravel/artisan schedule:run` | exit 1, `broken-task` named in the error, no journal entries |
| `DEMO_INCLUDE_BROKEN_TASK=1 php examples/laravel/artisan list` | exit 0 |

Locked versions: laravel/framework 12.69.3, symfony/console 7.4.20, dragonmantank/cron-expression 3.6.0,
phpunit/phpunit 11.5.56, phpstan/phpstan 2.2.16.

### macOS (commit `7b8f641`)

macOS 27.0 (Darwin 27.0.0, APFS), PHP 8.5.10, Composer 2.10.3: `composer validate --strict`,
`composer check-platform-reqs` and `composer check` exit 0, 412 tests, 1101 assertions.

### Not verified

- GitHub Actions itself: the workflow was checked with actionlint 1.7.12 (exit 0) and its commands were run in
  the Linux containers above, but it has not run on GitHub.
- x86_64 Linux (the containers were arm64), Windows, network file systems, several hosts.
- A real file-system failure inside `FileLock::release()` – the executor's handling of release errors is covered
  with fakes only.

## 2025-10-04 – regressions for the review findings

Each regression was written first and failed on the previous code:

| Finding | Regression | On the old code |
|---|---|---|
| Named ranges with steps (`MON-FRI/2` ran on Sunday, `JAN-DEC/2`, `SUN-SAT` rejected) and steps above the field maximum (`*/61` ran at minute 1) | `tests/Laravel/CronDialectRunTest` – real `schedule:run` per minute | 45 of 105 cases failed |
| Global options before the command (`artisan --env=local schedule:run`) silently saw an empty schedule | `tests/Integration/LaravelDemoTest` – option order, `schedule:list`, `Artisan::call()` | 7 failures |
| `release()` in a child forked without exec freed the parent's lock | `tests/Mutex/FileMutexTest::testForkedChildCannotFreeTheParentsLock` | failed: `release() in the forked child freed the parent's lock` |
| `composer.lock` needed PHP ≥ 8.4.1 (five Symfony 8.1 packages) | `composer prohibits php 8.2.0 --locked`; real install on PHP 8.2 above | exit 1, five conflicts |

Found by the independent final review (commit `d41c09e`) and fixed afterwards: day ranges touching Sunday
(`SUN-SUN`, `7-7`, `0-SUN`) ran every day and `SUN-SAT/7` was rejected; `*/n` in a day field with the other day
field restricted is ORed by Laravel but ANDed by classic cron. Regressions in `CronDialectRunTest` failed
(12 cases) before the fix. In addition, 792 generated expressions were compared day by day over 2026 between
the parser (on the numeric form) and an independent set-based reference with cronie's star rule: 618 accepted,
0 mismatches, 174 rejected – exactly the ambiguous day-field mixes.

The cron fix was also checked the other way round: handing the native event the raw expression while
validating the numeric one makes 31 cases fail.
