<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Kronn\Observability\Core;
use Kronn\Observability\State\CliState;

/**
 * Fires inside a queue worker right before each job runs. We:
 *
 *  1. Reset the Core's state (counters, phase durations, tags, extras)
 *     so the previous job does not leak into this one.
 *  2. Generate a fresh trace id for the job execution and link it to
 *     the producer-side trace via parent_trace_id (read from the
 *     payload key injected by CreateQueuePayloadListener).
 *
 * This is what makes distributed tracing work across the producer
 * (whoever called dispatch()) and the consumer (the queue worker).
 */
class JobProcessingListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(JobProcessing $event): void
    {
        if (! $this->core->state instanceof CliState) {
            return;
        }

        $payload = $event->job->payload();
        $parentTrace = isset($payload['kronn_parent_trace']) && is_string($payload['kronn_parent_trace'])
            ? $payload['kronn_parent_trace']
            : null;

        $newTrace = $this->core->uuid->make();
        $newExecutionId = $this->core->uuid->make();

        $this->core->resetForNextExecution($newTrace, $newExecutionId);
        $this->core->state->parentTraceId = $parentTrace;
        $this->core->state->source = 'queue';
    }
}
