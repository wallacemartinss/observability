<?php

declare(strict_types=1);

namespace Kronn\Observability\Contracts;

/**
 * Transport contract: what happens to a record once it leaves the buffer
 * (or is shipped right away). Implementations are plugged in via the
 * "drivers" array of the package config.
 */
interface Transport
{
    /**
     * Ship a batch of records in one shot. Implementations must swallow
     * errors silently — telemetry must never break the host application.
     *
     * @param  list<array<string, mixed>>  $records
     */
    public function ship(array $records): void;

    /**
     * Connectivity probe. Implementations with remote I/O return true
     * when the destination is reachable; no-op transports return true
     * as well.
     */
    public function probe(): bool;
}
