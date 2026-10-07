<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Execution;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Execution\TaskLockKey;

final class TaskLockKeyTest extends TestCase
{
    public function testKeyFormat(): void
    {
        self::assertSame('scheduler:my-app:prod:report.daily', (new TaskLockKey('my-app', 'prod'))->forTask('report.daily'));
    }

    public function testKeyMatchesMutexKeyFormat(): void
    {
        $key = (new TaskLockKey('App_1.x', 'stage-2'))->forTask('a_b-c.d');

        self::assertMatchesRegularExpression('/^[A-Za-z0-9._:-]{1,256}$/', $key);
        self::assertSame(['scheduler', 'App_1.x', 'stage-2', 'a_b-c.d'], explode(':', $key));
    }

    public function testLongestAllowedKeyFitsTheLimit(): void
    {
        $key = (new TaskLockKey(str_repeat('a', 48), str_repeat('b', 48)))->forTask(str_repeat('c', 128));

        self::assertSame(10 + 48 + 1 + 48 + 1 + 128, strlen($key));
        self::assertLessThanOrEqual(TaskLockKey::MAX_KEY_LENGTH, strlen($key));
    }

    /** @return iterable<string, array{string}> */
    public static function badComponents(): iterable
    {
        yield 'empty' => [''];
        yield 'colon' => ['a:b'];
        yield 'space' => ['a b'];
        yield 'slash' => ['a/b'];
        yield 'non-ascii' => ['прод'];
        yield '49 chars' => [str_repeat('a', 49)];
    }

    #[DataProvider('badComponents')]
    public function testRejectsBadApplication(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskLockKey($value, 'prod');
    }

    #[DataProvider('badComponents')]
    public function testRejectsBadEnvironment(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskLockKey('app', $value);
    }

    public function testAccepts48CharComponents(): void
    {
        $key = new TaskLockKey(str_repeat('a', 48), str_repeat('b', 48));

        self::assertStringStartsWith('scheduler:', $key->forTask('x'));
    }

    /** @return iterable<string, array{string}> */
    public static function badTaskIds(): iterable
    {
        yield 'empty' => [''];
        yield 'colon' => ['a:b'];
        yield 'uppercase' => ['Report'];
        yield '129 chars' => [str_repeat('a', 129)];
    }

    #[DataProvider('badTaskIds')]
    public function testRejectsBadTaskId(string $taskId): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TaskLockKey('app', 'prod'))->forTask($taskId);
    }
}
