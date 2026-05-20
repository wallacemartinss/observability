<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Kronn\Observability\Core;
use Kronn\Observability\Phase;

/**
 * Fires once the container has finished booting. On HTTP requests the
 * next phase is Routing; on CLI we go straight to Action.
 */
class BootListener
{
    public function __construct(private readonly Core $core, private readonly bool $isHttp)
    {
    }

    public function __invoke(): void
    {
        $this->core->sensors->transitionPhase($this->isHttp ? Phase::Routing : Phase::Action);
    }
}
