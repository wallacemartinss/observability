<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;

use function Kronn\Observability\Support\tiny_text;

class QueuedJob
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        ExecutionState $state,
        JobQueueing|JobQueued $event,
        Clock $clock,
    ): array {
        $microtime = $clock->microtime();
        $payload = $event->payload();
        $jobClass = self::resolveJobClass($payload, $event->job);
        $connection = (string) ($event->connectionName ?? 'default');
        $queue = (string) ($event->queue ?? 'default');

        $group = hash('xxh128', $jobClass . '|' . $connection . '|' . $queue);

        return Envelope::build($state, RecordType::QueuedJob, $microtime, $group) + [
            'stage' => $event instanceof JobQueued ? 'queued' : 'queueing',
            'class' => tiny_text($jobClass),
            'connection' => tiny_text($connection),
            'queue' => tiny_text($queue),
            'delay_seconds' => self::resolveDelay($event),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function resolveJobClass(array $payload, mixed $job): string
    {
        if (is_object($job)) {
            return $job::class;
        }
        if (is_string($job) && $job !== '') {
            return $job;
        }
        if (isset($payload['displayName'])) {
            return (string) $payload['displayName'];
        }

        return 'Unknown';
    }

    private static function resolveDelay(JobQueueing|JobQueued $event): ?int
    {
        if (! property_exists($event, 'delay')) {
            return null;
        }
        $delay = $event->delay; // @phpstan-ignore property.notFound

        if ($delay === null) {
            return null;
        }
        if (is_int($delay)) {
            return $delay;
        }
        if (is_object($delay) && method_exists($delay, 'getTimestamp')) {
            return $delay->getTimestamp() - time();
        }

        return null;
    }
}
