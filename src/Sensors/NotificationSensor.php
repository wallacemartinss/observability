<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Kronn\Observability\Records\Notification as NotificationRecord;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;

class NotificationSensor
{
    public function __construct(
        private readonly ExecutionState $state,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(NotificationSending|NotificationSent $event): array
    {
        if ($event instanceof NotificationSent) {
            $this->state->notificationCount++;
        }

        return NotificationRecord::make($this->state, $event, $this->clock);
    }
}
