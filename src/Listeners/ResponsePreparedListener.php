<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Routing\Events\ResponsePrepared;
use Kronn\Observability\Core;
use Kronn\Observability\Phase;

class ResponsePreparedListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(ResponsePrepared $event): void
    {
        $this->core->sensors->transitionPhase(Phase::Respond);
    }
}
