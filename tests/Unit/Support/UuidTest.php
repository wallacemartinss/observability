<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Support;

use Kronn\Observability\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function test_default_generator_produces_valid_uuid_v4(): void
    {
        $uuid = (new Uuid())->make();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function test_custom_generator_is_used(): void
    {
        $uuid = new Uuid(static fn (): string => 'fixed-id');

        self::assertSame('fixed-id', $uuid->make());
    }

    public function test_short_hash_is_deterministic_and_truncates_to_length(): void
    {
        $uuid = new Uuid();

        $hash = $uuid->shortHash('hello', 6);

        self::assertSame(6, strlen($hash));
        self::assertSame($hash, $uuid->shortHash('hello', 6));
        self::assertNotSame($hash, $uuid->shortHash('world', 6));
    }
}
