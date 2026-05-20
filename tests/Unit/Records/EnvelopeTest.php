<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Records;

use Kronn\Observability\Phase;
use Kronn\Observability\Records\Envelope;
use Kronn\Observability\Records\RecordType;
use Kronn\Observability\State\HttpState;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    public function test_builds_v1_header_with_all_metadata(): void
    {
        $state = new HttpState(
            startedAtMicrotime: 100.0,
            traceId: 'trace-abc',
            executionId: 'exec-xyz',
            deployment: 'deploy-1',
            environment: 'staging',
            server: 'web-01',
        );
        $state->phase = Phase::Action;
        $state->parentTraceId = 'parent-123';
        $state->tags = ['area' => 'billing'];
        $state->extras = ['cart_size' => 7];

        $envelope = Envelope::build($state, RecordType::Request, 200.500456, 'group-hash');

        self::assertSame('v1', $envelope['kronn']['schema']);
        self::assertSame('request', $envelope['kronn']['type']);
        self::assertEqualsWithDelta(200_500.456, $envelope['kronn']['timestamp_ms'], 0.001);
        self::assertIsFloat($envelope['kronn']['timestamp_ms']);
        self::assertSame('trace-abc', $envelope['kronn']['trace_id']);
        self::assertSame('parent-123', $envelope['kronn']['parent_trace_id']);
        self::assertSame('exec-xyz', $envelope['kronn']['execution_id']);
        self::assertSame('action', $envelope['kronn']['phase']);
        self::assertSame('http', $envelope['kronn']['source']);
        self::assertSame('staging', $envelope['kronn']['environment']);
        self::assertSame('deploy-1', $envelope['kronn']['deployment']);
        self::assertSame('web-01', $envelope['kronn']['server']);
        self::assertSame('group-hash', $envelope['kronn']['group']);
        self::assertSame(['area' => 'billing'], $envelope['tags']);
        self::assertSame(['cart_size' => 7], $envelope['extras']);
    }
}
