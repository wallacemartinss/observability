<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Support;

use Kronn\Observability\Support\Clock;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    public function test_default_microtime_uses_system_clock(): void
    {
        $clock = new Clock();
        $before = microtime(true);
        $value = $clock->microtime();
        $after = microtime(true);

        self::assertGreaterThanOrEqual($before, $value);
        self::assertLessThanOrEqual($after + 0.001, $value);
    }

    public function test_microtime_uses_injected_callable(): void
    {
        $clock = new Clock(microtimeFn: static fn (): float => 1234.5);

        self::assertSame(1234.5, $clock->microtime());
    }

    public function test_unix_seconds_uses_injected_callable(): void
    {
        $clock = new Clock(timeFn: static fn (): int => 1_700_000_000);

        self::assertSame(1_700_000_000, $clock->unixSeconds());
    }

    public function test_elapsed_ms_computes_difference(): void
    {
        $clock = new Clock(microtimeFn: static fn (): float => 10.5);

        self::assertSame(500.0, $clock->elapsedMs(10.0));
        self::assertSame(2000.0, $clock->elapsedMs(8.0, 10.0));
    }
}
