<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Kronn\Observability\State\CliState;
use Kronn\Observability\Support\Clock;
use Symfony\Component\Console\Input\InputInterface;

use function Kronn\Observability\Support\tiny_text;

class Command
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        CliState $state,
        InputInterface $input,
        int $exitCode,
        Clock $clock,
    ): array {
        $endMicrotime = $clock->microtime();
        $commandName = (string) ($input->getFirstArgument() ?? 'unknown');

        $group = hash('xxh128', $commandName . '|' . self::exitBucket($exitCode));

        return Envelope::build($state, RecordType::Command, $endMicrotime, $group) + [
            'name' => tiny_text($commandName),
            'raw' => tiny_text((string) $input),
            'exit_code' => $exitCode,
            'duration_ms' => round(($endMicrotime - $state->startedAtMicrotime) * 1000, 3),
            'phases_ms' => $state->phaseDurationsMs,
            'is_queue_worker' => $state->isQueueWorker,
            'is_scheduler' => $state->isScheduler,
            'user' => $state->user?->details(),
            'counters' => [
                'queries' => $state->queryCount,
                'slow_queries' => $state->slowQueryCount,
                'query_duration_ms' => round($state->queryDurationMs, 3),
                'lazy_loads' => $state->lazyLoadCount,
                'cache_hits' => $state->cacheHitCount,
                'cache_misses' => $state->cacheMissCount,
                'cache_writes' => $state->cacheWriteCount,
                'outgoing_requests' => $state->outgoingRequestCount,
                'slow_outgoing_requests' => $state->slowOutgoingRequestCount,
                'outgoing_duration_ms' => round($state->outgoingRequestDurationMs, 3),
                'mail' => $state->mailCount,
                'notifications' => $state->notificationCount,
                'queued_jobs' => $state->queuedJobCount,
                'exceptions_handled' => $state->handledExceptionCount,
                'exceptions_unhandled' => $state->unhandledExceptionCount,
            ],
        ];
    }

    private static function exitBucket(int $exit): string
    {
        return match (true) {
            $exit === 0 => 'ok',
            $exit < 0 => 'fatal',
            default => 'fail',
        };
    }
}
