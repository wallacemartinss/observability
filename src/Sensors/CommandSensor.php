<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Kronn\Observability\Records\Command as CommandRecord;
use Kronn\Observability\State\CliState;
use Kronn\Observability\Support\Clock;
use Symfony\Component\Console\Input\InputInterface;

class CommandSensor
{
    public function __construct(
        private readonly CliState $state,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(InputInterface $input, int $exitCode): array
    {
        $this->state->exitCode = $exitCode;
        $this->state->commandName = (string) ($input->getFirstArgument() ?? 'unknown');
        $this->state->rawCommand = (string) $input;

        return CommandRecord::make($this->state, $input, $exitCode, $this->clock);
    }
}
