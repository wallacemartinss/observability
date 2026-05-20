<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Kronn\Observability\Records\QueuedJob as QueuedJobRecord;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;

class QueuedJobSensor
{
    public function __construct(
        private readonly ExecutionState $state,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(JobQueueing|JobQueued $event): ?array
    {
        if ($event instanceof JobQueued) {
            $this->state->queuedJobCount++;
        }

        // We only emit the record on the "queued" stage — the "queueing"
        // stage exists only to inject the trace id into the payload,
        // which is handled by CreateQueuePayloadListener.
        if (! $event instanceof JobQueued) {
            return null;
        }

        return QueuedJobRecord::make($this->state, $event, $this->clock);
    }
}
