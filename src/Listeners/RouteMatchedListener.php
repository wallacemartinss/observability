<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Routing\Events\RouteMatched;
use Kronn\Observability\Core;
use Kronn\Observability\Phase;
use Kronn\Observability\State\HttpState;

class RouteMatchedListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(RouteMatched $event): void
    {
        $this->core->sensors->transitionPhase(Phase::Action);

        if ($this->core->state instanceof HttpState) {
            $this->core->state->routeName = $event->route->getName();
            $this->core->state->routeAction = $event->route->getActionName();
        }
    }
}
