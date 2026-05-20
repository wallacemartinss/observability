<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;

class NotificationListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(NotificationSending|NotificationSent $event): void
    {
        $this->core->record(
            RecordType::Notification,
            fn () => $this->core->sensors->notification($event),
        );
    }
}
