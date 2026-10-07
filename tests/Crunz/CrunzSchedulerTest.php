<?php

declare(strict_types=1);

namespace SchedulerTest\Tests\Crunz;

use Crunz\Event;
use Crunz\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SchedulerTest\Contract\Exception\DuplicateTaskIdException;
use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Contract\Exception\SchedulerIntegrationException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Infrastructure\Crunz\CrunzScheduler;
use SchedulerTest\Tests\Laravel\Fixtures\NoopTask;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Registration against a real Crunz\Schedule, no processes. What runs when is checked with the
 * real `crunz schedule:run` in CrunzCronRunTest and CrunzDemoTest.
 */
final class CrunzSchedulerTest extends TestCase
{
    private const WORKER = __DIR__ . '/Fixtures/app/worker.php';

    public function testRegistersOneWorkerEventPerTask(): void
    {
        $schedule = new Schedule();
        $scheduler = new CrunzScheduler($schedule, self::WORKER, PHP_BINARY);

        $scheduler->register(new ScheduledTask('report.daily', new NoopTask(), '30 6 * * MON-FRI', new \DateTimeZone('Europe/Prague')));

        $events = array_values($schedule->events());
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame(
            "exec '" . PHP_BINARY . "' '" . self::WORKER . "' 'task:run' 'report.daily'",
            $event->buildCommand(),
        );
        self::assertSame('30 6 * * 1,2,3,4,5', $event->getExpression());
        self::assertSame('report.daily', $event->description);
        self::assertSame('Europe/Prague', self::property($event, 'timezone'));
        // crunz's own overlap lock stays off - our mutex in the worker does that job
        self::assertFalse(self::property($event, 'preventOverlapping'));
        self::assertFalse($event->isClosure());
    }

    public function testDefaultPhpBinaryIsTheCurrentOne(): void
    {
        $schedule = new Schedule();
        (new CrunzScheduler($schedule, self::WORKER))->register(new ScheduledTask('a', new NoopTask(), '* * * * *'));

        self::assertStringStartsWith("exec '" . PHP_BINARY . "' ", array_values($schedule->events())[0]->buildCommand());
    }

