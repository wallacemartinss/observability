<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Buffers;

use Kronn\Observability\Buffers\Buffer;
use PHPUnit\Framework\TestCase;

final class BufferTest extends TestCase
{
    public function test_writes_grow_count_until_capacity(): void
    {
        $buffer = new Buffer(3);

        self::assertTrue($buffer->write(['n' => 1]));
        self::assertTrue($buffer->write(['n' => 2]));
        self::assertFalse($buffer->full);
        self::assertSame(2, $buffer->count());

        self::assertTrue($buffer->write(['n' => 3]));
        self::assertTrue($buffer->full);
        self::assertSame(3, $buffer->count());
    }

    public function test_write_rejects_once_full(): void
    {
        $buffer = new Buffer(1);
        $buffer->write(['n' => 1]);

        self::assertFalse($buffer->write(['n' => 2]));
        self::assertSame(1, $buffer->count());
    }

    public function test_force_write_evicts_oldest_when_full(): void
    {
        $buffer = new Buffer(2);
        $buffer->write(['n' => 1]);
        $buffer->write(['n' => 2]);

        $buffer->forceWrite(['n' => 3]);

        self::assertSame([['n' => 2], ['n' => 3]], $buffer->pull());
    }

    public function test_pull_returns_and_clears(): void
    {
        $buffer = new Buffer(5);
        $buffer->write(['n' => 1]);
        $buffer->write(['n' => 2]);

        $pulled = $buffer->pull();

        self::assertSame([['n' => 1], ['n' => 2]], $pulled);
        self::assertTrue($buffer->isEmpty());
        self::assertFalse($buffer->full);
    }

    public function test_flush_resets_without_returning(): void
    {
        $buffer = new Buffer(1);
        $buffer->write(['n' => 1]);
        self::assertTrue($buffer->full);

        $buffer->flush();

        self::assertTrue($buffer->isEmpty());
        self::assertFalse($buffer->full);
        self::assertTrue($buffer->write(['n' => 99])); // ready to accept again
    }

    public function test_capacity_is_exposed(): void
    {
        self::assertSame(42, (new Buffer(42))->capacity());
    }
}
