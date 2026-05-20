<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Kronn\Observability\Core;
use Kronn\Observability\Phase;
use Kronn\Observability\Records\RecordType;
use Kronn\Observability\State\CliState;

class CommandFinishedListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(CommandFinished $event): void
    {
        if (! $this->core->state instanceof CliState) {
            return;
        }

        $this->core->sensors->transitionPhase(Phase::Terminate);

        $exitCode = (int) ($event->exitCode ?? 0);
        $input = $event->input;

        $this->core->record(
            RecordType::Command,
            fn () => $this->core->sensors->command($input, $exitCode),
        );

        $this->core->digest();
        $this->core->sensors->transitionPhase(Phase::Done);
    }
}
