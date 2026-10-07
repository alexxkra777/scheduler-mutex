<?php

declare(strict_types=1);

namespace SchedulerTest\Infrastructure\Laravel;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use SchedulerTest\Contract\Exception\DuplicateTaskIdException;
use SchedulerTest\Contract\Exception\InvalidCronExpressionException;
use SchedulerTest\Contract\Exception\SchedulerIntegrationException;
use SchedulerTest\Contract\ScheduledTask;
use SchedulerTest\Contract\SchedulerInterface;
use SchedulerTest\Cron\CronDialect;

/**
 * Laravel 12 adapter: turns each ScheduledTask into a native callback event.
 *
 * Laravel's own `schedule:run` decides what is due (cron + timezone) and calls the
 * event in-process. The event only hands the task to the runner closure (the shared
 * executor), which owns our mutex. Laravel's mutexes stay off: no withoutOverlapping(),
 * no onOneServer(), no background or queue features.
 *
 * Native defaults are kept on purpose. In maintenance mode (`php artisan down`)
 * events are not due, so nothing runs until the app is up again.
 *
 * Errors: whatever the runner throws goes to Laravel untouched - schedule:run then
 * fires ScheduledTaskFailed, reports it through the ExceptionHandler and moves on to
 * the next due event (its exit code stays 0).
 *
 * If register() throws SchedulerIntegrationException, the native Schedule may already
 * hold a half-built event (Schedule has no remove API). Don't keep using that schedule:
 * let the exception escape the Schedule extender, so the container never caches the
 * failed schedule and the artisan command fails before looking at any event.
 */
final class LaravelScheduler implements SchedulerInterface
{
    /** @var array<string, true> ids that already went to the native schedule */
    private array $registered = [];

    /**
     * @param \Closure(ScheduledTask): void $runner shared executor; its result is ignored, its exceptions are not
     */
    public function __construct(
        private readonly Schedule $schedule,
        private readonly \Closure $runner,
    ) {
    }

    public function register(ScheduledTask $task): void
    {
        if (isset($this->registered[$task->id])) {
            throw DuplicateTaskIdException::forId($task->id);
        }

        // Laravel gets the numeric form (names resolved, steps checked) - the parser mishandles
        // names in stepped ranges. The very same string is validated here and registered below.
        try {
            $expression = CronDialect::toNumeric($task->cronExpression);
        } catch (InvalidCronExpressionException $e) {
            throw new InvalidCronExpressionException(sprintf('Task "%s": %s', $task->id, $e->getMessage()), 0, $e);
        }

        // same parser Laravel uses in Event::isDue(), so what passes here is what it evaluates later
        try {
            // getNextRunDate() also catches expressions that can never match, like "0 0 31 2 *" -
            // the constructor alone accepts them and schedule:list then dies on them
            (new CronExpression($expression))->getNextRunDate('now', 0, false, $task->timezone->getName());
        } catch (\Throwable $e) {
            throw new InvalidCronExpressionException(
                sprintf('Task "%s": cron expression "%s" rejected by the parser: %s', $task->id, $task->cronExpression, $e->getMessage()),
                0,
                $e,
            );
        }

        // the id is taken from here on, even if Laravel fails below - a retry would only add a second event
        $this->registered[$task->id] = true;
        $eventsBefore = count($this->schedule->events());

        try {
            $event = $this->schedule
                ->call($this->callbackFor($task))
                ->cron($expression)
                ->timezone($task->timezone)
                ->name($task->id);

            $this->assertNoNativeExtras($event);
        } catch (\Throwable $e) {
            $leftover = count($this->schedule->events()) > $eventsBefore
                ? ' A half-built event was left in the native schedule, do not run it.'
                : '';

            throw new SchedulerIntegrationException(
                sprintf('Task "%s": Laravel failed to register the event: %s.%s', $task->id, rtrim($e->getMessage(), '.'), $leftover),
                0,
                $e,
            );
        }
    }

    private function callbackFor(ScheduledTask $task): \Closure
    {
        $runner = $this->runner;

        // Returns nothing on purpose: Laravel counts a `false` result as a failed run.
        // Exceptions are not caught here, Laravel has to see them.
        return static function () use ($runner, $task): void {
            $runner($task);
        };
    }

    /**
     * A Schedule::group() around register() would merge its attributes into our event.
     * Native overlap/one-server locks, maintenance or environment rules and when()/skip()
     * filters must not sneak in.
     */
    private function assertNoNativeExtras(Event $event): void
    {
        // when()/skip() filters are protected; peek at them, a silently filtered task would never run or log
        $filterCount = (fn (): int => count($this->filters) + count($this->rejects))->call($event);

        if ($event->withoutOverlapping || $event->onOneServer || $event->evenInMaintenanceMode
            || $event->runInBackground || $event->environments !== [] || $event->repeatSeconds !== null
            || $filterCount > 0) {
            throw new \LogicException('the event picked up native attributes (e.g. from Schedule::group()) that this adapter does not allow');
        }
    }
}
