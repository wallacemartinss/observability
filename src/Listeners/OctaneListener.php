<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Kronn\Observability\Core;
use Kronn\Observability\Support\Clock;

/**
 * Octane reuses the same PHP process across requests, so our Core
 * (registered as a singleton) keeps carrying state from the previous
 * request. We hook RequestReceived to start a fresh trace and zero
 * every counter.
 *
 * The Octane RequestReceived event is class_exists()-checked at the
 * provider level because Octane is optional; the listener itself stays
 * decoupled from Octane classes.
 */
class OctaneListener
{
    public function __construct(
        private readonly Core $core,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(object $event): void
    {
        $traceId = $this->core->uuid->make();
        $executionId = $this->core->uuid->make();

        $this->core->state->resetForNextExecution($this->clock->microtime(), $traceId, $executionId);
        $this->core->sensors->setState($this->core->state);
        $this->core->buffer->flush();
    }
}
