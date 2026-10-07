<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz\Fixtures;

use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\ScheduleProviderInterface;

/**
 * Builds the fixture schedule from FIXTURE_CASES, a JSON list of {"id", "cron", "tz"?, "sleep"?}.
 * The crunz task file and the worker both read it, like a real app reads its config twice.
 */
final class CaseProvider implements ScheduleProviderInterface
{
    public function tasks(): iterable
    {
        if (getenv('FIXTURE_PROVIDER_FAILS') === '1') {
            throw new \RuntimeException('fixture provider failure');
        }

        $cases = json_decode((string) getenv('FIXTURE_CASES'), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($cases)) {
            throw new \RuntimeException('FIXTURE_CASES must be a JSON list');
        }

        $output = (string) getenv('FIXTURE_OUTPUT');
        foreach ($cases as $case) {
            if (!is_array($case) || !is_string($case['id'] ?? null) || !is_string($case['cron'] ?? null)) {
                throw new \RuntimeException('bad fixture case');
            }
            $sleep = $case['sleep'] ?? 0;

            yield new ScheduledTask(
                $case['id'],
                new RecordingTask($output, $case['id'], is_numeric($sleep) ? (float) $sleep : 0.0),
                $case['cron'],
                new \DateTimeZone(is_string($case['tz'] ?? null) ? $case['tz'] : 'UTC'),
            );
        }
    }
}
