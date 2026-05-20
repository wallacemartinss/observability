<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\LaravelFeatures;

use function Kronn\Observability\Support\tiny_text;

class Mail
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        ExecutionState $state,
        MessageSending|MessageSent $event,
        Clock $clock,
        LaravelFeatures $features,
    ): array {
        $microtime = $clock->microtime();
        $message = $event->message;

        $to = self::addresses($message->getTo());
        $subject = (string) $message->getSubject();
        $mailable = $features->hasMailableClassNameMacro && property_exists($event, 'data') && isset($event->data['__laravel_notification'])
            ? (string) $event->data['__laravel_notification']
            : null;

        $group = hash('xxh128', ($mailable ?? 'inline') . '|' . $subject);

        return Envelope::build($state, RecordType::Mail, $microtime, $group) + [
            'stage' => $event instanceof MessageSent ? 'sent' : 'sending',
            'mailable' => $mailable,
            'subject' => tiny_text($subject),
            'from' => self::addresses($message->getFrom()),
            'to' => $to,
            'cc' => self::addresses($message->getCc()),
            'bcc' => self::addresses($message->getBcc()),
            'has_attachments' => count($message->getAttachments()) > 0,
        ];
    }

    /**
     * @param  iterable<\Symfony\Component\Mime\Address>  $addresses
     * @return list<string>
     */
    private static function addresses(iterable $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            $out[] = tiny_text((string) $address->getAddress());
        }

        return $out;
    }
}
