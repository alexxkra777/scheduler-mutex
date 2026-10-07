<?php

declare(strict_types=1);

namespace SchedulerTest\Examples\App;

use SchedulerTest\Examples\DemoScheduleProvider;
use SchedulerTest\Execution\JsonLinesReporter;
use SchedulerTest\Execution\TaskExecutor;
use SchedulerTest\Infrastructure\Mutex\FileMutex;

/**
 * The demo application's own wiring, shared by both backends: the Laravel bootstrap, the crunz
 * task file and the crunz worker all build the executor and the provider here. So the
 * application name, environment, lock directory and journal are the same whichever backend
 * starts a task - that's what lets the mutex protect a task across Laravel and crunz too.
 *
 * Settings come from environment variables (tests point every process at its own dirs).
 * Relative paths are taken from the project root, not from the working directory: artisan
 * starts in the root, crunz in examples/crunz, and both must still meet in one lock directory.
 * Nothing here touches the disk; FileMutex and JsonLinesReporter create their files lazily.
 * No framework types on purpose.
 */
final class DemoApplication
{
    private function __construct(
        public readonly string $storage,
        public readonly string $lockDirectory,
        public readonly string $journal,
        public readonly string $application,
        public readonly string $environment,
        public readonly string $outputFile,
        public readonly float $slowSeconds,
        public readonly bool $includeBrokenTask,
        public readonly bool $providerFails,
        public readonly ?string $fakeNow,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $storage = self::path('SCHEDULER_DEMO_STORAGE', dirname(__DIR__) . '/storage');
        $fakeNow = self::setting('SCHEDULER_DEMO_FAKE_NOW', '');

        return new self(
            $storage,
            self::path('SCHEDULER_LOCK_DIR', $storage . '/locks'),
            self::path('SCHEDULER_JOURNAL', $storage . '/logs/scheduler.jsonl'),
            self::setting('SCHEDULER_APP', 'demo'),
            self::setting('SCHEDULER_ENV', 'local'),
            self::path('DEMO_OUTPUT', $storage . '/demo-output.log'),
            (float) self::setting('DEMO_SLOW_SECONDS', '5'),
            self::setting('DEMO_INCLUDE_BROKEN_TASK', '0') === '1',
            self::setting('DEMO_PROVIDER_FAILS', '0') === '1',
            $fakeNow === '' ? null : $fakeNow,
        );
    }

    /**
     * @throws \InvalidArgumentException when a setting is not usable (bad app/env name, NUL in a path...)
     */
    public function executor(): TaskExecutor
    {
        return new TaskExecutor(
            new FileMutex($this->lockDirectory),
            $this->application,
            $this->environment,
            new JsonLinesReporter($this->journal),
        );
    }

    public function provider(): DemoScheduleProvider
    {
        return new DemoScheduleProvider(
            $this->outputFile,
            $this->slowSeconds,
            $this->includeBrokenTask,
            $this->providerFails,
        );
    }

    private static function path(string $name, string $default): string
    {
        $value = self::setting($name, $default);

        return str_starts_with($value, '/') ? $value : dirname(__DIR__, 2) . '/' . $value;
    }

    private static function setting(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
