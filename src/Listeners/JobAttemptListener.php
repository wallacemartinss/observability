<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;
use Kronn\Observability\State\CliState;

class JobAttemptListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(JobProcessed|JobReleasedAfterException|JobFailed $event): void
    {
        if (! $this->core->state instanceof CliState) {
            return;
        }

        $this->core->record(
            RecordType::JobAttempt,
            fn () => $this->core->sensors->jobAttempt($event),
        );

        // Force a digest between jobs so the worker doesn't accumulate memory.
        $this->core->digest();
    }
}
