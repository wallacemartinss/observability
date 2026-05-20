<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Kronn\Observability\Phase;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;

/**
 * Tracks the duration of each phase by accumulating it into the state.
 * Does not emit a record — instead feeds the phase_durations field
 * consumed by Request/Command records.
 */
class PhaseSensor
{
    public function __construct(
        private readonly ExecutionState $state,
        private readonly Clock $clock,
    ) {
    }

    public function transition(Phase $next): void
    {
        $now = $this->clock->microtime();
        $previousMs = ($now - $this->state->phaseStartedAtMicrotime) * 1000.0;
        $key = $this->state->phase->value;
        $this->state->phaseDurationsMs[$key] = ($this->state->phaseDurationsMs[$key] ?? 0.0) + $previousMs;
        $this->state->phase = $next;
        $this->state->phaseStartedAtMicrotime = $now;
    }
}