    public function testQuotesPathsWithSpacesAndQuotes(): void
    {
        $dir = sys_get_temp_dir() . '/crunz adapter ' . bin2hex(random_bytes(4)) . " it's \"quoted\"";
        mkdir($dir);
        $worker = $dir . '/worker.php';
        touch($worker);
        $php = $dir . '/php bin';
        touch($php);
        chmod($php, 0755);

        try {
            $schedule = new Schedule();
            (new CrunzScheduler($schedule, $worker, $php))->register(new ScheduledTask('a', new NoopTask(), '* * * * *'));
            $command = array_values($schedule->events())[0]->buildCommand();
        } finally {
            unlink($worker);
            unlink($php);
            rmdir($dir);
        }

        $quotedPhp = "'" . str_replace("'", "'\\''", $dir . '/php bin') . "'";
        self::assertStringStartsWith("exec $quotedPhp ", $command);

        // the shell has to hand exactly these argv entries to php: print them instead of running php
        $out = shell_exec("printf '%s\\n' " . substr($command, strlen("exec $quotedPhp ")));
        self::assertSame($worker . "\ntask:run\na\n", $out);
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function placeholderPaths(): iterable
    {
        $placeholder = 'quoted "${:AUDIT_PATH}" dir';
        foreach (['AUDIT_PATH not set' => [], 'AUDIT_PATH set' => ['AUDIT_PATH' => 'ordinary']] as $name => $env) {
            yield "worker, $name" => [$placeholder, 'plain', $env];
            yield "php, $name" => ['plain', $placeholder, $env];
            yield "both, $name" => [$placeholder, $placeholder, $env];
            yield "dollar sign mix, $name" => ['a $HOME "${x}" \'${:A}\' $', 'b "${:AUDIT_PATH}"${:AUDIT_PATH}', $env];
        }
    }

    /**
     * The event's command goes through crunz's Event::buildCommand(), then Symfony's
     * Process::fromShellCommandline() (which replaces "${:NAME}" placeholders on start), then sh.
     * A stand-in "php" prints the argv it really received.
     *
     * @param array<string, string> $env
     */
    #[DataProvider('placeholderPaths')]
    public function testCommandSurvivesSymfonyPlaceholdersAndTheShell(string $workerDir, string $phpDir, array $env): void
    {
        self::assertFalse(getenv('AUDIT_PATH'), 'the test controls AUDIT_PATH itself');
        $root = sys_get_temp_dir() . '/crunz-literal-' . bin2hex(random_bytes(4));
        mkdir($root . '/w/' . $workerDir, 0777, true);
        mkdir($root . '/p/' . $phpDir, 0777, true);
        $worker = $root . '/w/' . $workerDir . '/worker.php';
        touch($worker);
        $php = $root . '/p/' . $phpDir . '/php';
        file_put_contents($php, "#!/bin/sh\nprintf '%s\\n' \"\$0\" \"\$@\"\n");
        chmod($php, 0755);

        try {
            $schedule = new Schedule();
            (new CrunzScheduler($schedule, $worker, $php))->register(new ScheduledTask('report.daily', new NoopTask(), '* * * * *'));
            $command = array_values($schedule->events())[0]->buildCommand();

            $process = SymfonyProcess::fromShellCommandline($command, null, $env);
            $process->run();
        } finally {
            unlink($worker);
            unlink($php);
            rmdir($root . '/w/' . $workerDir);
            rmdir($root . '/p/' . $phpDir);
            rmdir($root . '/w');
            rmdir($root . '/p');
            rmdir($root);
        }

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame("$php\n$worker\ntask:run\nreport.daily\n", $process->getOutput());
        self::assertStringStartsWith('exec ', $command, 'the shell must hand over to php, not stay in between');
    }

    /** @return iterable<string, array{string, string}> */
    public static function numericForms(): iterable
    {
        // the old troublesome expressions: crunz gets the very same numeric form Laravel gets
        yield 'named weekday range with step' => ['0 0 * * MON-FRI/2', '0 0 * * 1,3,5'];
        yield 'Sunday range by name' => ['0 0 * * SUN-SUN', '0 0 * * 0'];
        yield 'Sunday range 7-7' => ['0 0 * * 7-7', '0 0 * * 0'];
        yield 'week ending on Sunday' => ['0 0 * * MON-SUN', '0 0 * * 0,1,2,3,4,5,6'];
        yield 'minute step 59' => ['*/59 * * * *', '0,59 * * * *'];
        yield 'minute range step 59' => ['10-20/59 * * * *', '10 * * * *'];
        yield 'hour range step 23' => ['0 1-2/23 * * *', '0 1 * * *'];
        yield 'named month step' => ['0 0 1 JAN-DEC/2 *', '0 0 1 1-12/2 *'];
        yield 'dom OR dow stays restricted' => ['0 0 13 * MON', '0 0 13 * 1'];
        yield 'plain' => ['*/5 9-17 * * *', '0,5,10,15,20,25,30,35,40,45,50,55 9-17 * * *'];
    }

    #[DataProvider('numericForms')]
    public function testRegistersTheNumericForm(string $cron, string $expected): void
    {
        $schedule = new Schedule();
        (new CrunzScheduler($schedule, self::WORKER))->register(new ScheduledTask('a', new NoopTask(), $cron));

        self::assertSame($expected, array_values($schedule->events())[0]->getExpression());
    }

    /** @return iterable<string, array{string}> */
    public static function parserRejected(): iterable
    {
        yield 'minute 61' => ['61 * * * *'];
        yield 'month 13' => ['0 0 1 13 *'];
        yield 'day 32' => ['0 0 32 * *'];
        yield 'Feb 31 never happens' => ['0 0 31 2 *'];
        yield 'Apr 31 never happens' => ['0 0 31 4 *'];
    }

    #[DataProvider('parserRejected')]
    public function testRejectsBeforeCreatingAnEvent(string $cron): void
    {
        $schedule = new Schedule();
        $scheduler = new CrunzScheduler($schedule, self::WORKER);
        $scheduler->register(new ScheduledTask('ok', new NoopTask(), '* * * * *'));

        try {
            $scheduler->register(new ScheduledTask('bad', new NoopTask(), $cron));
            self::fail('Expected InvalidCronExpressionException');
        } catch (InvalidCronExpressionException $e) {
            self::assertStringContainsString('"bad"', $e->getMessage());
        }

        self::assertCount(1, $schedule->events());
        // a rejected id is not taken - the corrected definition can still be registered
        $scheduler->register(new ScheduledTask('bad', new NoopTask(), '0 0 1 1 *'));
        self::assertCount(2, $schedule->events());
    }

    /** @return iterable<string, array{string}> */
    public static function dialectRejected(): iterable
    {
        yield 'ambiguous dow step with restricted dom' => ['0 0 13 * */2'];
        yield 'ambiguous dom step with restricted dow' => ['0 0 */2 * MON'];
        yield 'step above field max' => ['*/60 * * * *'];
        yield 'macro' => ['@daily'];
        yield 'L extension' => ['0 0 L * *'];
    }

    // rejected by the ScheduledTask constructor already - no adapter, no event can be involved
    #[DataProvider('dialectRejected')]
    public function testDialectErrorsNeverReachTheAdapter(string $cron): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ScheduledTask('a', new NoopTask(), $cron);
    }

