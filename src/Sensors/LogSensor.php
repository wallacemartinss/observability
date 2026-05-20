<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Kronn\Observability\Records\Log as LogRecord;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Monolog\LogRecord as MonologRecord;

class LogSensor
{
    public function __construct(
        private readonly ExecutionState $state,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(MonologRecord $record): array
    {
        $this->state->recordLog(strtolower($record->level->getName()));

        return LogRecord::make($this->state, $record, $this->clock);
    }
}
