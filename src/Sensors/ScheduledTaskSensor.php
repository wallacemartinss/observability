<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Kronn\Observability\Records\ScheduledTask as ScheduledTaskRecord;
use Kronn\Observability\State\CliState;
use Kronn\Observability\Support\Clock;

class ScheduledTaskSensor
{
    public function __construct(
        private readonly CliState $state,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): array
    {
        return ScheduledTaskRecord::make($this->state, $event, $this->clock);
    }
}
