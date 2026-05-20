<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Kronn\Observability\Records\JobAttempt as JobAttemptRecord;
use Kronn\Observability\State\CliState;
use Kronn\Observability\Support\Clock;

class JobAttemptSensor
{
    public function __construct(
        private readonly CliState $state,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(JobProcessed|JobReleasedAfterException|JobFailed $event): array
    {
        return JobAttemptRecord::make($this->state, $event, $this->clock);
    }
}