    public function testDuplicateIdIsRejectedAndAddsNoEvent(): void
    {
        $schedule = new Schedule();
        $scheduler = new CrunzScheduler($schedule, self::WORKER);
        $scheduler->register(new ScheduledTask('a', new NoopTask(), '* * * * *'));

        $this->expectException(DuplicateTaskIdException::class);
        try {
            $scheduler->register(new ScheduledTask('a', new NoopTask(), '0 * * * *'));
        } finally {
            self::assertCount(1, $schedule->events());
        }
    }

    public function testNativeFailureBecomesIntegrationErrorWithoutLeftoverEvent(): void
    {
        $schedule = new class() extends Schedule {
            public function run($command, array $parameters = [])
            {
                parent::run($command, $parameters); // the event is already in the list
                throw new \RuntimeException('crunz said no');
            }
        };
        $scheduler = new CrunzScheduler($schedule, self::WORKER);

        try {
            $scheduler->register(new ScheduledTask('a', new NoopTask(), '* * * * *'));
            self::fail('Expected SchedulerIntegrationException');
        } catch (SchedulerIntegrationException $e) {
            self::assertStringContainsString('crunz said no', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }

        self::assertSame([], $schedule->events());
        // the id stays taken: the same definition again would only repeat the failure
        $this->expectException(DuplicateTaskIdException::class);
        $scheduler->register(new ScheduledTask('a', new NoopTask(), '* * * * *'));
    }

    /** @return iterable<string, array{string, ?string, string}> */
    public static function badPaths(): iterable
    {
        yield 'empty worker' => ['', null, 'must not be empty'];
        yield 'NUL in worker' => [self::WORKER . "\0", null, 'NUL'];
        yield 'relative worker' => ['tests/Crunz/Fixtures/app/worker.php', null, 'absolute'];
        yield 'missing worker' => [__DIR__ . '/Fixtures/app/missing.php', null, 'not a file'];
        yield 'worker is a directory' => [__DIR__, null, 'not a file'];
        yield 'empty php' => [self::WORKER, '', 'must not be empty'];
        yield 'NUL in php' => [self::WORKER, PHP_BINARY . "\0", 'NUL'];
        // a php that can't start would only show up later as a failed shell command, with crunz exiting 0
        yield 'relative php' => [self::WORKER, 'php', 'absolute'];
        yield 'missing php' => [self::WORKER, '/nonexistent/php', 'not an executable file'];
        yield 'php is a directory' => [self::WORKER, __DIR__, 'not an executable file'];
        yield 'php not executable' => [self::WORKER, __FILE__, 'not an executable file'];
    }

    #[DataProvider('badPaths')]
    public function testConstructorRejectsUnusablePaths(string $worker, ?string $php, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new CrunzScheduler(new Schedule(), $worker, $php);
    }

    private static function property(Event $event, string $name): mixed
    {
        return (fn (): mixed => $this->{$name})->call($event);
    }
}
