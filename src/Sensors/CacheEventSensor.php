<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Illuminate\Cache\Events\CacheEvent as IlluminateCacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Kronn\Observability\Records\CacheEvent as CacheEventRecord;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\LaravelFeatures;

class CacheEventSensor
{
    public function __construct(
        private readonly ExecutionState $state,
        private readonly Clock $clock,
        private readonly LaravelFeatures $features,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function __invoke(IlluminateCacheEvent $event): ?array
    {
        if ($event instanceof CacheHit) {
            $this->state->cacheHitCount++;
        } elseif ($event instanceof CacheMissed) {
            $this->state->cacheMissCount++;
        } elseif ($event instanceof KeyWritten) {
            $this->state->cacheWriteCount++;
        } elseif ($event instanceof KeyForgotten) {
            $this->state->cacheForgetCount++;
        }

        return CacheEventRecord::make($this->state, $event, $this->clock, $this->features);
    }
}
