<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Kronn\Observability\Records\Mail as MailRecord;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\LaravelFeatures;

class MailSensor
{
    public function __construct(
        private readonly ExecutionState $state,
        private readonly Clock $clock,
        private readonly LaravelFeatures $features,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(MessageSending|MessageSent $event): array
    {
        if ($event instanceof MessageSent) {
            $this->state->mailCount++;
        }

        return MailRecord::make($this->state, $event, $this->clock, $this->features);
    }
}
