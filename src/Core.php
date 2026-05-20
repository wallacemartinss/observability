<?php

declare(strict_types=1);

namespace Kronn\Observability;

use Closure;
use Kronn\Observability\Buffers\Buffer;
use Kronn\Observability\Concerns\RedactsRecords;
use Kronn\Observability\Concerns\RejectsRecords;
use Kronn\Observability\Contracts\Transport;
use Kronn\Observability\Records\RecordType;
use Kronn\Observability\Sensors\Manager as SensorManager;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\State\HttpState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\Uuid;
use Throwable;

/**
 * Central orchestrator. Every public interaction lands here via the
 * Telemetry facade; framework listeners also talk to the Core directly
 * to report events.
 *
 * Invariants:
 *  - Never propagates exceptions to the host application. Internal
 *    failures are routed to the "observability" log channel through
 *    reportSelfFailure().
 *  - While $ignoreDepth > 0, every record is dropped silently.
 *  - When shouldKeep() is false the record is not even built — sensors
 *    must check Core::wants() before doing any work.
 */
class Core
{
    use RedactsRecords;
    use RejectsRecords;

    public bool $enabled = true;

    /**
     * Watchdog thresholds in milliseconds. When non-null and elapsed
     * execution time crosses the matching threshold, Core::record()
     * forces an early digest to keep memory bounded on long executions.
     */
    public ?float $longRequestMs = null;
    public ?float $longCommandMs = null;

    /**
     * Blocks every emission while greater than zero. Used by
     * Telemetry::ignore() and by internal subroutines (e.g. user
     * resolution) that must not report themselves.
     */
    protected int $ignoreDepth = 0;

    private float $lastWatchdogDigestAtMicrotime = 0.0;

    /** @var (callable(\Throwable): void)|null */
    public $selfFailureReporter = null;

    /** @var (callable(\Illuminate\Contracts\Auth\Authenticatable): array<string, mixed>)|null */
    public $userDetailsResolver = null;

    public function __construct(
        public readonly Clock $clock,
        public readonly Uuid $uuid,
        public readonly SensorManager $sensors,
        public readonly Buffer $buffer,
        public Transport $transport,
        public ExecutionState $state,
    ) {
    }

    // -------------------------- Public API --------------------------

    /**
     * Register (or replace) the user-details resolver.
     *
     * @param  callable(\Illuminate\Contracts\Auth\Authenticatable): array<string, mixed>  $resolver
     */
    public function user(callable $resolver): void
    {
        $this->userDetailsResolver = $resolver;
    }

    /**
     * Attach free-form tags to the current execution. Tags persist for the
     * whole execution and are copied into every record.
     *
     * @param  string|array<string, scalar>  $tag
     */
    public function tag(string|array $tag, mixed $value = true): void
    {
        if (is_array($tag)) {
            foreach ($tag as $k => $v) {
                $this->state->tags[$k] = $v;
            }
            return;
        }
        $this->state->tags[$tag] = $value;
    }

    /**
     * Attach extra fields to the current execution.
     *
     * @param  array<string, mixed>  $extras
     */
    public function extra(array $extras): void
    {
        $this->state->extras = array_replace($this->state->extras, $extras);
    }

    /**
     * Manually report an exception.
     */
    public function report(Throwable $throwable, bool $handled = true): void
    {
        if (! $this->shouldEmit(RecordType::Exception)) {
            return;
        }

        $this->guard(function () use ($throwable, $handled) {
            $record = $this->sensors->exception($throwable, $handled);
            $this->buffer->forceWrite($record);
        });
    }

    /**
     * Run $callback without emitting any telemetry. Useful for internal
     * hot paths that must not feed back into the pipeline.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function ignore(callable $callback): mixed
    {
        $this->ignoreDepth++;
        try {
            return $callback();
        } finally {
            $this->ignoreDepth--;
        }
    }

    public function trace(): string
    {
        return $this->state->traceId;
    }

    public function executionId(): string
    {
        return $this->state->executionId;
    }

    public function digest(): void
    {
        if ($this->buffer->isEmpty()) {
            return;
        }

        $this->guard(function () {
            $this->transport->ship($this->buffer->pull());
        });
    }

    // -------------------------- Internal API --------------------------

    /**
     * Should the caller bother building a record of this type?
     */
    public function wants(RecordType $type): bool
    {
        return $this->enabled && $this->ignoreDepth === 0 && $this->shouldKeep($type);
    }

    /**
     * Default pipeline: sensor -> record -> buffer. Returns true when
     * the record was accepted into the buffer.
     *
     * @param  Closure(): (array<string, mixed>|null)  $build
     */
    public function record(RecordType $type, Closure $build): bool
    {
        if (! $this->wants($type)) {
            return false;
        }

        try {
            $record = $build();
        } catch (Throwable $e) {
            $this->reportSelfFailure($e);
            return false;
        }

        if ($record === null) {
            return false;
        }

        $written = $this->buffer->write($record);
        if (! $written && ($this->buffer->full)) {
            $this->digest();
            $this->buffer->write($record);
        }

        $this->maybeWatchdogDigest();

        return true;
    }

    /**
     * If the current execution has been running longer than the
     * configured threshold for its kind, flush whatever is buffered.
     * The watchdog re-arms once per threshold window — we don't digest
     * on every single record after the line is crossed.
     */
    private function maybeWatchdogDigest(): void
    {
        $threshold = $this->state instanceof HttpState ? $this->longRequestMs : $this->longCommandMs;
        if ($threshold === null || $this->buffer->isEmpty()) {
            return;
        }

        $now = $this->clock->microtime();
        $elapsedMs = ($now - $this->state->startedAtMicrotime) * 1000.0;
        if ($elapsedMs < $threshold) {
            return;
        }

        // Re-arm once per threshold window.
        if ($this->lastWatchdogDigestAtMicrotime > 0.0
            && ($now - $this->lastWatchdogDigestAtMicrotime) * 1000.0 < $threshold) {
            return;
        }

        $this->lastWatchdogDigestAtMicrotime = $now;
        $this->digest();
    }

    /**
     * Reset between jobs while inside a queue worker.
     */
    public function resetForNextExecution(string $traceId, string $executionId): void
    {
        $this->state->resetForNextExecution($this->clock->microtime(), $traceId, $executionId);
        $this->sensors->setState($this->state);
        $this->buffer->flush();
    }

    public function reportSelfFailure(Throwable $throwable): void
    {
        if ($this->selfFailureReporter !== null) {
            try {
                ($this->selfFailureReporter)($throwable);
            } catch (Throwable) {
                // If the reporter itself throws there is nothing we can do.
            }
        }
    }

    private function shouldEmit(RecordType $type): bool
    {
        return $this->wants($type);
    }

    /**
     * Run the callback, swallowing any exception and routing it to the
     * self-failure reporter.
     */
    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->reportSelfFailure($e);
        }
    }
}
