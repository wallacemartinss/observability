<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;

class QueuedJobListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(JobQueueing|JobQueued $event): void
    {
        $this->core->record(
            RecordType::QueuedJob,
            fn () => $this->core->sensors->queuedJob($event),
        );
    }
}
