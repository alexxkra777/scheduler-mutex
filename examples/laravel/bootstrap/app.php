<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use SchedulerTest\Bootstrap\ScheduleLoader;
use SchedulerTest\Examples\App\DemoApplication;
use SchedulerTest\Infrastructure\Laravel\LaravelScheduler;

// Demo Laravel 12 app. The only place that knows about both Laravel and our module.
// Settings come from environment variables (see DemoApplication) so tests can point every process
// at its own dirs. The crunz demo reads the very same settings, so both backends share the app name,
// environment, lock directory and journal.
$basePath = dirname(__DIR__);
$demo = DemoApplication::fromEnvironment();

$app = Application::configure(basePath: $basePath)
    ->withExceptions(static function (Exceptions $exceptions): void {
        // Laravel's default handler: failed scheduled tasks get reported to the log channel (stderr here)
    })
    ->create();

// No withSchedule() on purpose: Laravel calls it when *any* artisan command starts, so one broken
// task definition would take down migrate, down, queue:work... Instead we fill the Schedule the
// moment somebody asks for it - only the schedule:* commands do. That works for every argv shape
// (`artisan --env=x -v schedule:run`) and for Artisan::call() alike, since no command name is parsed.
//
// extend(), not afterResolving(): the container runs extenders *before* it caches the singleton,
// resolving callbacks after. If registration throws half-way, nothing is cached, so the next
// resolve builds a fresh Schedule and fails again - a half-filled one can never be run.
$app->extend(Schedule::class, static function (Schedule $schedule): Schedule {
    // Each schedule:run process rebuilds the full set from the provider. Settings are read again
    // here, at resolve time, the same way the crunz task file and worker read them.
    $demo = DemoApplication::fromEnvironment();

    (new ScheduleLoader())->registerAll($demo->provider(), new LaravelScheduler($schedule, $demo->executor()->execute(...)));

    return $schedule;
});

$app->useStoragePath($demo->storage);

// Test hook only: freeze Laravel's clock so tests can ask "what is due at <time>"
// without waiting for that time. Laravel still makes the due decision itself.
if ($demo->fakeNow !== null) {
    Carbon::setTestNow(Carbon::parse($demo->fakeNow));
}

return $app;
