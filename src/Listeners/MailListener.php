<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;

class MailListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(MessageSending|MessageSent $event): void
    {
        $this->core->record(
            RecordType::Mail,
            fn () => $this->core->sensors->mail($event),
        );
    }
}
