<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Kronn\Observability\State\ExecutionState;

/**
 * Builds the common header that every record carries — used by the
 * backend for routing, grouping and linking back to the parent
 * execution.
 *
 * Schema decision: a single "kronn" key isolates platform metadata from
 * the domain payload (request, query, ...).
 */
class Envelope
{
    /**
     * @return array<string, mixed>
     */
    public static function build(
        ExecutionState $state,
        RecordType $type,
        float $timestampMicrotime,
        string $group = '',
    ): array {
        return [
            'kronn' => [
                'schema' => 'v1',
                'type' => $type->value,
                'timestamp_ms' => (int) round($timestampMicrotime * 1000),
                'trace_id' => $state->traceId,
                'parent_trace_id' => $state->parentTraceId,
                'execution_id' => $state->executionId,
                'phase' => $state->phase->value,
                'source' => $state->source,
                'environment' => $state->environment,
                'deployment' => $state->deployment,
                'server' => $state->server,
                'group' => $group,
            ],
            'tags' => $state->tags,
            'extras' => $state->extras,
        ];
    }
}
