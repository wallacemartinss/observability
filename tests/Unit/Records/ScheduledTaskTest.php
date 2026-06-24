<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Records;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as SchedulingEvent;
use Illuminate\Console\Scheduling\EventMutex;
use Kronn\Observability\Records\ScheduledTask;
use Kronn\Observability\State\CliState;
use Kronn\Observability\Support\Clock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ScheduledTaskTest extends TestCase
{
    /**
     * Regression: Laravel's scheduler exposes the human-friendly label via
     * the public $description property; description() is a *setter* that
     * requires one argument. Calling it as a getter throws ArgumentCountError
     * and crashes schedule:run. make() must read the property instead.
     */
    public function test_reads_description_from_the_property_without_calling_the_setter(): void
    {
        $task = (new SchedulingEvent($this->mutex(), 'php artisan inspire'))
            ->description('Send daily report');

        $record = ScheduledTask::make(
            $this->state(),
            new ScheduledTaskFinished($task, 1.5),
            $this->clock(1000.5),
        );

        self::assertSame('Send daily report', $record['description']);
        self::assertSame('finished', $record['outcome']);
        self::assertEqualsWithDelta(500.0, $record['duration_ms'], 0.001);
    }

    public function test_falls_back_to_the_command_when_the_description_is_null(): void
    {
        $task = new SchedulingEvent($this->mutex(), 'php artisan queue:work');

        $record = ScheduledTask::make(
            $this->state(),
            new ScheduledTaskFinished($task, 0.0),
            $this->clock(1000.0),
        );

        self::assertSame('php artisan queue:work', $record['description']);
        self::assertSame('* * * * *', $record['expression']);
        self::assertSame(0.0, $record['duration_ms']);
    }

    public function test_records_the_outcome_and_exception_class_for_a_failed_task(): void
    {
        $task = (new SchedulingEvent($this->mutex(), 'php artisan import:feed'))
            ->description('Import feed');

        $record = ScheduledTask::make(
            $this->state(),
            new ScheduledTaskFailed($task, new RuntimeException('boom')),
            $this->clock(1000.0),
        );

        self::assertSame('Import feed', $record['description']);
        self::assertSame('failed', $record['outcome']);
        self::assertSame(RuntimeException::class, $record['exception_class']);
        self::assertNull($record['duration_ms']);
    }

    public function test_records_a_skipped_outcome(): void
    {
        $task = (new SchedulingEvent($this->mutex(), 'php artisan report:build'))
            ->description('Build report');

        $record = ScheduledTask::make(
            $this->state(),
            new ScheduledTaskSkipped($task),
            $this->clock(1000.0),
        );

        self::assertSame('skipped', $record['outcome']);
        self::assertNull($record['duration_ms']);
    }

    private function mutex(): EventMutex
    {
        return new class implements EventMutex
        {
            public function create(SchedulingEvent $event)
            {
                return true;
            }

            public function exists(SchedulingEvent $event)
            {
                return false;
            }

            public function forget(SchedulingEvent $event)
            {
                //
            }
        };
    }

    private function state(): CliState
    {
        return new CliState(
            startedAtMicrotime: 1000.0,
            traceId: 'trace-1',
            executionId: 'exec-1',
            deployment: 'deploy-1',
            environment: 'testing',
            server: 'cli-01',
        );
    }

    private function clock(float $now): Clock
    {
        return new Clock(fn (): float => $now);
    }
}
