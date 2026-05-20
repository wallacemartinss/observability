<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;
use Kronn\Observability\State\CliState;

class ScheduledTaskListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): void
    {
        if (! $this->core->state instanceof CliState) {
            return;
        }

        $this->core->state->isScheduler = true;
        $this->core->record(
            RecordType::ScheduledTask,
            fn () => $this->core->sensors->scheduledTask($event),
        );
    }
}
