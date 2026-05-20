<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Kronn\Observability\Core;

/**
 * When a job is enqueued we inject the current trace id into its
 * payload so the worker can restore it and link the consumer trace
 * back to the producer trace.
 *
 * Wire: Queue::createPayloadUsing(fn (...): array => [...])
 */
class CreateQueuePayloadListener
{
    public function __construct(private readonly Core $core)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $connection, ?string $queue, ?array $payload): array
    {
        return [
            'kronn_parent_trace' => $this->core->state->traceId,
        ];
    }
}
