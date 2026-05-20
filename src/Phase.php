<?php

declare(strict_types=1);

namespace Kronn\Observability;

enum Phase: string
{
    case Boot = 'boot';
    case Routing = 'routing';
    case Action = 'action';
    case Render = 'render';
    case Respond = 'respond';
    case Send = 'send';
    case Terminate = 'terminate';
    case Done = 'done';

    public function isHttpOnly(): bool
    {
        return match ($this) {
            self::Routing, self::Render, self::Respond, self::Send => true,
            default => false,
        };
    }
}
