<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Cache\Events\CacheEvent as IlluminateCacheEvent;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;

class CacheEventListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(IlluminateCacheEvent $event): void
    {
        $this->core->record(
            RecordType::CacheEvent,
            fn () => $this->core->sensors->cacheEvent($event),
        );
    }
}
