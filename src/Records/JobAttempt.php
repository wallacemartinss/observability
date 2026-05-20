<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Kronn\Observability\State\CliState;
use Kronn\Observability\Support\Clock;

use function Kronn\Observability\Support\tiny_text;

class JobAttempt
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        CliState $state,
        JobProcessed|JobReleasedAfterException|JobFailed $event,
        Clock $clock,
    ): array {
        $microtime = $clock->microtime();
        $job = $event->job;
        $payload = $job->payload();

        $outcome = match (true) {
            $event instanceof JobProcessed => 'processed',
            $event instanceof JobFailed => 'failed',
            $event instanceof JobReleasedAfterException => 'released',
        };

        $jobClass = $payload['displayName'] ?? $job->resolveName();
        $connection = (string) $event->connectionName;
        $queue = (string) $job->getQueue();

        $group = hash('xxh128', $jobClass . '|' . $connection . '|' . $queue . '|' . $outcome);

        return Envelope::build($state, RecordType::JobAttempt, $microtime, $group) + [
            'outcome' => $outcome,
            'class' => tiny_text((string) $jobClass),
            'connection' => tiny_text($connection),
            'queue' => tiny_text($queue),
            'attempts' => $job->attempts(),
            'duration_ms' => round(($microtime - $state->startedAtMicrotime) * 1000, 3),
            'exception_class' => $event instanceof JobFailed ? $event->exception::class : null,
            'exception_message' => $event instanceof JobFailed ? tiny_text($event->exception->getMessage()) : null,
        ];
    }
}
