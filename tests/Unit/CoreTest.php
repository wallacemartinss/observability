<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit;

use Kronn\Observability\Buffers\Buffer;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;
use Kronn\Observability\Sensors\Manager as SensorManager;
use Kronn\Observability\State\HttpState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\LaravelFeatures;
use Kronn\Observability\Support\Location;
use Kronn\Observability\Support\Uuid;
use Kronn\Observability\Transports\NullTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreTest extends TestCase
{
    public function test_record_writes_to_buffer_when_sampled_in(): void
    {
        $core = $this->makeCore();
        $core->enabled = true;
        $core->sampling = true;

        $accepted = $core->record(
            RecordType::Query,
            static fn (): array => ['kronn' => ['type' => 'query'], 'sql' => 'select 1'],
        );

        self::assertTrue($accepted);
        self::assertSame(1, $core->buffer->count());
    }

    public function test_record_drops_when_sampling_off(): void
    {
        $core = $this->makeCore();
        $core->enabled = true;
        $core->sampling = false;

        $accepted = $core->record(RecordType::Query, static fn (): array => ['n' => 1]);

        self::assertFalse($accepted);
        self::assertSame(0, $core->buffer->count());
    }

    public function test_record_drops_when_type_filter_set(): void
    {
        $core = $this->makeCore();
        $core->enabled = true;
        $core->sampling = true;
        $core->setTypeFilters([RecordType::Query->value => true]);

        self::assertFalse($core->record(RecordType::Query, static fn (): array => ['n' => 1]));
        self::assertSame(0, $core->buffer->count());
    }

    public function test_exception_sampling_is_independent_of_request_sampling(): void
    {
        $core = $this->makeCore();
        $core->enabled = true;
        $core->sampling = false;          // requests are dropped
        $core->exceptionSampling = true;  // but exceptions still go through

        $accepted = $core->record(RecordType::Exception, static fn (): array => ['x' => 1]);

        self::assertTrue($accepted);
    }

    public function test_scheduled_task_sampling_is_independent_of_command_sampling(): void
    {
        $core = $this->makeCore();
        $core->enabled = true;
        $core->sampling = false;             // commands are dropped
        $core->scheduledTaskSampling = true; // but scheduled tasks still go through

        $accepted = $core->record(RecordType::ScheduledTask, static fn (): array => ['x' => 1]);

        self::assertTrue($accepted);
    }

    public function test_scheduled_task_sampling_can_drop_independently(): void
    {
        $core = $this->makeCore();
        $core->enabled = true;
        $core->sampling = true;               // commands kept
        $core->scheduledTaskSampling = false; // but scheduled tasks dropped

        $accepted = $core->record(RecordType::ScheduledTask, static fn (): array => ['x' => 1]);

        self::assertFalse($accepted);
    }

    public function test_decide_scheduled_task_sampling_returns_true_on_rate_one(): void
    {
        $core = $this->makeCore();

        self::assertTrue($core->decideScheduledTaskSampling(1.0));
    }

    public function test_decide_scheduled_task_sampling_returns_false_on_rate_zero(): void
    {
        $core = $this->makeCore();

        self::assertFalse($core->decideScheduledTaskSampling(0.0));
    }

    public function test_ignore_block_drops_records(): void
    {
        $core = $this->makeCore();
        $core->enabled = true;
        $core->sampling = true;

        $core->ignore(function () use ($core) {
            $core->record(RecordType::Query, static fn (): array => ['inside' => true]);
        });

        self::assertSame(0, $core->buffer->count());
    }

    public function test_decide_sampling_returns_true_on_rate_one(): void
    {
        $core = $this->makeCore();

        self::assertTrue($core->decideSampling(1.0));
    }

    public function test_decide_sampling_returns_false_on_rate_zero(): void
    {
        $core = $this->makeCore();

        self::assertFalse($core->decideSampling(0.0));
    }

    public function test_tag_and_extra_attach_to_state(): void
    {
        $core = $this->makeCore();

        $core->tag(['area' => 'billing']);
        $core->extra(['cart_size' => 5]);

        self::assertSame('billing', $core->state->tags['area']);
        self::assertSame(5, $core->state->extras['cart_size']);
    }

    public function test_report_writes_exception_record(): void
    {
        $core = $this->makeCore();
        $core->enabled = true;
        $core->exceptionSampling = true;

        $core->report(new RuntimeException('boom'));

        self::assertGreaterThanOrEqual(1, $core->buffer->count());
    }

    public function test_disabled_core_drops_everything(): void
    {
        $core = $this->makeCore();
        $core->enabled = false;
        $core->sampling = true;

        self::assertFalse($core->record(RecordType::Query, static fn (): array => ['n' => 1]));
    }

    public function test_watchdog_triggers_digest_after_threshold(): void
    {
        $shippedBatches = [];
        $transport = new class($shippedBatches) extends NullTransport {
            public function __construct(private array &$batches) {}
            public function ship(array $records): void { $this->batches[] = $records; }
        };

        $now = 0.0;
        $clock = new Clock(microtimeFn: static function () use (&$now): float { return $now; });
        $core = $this->makeCore(clock: $clock, transport: $transport);
        $core->enabled = true;
        $core->sampling = true;
        $core->state->startedAtMicrotime = 0.0;
        $core->longRequestMs = 100.0;

        $now = 0.05;  // 50 ms — below threshold
        $core->record(RecordType::Query, static fn (): array => ['n' => 1]);
        self::assertSame([], $shippedBatches, 'should not have flushed yet');

        $now = 0.2;  // 200 ms — past threshold
        $core->record(RecordType::Query, static fn (): array => ['n' => 2]);

        self::assertCount(1, $shippedBatches, 'watchdog should have flushed');
        self::assertSame(0, $core->buffer->count());
    }

    private function makeCore(?Clock $clock = null, ?NullTransport $transport = null): Core
    {
        $clock = $clock ?? new Clock();
        $uuid = new Uuid(static fn (): string => 'fixed-uuid');
        $state = new HttpState(
            startedAtMicrotime: $clock->microtime(),
            traceId: 'trace',
            executionId: 'exec',
            deployment: 'dep',
            environment: 'test',
            server: 'host',
        );
        $features = new LaravelFeatures();
        $sensors = new SensorManager(
            state: $state,
            clock: $clock,
            location: new Location('/tmp'),
            features: $features,
            options: [
                'slow_query_ms' => 100.0,
                'slow_outgoing_ms' => 1000.0,
                'capture_payload' => false,
                'capture_exception_source' => false,
                'redact_payload' => [],
                'redact_headers' => [],
            ],
        );

        return new Core(
            clock: $clock,
            uuid: $uuid,
            sensors: $sensors,
            buffer: new Buffer(100),
            transport: $transport ?? new NullTransport(),
            state: $state,
        );
    }
}
