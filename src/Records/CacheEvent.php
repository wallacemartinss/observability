<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Illuminate\Cache\Events\CacheEvent as IlluminateCacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\LaravelFeatures;

use function Kronn\Observability\Support\tiny_text;

class CacheEvent
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        ExecutionState $state,
        IlluminateCacheEvent $event,
        Clock $clock,
        LaravelFeatures $features,
    ): array {
        $microtime = $clock->microtime();
        $key = (string) ($event->key ?? '');
        $store = $features->hasCacheStoreInEvents && property_exists($event, 'storeName')
            ? (string) ($event->storeName ?? 'default')
            : 'unknown';

        $kind = self::classify($event);
        $group = hash('xxh128', $kind . '|' . $store . '|' . self::keyShape($key));

        return Envelope::build($state, RecordType::CacheEvent, $microtime, $group) + [
            'kind' => $kind, // retrieving|hit|miss|writing|written|write_failed|forgetting|forgotten|forget_failed
            'key' => tiny_text($key),
            'key_shape' => tiny_text(self::keyShape($key)),
            'store' => tiny_text($store),
            'tags' => method_exists($event, 'tags') ? array_map('strval', $event->tags() ?? []) : [],
        ];
    }

    private static function classify(IlluminateCacheEvent $event): string
    {
        return match (true) {
            $event instanceof CacheHit => 'hit',
            $event instanceof CacheMissed => 'miss',
            $event instanceof RetrievingKey => 'retrieving',
            $event instanceof WritingKey => 'writing',
            $event instanceof KeyWritten => 'written',
            $event instanceof KeyWriteFailed => 'write_failed',
            $event instanceof ForgettingKey => 'forgetting',
            $event instanceof KeyForgotten => 'forgotten',
            $event instanceof KeyForgetFailed => 'forget_failed',
            default => 'unknown',
        };
    }

    /**
     * Replaces numeric and UUID sub-segments with placeholders.
     * Example: "user.42.profile" -> "user.{id}.profile".
     */
    private static function keyShape(string $key): string
    {
        $shaped = preg_replace('/\b\d+\b/', '{id}', $key) ?? $key;
        $shaped = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{uuid}', $shaped) ?? $shaped;

        return $shaped;
    }
}
