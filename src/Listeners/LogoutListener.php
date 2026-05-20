<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Auth\Events\Logout;
use Kronn\Observability\Core;

/**
 * The UserResolver memoizes its lookup result for the lifetime of an
 * execution. If a logout happens mid-request, that cache becomes stale
 * — anything emitted afterwards would still claim to belong to the
 * just-logged-out user. We reset it here so the next resolution starts
 * fresh (and will return null).
 */
class LogoutListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(Logout $event): void
    {
        $this->core->state->user?->reset();
    }
}
