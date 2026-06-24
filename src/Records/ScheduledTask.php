<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Kronn\Observability\State\CliState;
use Kronn\Observability\Support\Clock;

use function Kronn\Observability\Support\tiny_text;

class ScheduledTask
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        CliState $state,
        ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event,
        Clock $clock,
    ): array {
        $microtime = $clock->microtime();
        $task = $event->task;
        // Laravel's scheduler exposes the human-friendly label via the public
        // $description property; description() is a *setter* (one required arg),
        // so it must never be called as a getter. Fall back to the command.
        $description = match (true) {
            property_exists($task, 'description') && $task->description !== null => (string) $task->description,
            property_exists($task, 'command') && is_string($task->command) => $task->command,
            default => 'unknown',
        };
        $expression = property_exists($task, 'expression') ? (string) $task->expression : '';

        $outcome = match (true) {
            $event instanceof ScheduledTaskFailed => 'failed',
            $event instanceof ScheduledTaskSkipped => 'skipped',
            $event instanceof ScheduledTaskFinished => 'finished',
        };

        $group = hash('xxh128', $description . '|' . $expression . '|' . $outcome);

        return Envelope::build($state, RecordType::ScheduledTask, $microtime, $group) + [
            'outcome' => $outcome,
            'description' => tiny_text($description),
            'expression' => tiny_text($expression),
            'duration_ms' => $event instanceof ScheduledTaskFinished
                ? round(($microtime - $state->startedAtMicrotime) * 1000, 3)
                : null,
            'exception_class' => $event instanceof ScheduledTaskFailed
                ? $event->exception::class
                : null,
        ];
    }
}
