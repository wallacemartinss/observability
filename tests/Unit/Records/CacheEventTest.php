<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Records;

use Illuminate\Cache\Events\CacheHit;
use Kronn\Observability\Records\CacheEvent;
use Kronn\Observability\State\CliState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\LaravelFeatures;
use PHPUnit\Framework\TestCase;

final class CacheEventTest extends TestCase
{
    /**
     * Regression: Laravel cache events carry their tags on the public $tags
     * property (and setTags() is the only method); there is no tags() getter,
     * so the previous method_exists()/tags() path always yielded an empty
     * array. make() must read the property.
     */
    public function test_captures_cache_tags_without_clobbering_application_tags(): void
    {
        $event = new CacheHit('redis', 'user.42.profile', 'value', ['team:7', 'profiles']);

        $state = $this->state();
        $state->tags = ['area' => 'cache']; // application tags from the envelope

        $record = CacheEvent::make($state, $event, $this->clock(), $this->features());

        self::assertSame('hit', $record['kind']);
        self::assertSame(['team:7', 'profiles'], $record['cache_tags']);
        self::assertSame(['area' => 'cache'], $record['tags'], 'envelope tags must survive');
        self::assertSame('user.{id}.profile', $record['key_shape']);
        self::assertSame('redis', $record['store']);
    }

    public function test_cache_tags_default_to_an_empty_array_when_none_are_set(): void
    {
        $event = new CacheHit('redis', 'config', 'value');

        $record = CacheEvent::make($this->state(), $event, $this->clock(), $this->features());

        self::assertSame([], $record['cache_tags']);
    }

    private function state(): CliState
    {
        return new CliState(
            startedAtMicrotime: 1000.0,
            traceId: 'trace-1',
            executionId: 'exec-1',
            deployment: 'deploy-1',
            environment: 'testing',
            server: 'cli-01',
        );
    }

    private function clock(): Clock
    {
        return new Clock(fn (): float => 1000.0);
    }

    private function features(): LaravelFeatures
    {
        $features = new LaravelFeatures();
        $features->hasCacheStoreInEvents = true;

        return $features;
    }
}
