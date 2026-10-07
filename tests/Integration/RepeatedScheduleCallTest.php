<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Two `schedule:run` calls in one application/kernel. A failed schedule build must fail every
 * time and run nothing; a good one must run the due tasks each time without registering twice.
 */
final class RepeatedScheduleCallTest extends DemoProcessTestCase
{
    /** @return iterable<string, array{array<string, string>, string}> */
    public static function brokenConfigurations(): iterable
    {
        // valid tasks are registered first, then the parser rejects the broken one
        yield 'invalid cron during registration' => [['DEMO_INCLUDE_BROKEN_TASK' => '1'], 'InvalidCronExpressionException'];
        // nothing registered at all, the provider throws while being read
        yield 'provider fails before registration' => [['DEMO_PROVIDER_FAILS' => '1'], 'ScheduleConfigurationException'];
    }

    /** @param array<string, string> $env */
    #[DataProvider('brokenConfigurations')]
    public function testEveryCallFailsAndNothingRuns(array $env, string $exception): void
    {
        $pid = $this->startPhpScript(dirname(__DIR__) . '/Fixtures/repeated-schedule-calls.php', $env);
        self::assertSame(0, $this->finish($pid), $this->stderrOf($pid));

        $out = $this->stdoutOf($pid);
        self::assertStringContainsString("attempt=1 exception=SchedulerTest\\Contract\\Exception\\$exception", $out);
        self::assertStringContainsString("attempt=2 exception=SchedulerTest\\Contract\\Exception\\$exception", $out, 'second call must not get a cached half-built schedule');
        self::assertStringNotContainsString('exit=', $out, 'no attempt may finish, not even with an empty schedule');
        self::assertSame([], $this->journal());
        self::assertSame([], $this->outputLines());
    }

    public function testValidScheduleRunsOnEveryCallWithoutDuplicateEvents(): void
    {
        $pid = $this->startPhpScript(dirname(__DIR__) . '/Fixtures/repeated-schedule-calls.php');
        self::assertSame(0, $this->finish($pid), $this->stderrOf($pid));

        $out = $this->stdoutOf($pid);
        self::assertStringContainsString('attempt=1 exit=0 events=4', $out);
        self::assertStringContainsString('attempt=2 exit=0 events=4', $out);

        // both calls ran the three due tasks once each - repeated calls are not deduplicated
        $tasks = array_count_values(array_map(static fn (array $row): string => (string) $row['task'], $this->journal($pid)));
        ksort($tasks);
        self::assertSame(['failing-task' => 2, 'heartbeat' => 2, 'slow-report' => 2], $tasks);
    }
}
