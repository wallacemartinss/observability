<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Console\Events\ArtisanStarting;
use Kronn\Observability\Core;
use Kronn\Observability\State\CliState;

/**
 * Fires once when Artisan boots — earlier than CommandStarting. We use
 * it to capture the raw argv, which is the only authoritative source of
 * the original command line before Symfony normalizes inputs.
 */
class ArtisanStartingListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(ArtisanStarting $event): void
    {
        if (! $this->core->state instanceof CliState) {
            return;
        }

        $argv = $_SERVER['argv'] ?? [];
        if (is_array($argv) && $argv !== []) {
            $this->core->state->rawCommand = implode(' ', array_slice(array_map('strval', $argv), 1));
        }
    }
}
