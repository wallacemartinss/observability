<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Sensors;

use Kronn\Observability\Sensors\LazyLoadSensor;
use Kronn\Observability\State\HttpState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\Location;
use PHPUnit\Framework\TestCase;

final class LazyLoadSensorTest extends TestCase
{
    private function state(): HttpState
    {
        return new HttpState(
            startedAtMicrotime: 1.0,
            traceId: 't',
            executionId: 'e',
            deployment: 'd',
            environment: 'testing',
            server: 's',
        );
    }

    private function sensor(HttpState $state): LazyLoadSensor
    {
        return new LazyLoadSensor($state, new Clock(microtimeFn: fn () => 1.0), new Location('/var/www'));
    }

    public function test_first_violation_emits_record_and_counts(): void
    {
        $state = $this->state();
        $record = ($this->sensor($state))('App\\User', 'posts');

        self::assertNotNull($record);
        self::assertSame('posts', $record['relation']);
        self::assertSame(1, $state->lazyLoadCount);
    }

    public function test_repeated_pair_is_deduped_but_every_violation_still_counts(): void
    {
        $state = $this->state();
        $sensor = $this->sensor($state);

        $first = $sensor('App\\User', 'posts');
        $second = $sensor('App\\User', 'posts');
        $third = $sensor('App\\User', 'posts');

        self::assertNotNull($first);
        self::assertNull($second);
        self::assertNull($third);
        self::assertSame(3, $state->lazyLoadCount);
    }

    public function test_distinct_relations_each_emit_a_record(): void
    {
        $state = $this->state();
        $sensor = $this->sensor($state);

        self::assertNotNull($sensor('App\\User', 'posts'));
        self::assertNotNull($sensor('App\\User', 'comments'));
        self::assertNull($sensor('App\\User', 'posts'));
        self::assertSame(3, $state->lazyLoadCount);
    }
}
