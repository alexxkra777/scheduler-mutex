<?php

declare(strict_types=1);

namespace SchedulerTest\Execution;

use SchedulerTest\Contract\ScheduledTask;

/**
 * Builds mutex keys as `scheduler:<application>:<environment>:<task-id>`.
 *
 * None of the parts may contain a colon, so a key always splits back the same way
 * and two apps/environments sharing one lock directory never collide.
 */
final class TaskLockKey
{
    public const PREFIX = 'scheduler';
    public const COMPONENT_PATTERN = '/\A[a-zA-Z0-9._-]{1,48}\z/';
    public const MAX_KEY_LENGTH = 256;

    /**
     * @throws \InvalidArgumentException when application or environment is not 1-48 chars of [a-zA-Z0-9._-]
     */
    public function __construct(
        private readonly string $application,
        private readonly string $environment,
    ) {
        self::assertComponent('application', $application);
        self::assertComponent('environment', $environment);
    }

    /**
     * @throws \InvalidArgumentException when the task id is malformed or the key gets too long
     */
    public function forTask(string $taskId): string
    {
        if (preg_match(ScheduledTask::ID_PATTERN, $taskId) !== 1) {
            throw new \InvalidArgumentException(sprintf('Task id "%s" cannot be used in a lock key.', $taskId));
        }

        $key = implode(':', [self::PREFIX, $this->application, $this->environment, $taskId]);

        // with today's limits the longest key is 236 bytes, this guards against someone bumping them later
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Lock key for task "%s" is %d bytes, the limit is %d.',
                $taskId,
                strlen($key),
                self::MAX_KEY_LENGTH,
            ));
        }

        return $key;
    }

    private static function assertComponent(string $name, string $value): void
    {
        if (preg_match(self::COMPONENT_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Lock key %s "%s" is invalid: expected 1-48 chars of [a-zA-Z0-9._-] (no colon).',
                $name,
                $value,
            ));
        }
    }
}
