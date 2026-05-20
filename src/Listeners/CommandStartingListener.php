<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Console\Events\CommandStarting;
use Kronn\Observability\Core;
use Kronn\Observability\Phase;
use Kronn\Observability\State\CliState;

class CommandStartingListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(CommandStarting $event): void
    {
        $this->core->sensors->transitionPhase(Phase::Action);

        if ($this->core->state instanceof CliState) {
            $this->core->state->commandName = (string) $event->command;
            $this->core->state->rawCommand = (string) $event->input;
            $this->core->state->isQueueWorker = str_starts_with((string) $event->command, 'queue:work');
        }
    }
}
