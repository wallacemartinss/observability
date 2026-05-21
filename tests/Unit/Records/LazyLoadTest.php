<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Records;

use Kronn\Observability\Records\LazyLoad;
use Kronn\Observability\State\HttpState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\Location;
use PHPUnit\Framework\TestCase;

final class LazyLoadTest extends TestCase
{
    private function state(): HttpState
    {
        return new HttpState(
            startedAtMicrotime: 100.0,
            traceId: 'trace-1',
            executionId: 'exec-1',
            deployment: 'deploy-1',
            environment: 'testing',
            server: 'web-1',
        );
    }

    public function test_builds_lazy_load_record_with_model_relation_and_origin(): void
    {
        $record = LazyLoad::make(
            state: $this->state(),
            modelClass: 'App\\Models\\User',
            relation: 'posts',
            origin: ['file' => '/var/www/app/Http/Controllers/UserController.php', 'line' => 42],
            clock: new Clock(microtimeFn: fn () => 200.5),
            location: new Location('/var/www'),
        );

        self::assertSame('lazy_load', $record['kronn']['type']);
        self::assertEqualsWithDelta(200_500.0, $record['kronn']['timestamp_ms'], 0.001);
        self::assertSame('App\\Models\\User', $record['model']);
        self::assertSame('posts', $record['relation']);
        self::assertSame('{base}/app/Http/Controllers/UserController.php', $record['origin']['file']);
        self::assertSame(42, $record['origin']['line']);
        self::assertTrue($record['origin']['is_app_code']);
    }

    public function test_origin_is_null_when_no_app_frame_was_captured(): void
    {
        $record = LazyLoad::make(
            state: $this->state(),
            modelClass: 'App\\Models\\User',
            relation: 'posts',
            origin: [],
            clock: new Clock(microtimeFn: fn () => 1.0),
            location: new Location('/var/www'),
        );

        self::assertNull($record['origin']['file']);
        self::assertNull($record['origin']['line']);
        self::assertFalse($record['origin']['is_app_code']);
    }

    public function test_group_is_stable_per_model_relation_pair(): void
    {
        $clock = new Clock(microtimeFn: fn () => 1.0);
        $location = new Location('/var/www');

        $a = LazyLoad::make($this->state(), 'App\\User', 'posts', [], $clock, $location);
        $b = LazyLoad::make($this->state(), 'App\\User', 'posts', [], $clock, $location);
        $c = LazyLoad::make($this->state(), 'App\\User', 'comments', [], $clock, $location);

        self::assertSame($a['kronn']['group'], $b['kronn']['group']);
        self::assertNotSame($a['kronn']['group'], $c['kronn']['group']);
    }
}
