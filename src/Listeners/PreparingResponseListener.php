<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Routing\Events\PreparingResponse;
use Kronn\Observability\Core;
use Kronn\Observability\Phase;

class PreparingResponseListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(PreparingResponse $event): void
    {
        $this->core->sensors->transitionPhase(Phase::Render);
    }
}
