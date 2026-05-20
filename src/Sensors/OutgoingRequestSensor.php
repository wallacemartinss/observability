<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Kronn\Observability\Records\OutgoingRequest as OutgoingRequestRecord;
use Kronn\Observability\State\ExecutionState;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class OutgoingRequestSensor
{
    public function __construct(
        private readonly ExecutionState $state,
        private readonly float $slowThresholdMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        float $startMicrotime,
        float $endMicrotime,
        RequestInterface $request,
        ResponseInterface $response,
    ): array {
        $duration = ($endMicrotime - $startMicrotime) * 1000.0;
        $this->state->outgoingRequestCount++;
        $this->state->outgoingRequestDurationMs += $duration;
        if ($duration >= $this->slowThresholdMs) {
            $this->state->slowOutgoingRequestCount++;
        }

        return OutgoingRequestRecord::make(
            state: $this->state,
            startMicrotime: $startMicrotime,
            endMicrotime: $endMicrotime,
            request: $request,
            response: $response,
            slowThresholdMs: $this->slowThresholdMs,
        );
    }
}
