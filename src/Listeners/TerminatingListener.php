<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Kronn\Observability\Core;
use Kronn\Observability\Phase;

class TerminatingListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(): void
    {
        $this->core->sensors->transitionPhase(Phase::Terminate);
        $this->core->digest();
        $this->core->sensors->transitionPhase(Phase::Done);
    }
}
