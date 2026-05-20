<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit;

use Kronn\Observability\Phase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhaseTest extends TestCase
{
    public function test_phase_values_match_wire_format(): void
    {
        self::assertSame('boot', Phase::Boot->value);
        self::assertSame('routing', Phase::Routing->value);
        self::assertSame('action', Phase::Action->value);
        self::assertSame('render', Phase::Render->value);
        self::assertSame('respond', Phase::Respond->value);
        self::assertSame('send', Phase::Send->value);
        self::assertSame('terminate', Phase::Terminate->value);
        self::assertSame('done', Phase::Done->value);
    }

    #[DataProvider('httpOnlyProvider')]
    public function test_is_http_only(Phase $phase, bool $expected): void
    {
        self::assertSame($expected, $phase->isHttpOnly());
    }

    public static function httpOnlyProvider(): iterable
    {
        yield 'routing'   => [Phase::Routing, true];
        yield 'render'    => [Phase::Render, true];
        yield 'respond'   => [Phase::Respond, true];
        yield 'send'      => [Phase::Send, true];
        yield 'boot'      => [Phase::Boot, false];
        yield 'action'    => [Phase::Action, false];
        yield 'terminate' => [Phase::Terminate, false];
        yield 'done'      => [Phase::Done, false];
    }
}
