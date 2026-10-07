<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Contract\Exception\InvalidTaskDefinitionException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\TaskInterface;

final class ScheduledTaskTest extends TestCase
{
    public function testDefaults(): void
    {
        $task = new ScheduledTask('report.daily', self::noop(), '0 6 * * *');

        self::assertSame('report.daily', $task->id);
        self::assertSame('0 6 * * *', $task->cronExpression);
        self::assertSame('UTC', $task->timezone->getName());
        self::assertTrue($task->preventOverlap);
    }

    public function testKeepsGivenValuesAndNormalisesWhitespace(): void
    {
        $handler = self::noop();
        $tz = new \DateTimeZone('Europe/Prague');
        $task = new ScheduledTask('a', $handler, "  */5\t9-17  * jan-jun   mon-fri ", $tz, false);

        self::assertSame($handler, $task->task);
        self::assertSame('*/5 9-17 * jan-jun mon-fri', $task->cronExpression);
        self::assertSame($tz, $task->timezone);
        self::assertFalse($task->preventOverlap);
    }

    public function testIsReadonly(): void
    {
        $task = new ScheduledTask('a', self::noop(), '* * * * *');

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line deliberately writing a readonly property */
        $task->cronExpression = '0 0 * * *';
    }

    /** @return iterable<string, array{string}> */
    public static function validIds(): iterable
    {
        yield 'single char' => ['a'];
        yield 'digits first' => ['1report'];
        yield 'dots dashes underscores' => ['billing.invoice-send_v2'];
        yield 'max length' => [str_repeat('a', 128)];
    }

    #[DataProvider('validIds')]
    public function testAcceptsValidIds(string $id): void
    {
        self::assertSame($id, (new ScheduledTask($id, self::noop(), '* * * * *'))->id);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Report'];
        yield 'colon is reserved for the lock key' => ['a:b'];
        yield 'starts with dot' => ['.a'];
        yield 'space' => ['a b'];
        yield 'too long' => [str_repeat('a', 129)];
        yield 'non ascii' => ['úloha'];
        yield 'trailing newline' => ["report\n"];
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidIds(string $id): void
    {
        $this->expectException(InvalidTaskDefinitionException::class);
        new ScheduledTask($id, self::noop(), '* * * * *');
    }

    /** @return iterable<string, array{string}> */
    public static function wrongShape(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'four fields' => ['* * * *'];
        yield 'six fields (seconds/year)' => ['* * * * * *'];
        yield 'with command' => ['* * * * * /usr/bin/php'];
        yield 'macro' => ['@daily'];
    }

    #[DataProvider('wrongShape')]
    public function testRejectsWrongShape(string $cron): void
    {
        $this->expectException(InvalidTaskDefinitionException::class);
        new ScheduledTask('a', self::noop(), $cron);
    }

    /** @return iterable<string, array{string}> */
    public static function supportedCron(): iterable
    {
        yield 'every minute' => ['* * * * *'];
        yield 'numbers' => ['30 6 1 1 0'];
        yield 'lists' => ['0,15,30,45 * * * *'];
        yield 'ranges' => ['0 9-17 * * 1-5'];
        yield 'step on star' => ['*/10 * * * *'];
        yield 'step on range' => ['0-30/5 * * * *'];
        yield 'month names' => ['0 0 1 JAN,jul *'];
        yield 'month name range' => ['0 0 1 mar-OCT *'];
        yield 'day names' => ['0 0 * * MON-FRI'];
        yield 'sunday as 7' => ['0 0 * * 7'];
        yield 'mixed list' => ['0 0 * * sun,3,sat'];
    }

    #[DataProvider('supportedCron')]
    public function testAcceptsSupportedCronSyntax(string $cron): void
    {
        self::assertSame($cron, (new ScheduledTask('a', self::noop(), $cron))->cronExpression);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedCron(): iterable
    {
        yield 'L extension' => ['0 0 L * *'];
        yield 'W extension' => ['0 0 15W * *'];
        yield 'hash extension' => ['0 0 * * 5#2'];
        yield 'question mark' => ['0 0 ? * *'];
        yield 'month name in day-of-week' => ['0 0 * * JAN'];
        yield 'day name in month' => ['0 0 1 MON *'];
        yield 'name in minute' => ['MON * * * *'];
        yield 'unknown name' => ['0 0 1 FOO *'];
        yield 'step on single value' => ['5/10 * * * *'];
        yield 'empty list item' => ['1,,2 * * * *'];
        yield 'negative' => ['-1 * * * *'];
        yield 'garbage' => ['a$b * * * *'];
    }

    #[DataProvider('unsupportedCron')]
    public function testRejectsSyntaxOutsideDialect(string $cron): void
    {
        $this->expectException(InvalidCronExpressionException::class);
        new ScheduledTask('a', self::noop(), $cron);
    }

    public function testExceptionsAreOurOwnTypes(): void
    {
        self::assertTrue(is_a(InvalidTaskDefinitionException::class, \InvalidArgumentException::class, true));
        self::assertTrue(is_a(InvalidCronExpressionException::class, \InvalidArgumentException::class, true));
    }

    private static function noop(): TaskInterface
    {
        return new class () implements TaskInterface {
            public function execute(): void
            {
            }
        };
    }
}
