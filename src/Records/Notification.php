<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;

use function Kronn\Observability\Support\tiny_text;

class Notification
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        ExecutionState $state,
        NotificationSending|NotificationSent $event,
        Clock $clock,
    ): array {
        $microtime = $clock->microtime();
        $notificationClass = $event->notification::class;
        $channel = (string) $event->channel;
        $notifiableClass = is_object($event->notifiable) ? $event->notifiable::class : (string) $event->notifiable;

        $group = hash('xxh128', $notificationClass . '|' . $channel);

        return Envelope::build($state, RecordType::Notification, $microtime, $group) + [
            'stage' => $event instanceof NotificationSent ? 'sent' : 'sending',
            'class' => tiny_text($notificationClass),
            'channel' => tiny_text($channel),
            'notifiable_class' => tiny_text($notifiableClass),
            'notifiable_id' => method_exists($event->notifiable, 'getKey')
                ? (string) $event->notifiable->getKey()
                : null,
        ];
    }
}
