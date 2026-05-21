<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Kronn\Observability\Records\LazyLoad as LazyLoadRecord;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\Location;

/**
 * Turns an Eloquent lazy-loading violation into a kronn-v1 record.
 *
 * Deduplicates per execution: the first violation of a given
 * (model, relation) pair emits a record, later ones only bump the
 * counter. One N+1 of 84 lazy loads on User->posts becomes a single
 * record — the repeat count already lives in the query records.
 */
class LazyLoadSensor
{
    /** @var array<string, true> model|relation pairs already recorded this execution */
    private array $seen = [];

    public function __construct(
        private readonly ExecutionState $state,
        private readonly Clock $clock,
        private readonly Location $location,
    ) {
    }

    /**
     * @return array<string, mixed>|null  null when this pair was already recorded
     */
    public function __invoke(string $modelClass, string $relation): ?array
    {
        $this->state->lazyLoadCount++;

        $key = $modelClass . '|' . $relation;
        if (isset($this->seen[$key])) {
            return null;
        }
        $this->seen[$key] = true;

        return LazyLoadRecord::make(
            state: $this->state,
            modelClass: $modelClass,
            relation: $relation,
            origin: $this->captureOrigin(),
            clock: $this->clock,
            location: $this->location,
        );
    }

    /**
     * First stack frame that lives in application code — the line that
     * triggered the lazy load.
     *
     * @return array{file?: string, line?: int}
     */
    private function captureOrigin(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30);

        foreach ($trace as $frame) {
            $file = (string) ($frame['file'] ?? '');
            if ($file !== '' && $this->location->isAppCode($file)) {
                return ['file' => $file, 'line' => (int) ($frame['line'] ?? 0)];
            }
        }

        return [];
    }
}
