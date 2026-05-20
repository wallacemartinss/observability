<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Illuminate\Database\Events\QueryExecuted;
use Kronn\Observability\Records\Query as QueryRecord;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\Location;

class QuerySensor
{
    public function __construct(
        private readonly ExecutionState $state,
        private readonly Clock $clock,
        private readonly Location $location,
        private readonly float $slowThresholdMs,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(QueryExecuted $event): array
    {
        $duration = (float) $event->time;
        $this->state->queryCount++;
        $this->state->queryDurationMs += $duration;
        if ($duration >= $this->slowThresholdMs) {
            $this->state->slowQueryCount++;
        }

        $origin = $this->captureOrigin();

        return QueryRecord::make(
            state: $this->state,
            event: $event,
            origin: $origin,
            slowThresholdMs: $this->slowThresholdMs,
            clock: $this->clock,
            location: $this->location,
        );
    }

    /**
     * Walks the stack backwards looking for the first frame that lives
     * in application code.
     *
     * @return array{file?: string, line?: int}
     */
    private function captureOrigin(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);

        foreach ($trace as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file !== '' && $this->location->isAppCode($file)) {
                return ['file' => $file, 'line' => (int) ($frame['line'] ?? 0)];
            }
        }

        return [];
    }
}
