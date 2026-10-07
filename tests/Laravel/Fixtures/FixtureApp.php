<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Laravel\Fixtures;

use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A real Laravel 12 app (configure()->withSchedule()->withExceptions()->create())
 * that runs one `schedule:run` through the console kernel, like `php artisan` does.
 *
 * Laravel keeps a lot of static state (artisan bootstrappers, facades, error
 * handlers), so every instance cleans up after itself in close().
 */
final class FixtureApp
{
    public readonly Application $app;

    /** @var list<\Throwable> what reached ExceptionHandler::report() */
    public array $reported = [];

    /** @var list<ScheduledTaskFailed> */
    public array $failedEvents = [];

    public ?Schedule $schedule = null;

    public string $output = '';

    private readonly string $tmpDir;
    private readonly string $previousTimezone;
    private readonly int $previousErrorReporting;

    /**
     * @param \Closure(Schedule): void $defineSchedule body of withSchedule()
     */
    public function __construct(\Closure $defineSchedule)
    {
        $this->previousTimezone = date_default_timezone_get();
        $this->previousErrorReporting = error_reporting();

        // static leftovers from another app would run its withSchedule() callback too
        Artisan::forgetBootstrappers();

        $this->tmpDir = sys_get_temp_dir() . '/scheduler-test-laravel-' . bin2hex(random_bytes(6));
        foreach (['/storage/framework', '/storage/logs', '/bootstrap/cache'] as $dir) {
            if (!mkdir($this->tmpDir . $dir, 0o777, true)) {
                throw new \RuntimeException('Cannot create ' . $this->tmpDir . $dir);
            }
        }

        $this->app = Application::configure(basePath: __DIR__ . '/app')
            ->withSchedule(function (Schedule $schedule) use ($defineSchedule): void {
                $this->schedule = $schedule;
                $defineSchedule($schedule);
            })
            ->withExceptions(function (Exceptions $exceptions): void {
                // collect and stop, so the real handler doesn't go on to the logger
                $exceptions->report(function (\Throwable $e): void {
                    $this->reported[] = $e;
                })->stop();
            })
            ->create();

        // nothing gets written next to the fixture sources
        $this->app->useStoragePath($this->tmpDir . '/storage');
        $this->app->useBootstrapPath($this->tmpDir . '/bootstrap');

        $this->app['events']->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            $this->failedEvents[] = $event;
        });
    }

    public function bindRecordingMutexes(RecordingEventMutex $eventMutex, RecordingSchedulingMutex $schedulingMutex): void
    {
        $this->app->instance(EventMutex::class, $eventMutex);
        $this->app->instance(SchedulingMutex::class, $schedulingMutex);
    }

    /**
     * Runs `schedule:run` with the clock frozen at the given UTC time. Returns the exit code.
     */
    public function runScheduleAt(string $utcTime): int
    {
        Carbon::setTestNow(Carbon::parse($utcTime, 'UTC'));

        $output = new BufferedOutput();
        $kernel = $this->app->make(Kernel::class);
        $exitCode = $kernel->handle(new ArrayInput(['command' => 'schedule:run']), $output);
        $this->output = $output->fetch();

        return $exitCode;
    }

    public function close(): void
    {
        try {
            Carbon::setTestNow();
            Artisan::forgetBootstrappers();
            // also puts PHPUnit's own error handler back in place
            HandleExceptions::flushState();
            RegisterProviders::flushState();
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            AliasLoader::setInstance(null);
            $this->app->flush();
            Container::setInstance(null);

            date_default_timezone_set($this->previousTimezone);
            error_reporting($this->previousErrorReporting);
        } finally {
            self::removeDir($this->tmpDir);
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
