<?php

declare(strict_types=1);

namespace Kronn\Observability\Transports;

use Kronn\Observability\Contracts\Transport;

/**
 * Discards everything silently. Useful in tests and in environments
 * where telemetry must be installed but inert (e.g. feature flag off).
 */
class NullTransport implements Transport
{
    public function ship(array $records): void
    {
        // intentional
    }

    public function probe(): bool
    {
        return true;
    }
}
