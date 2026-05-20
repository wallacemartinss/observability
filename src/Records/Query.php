<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Illuminate\Database\Events\QueryExecuted;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\Location;

use function Kronn\Observability\Support\text;
use function Kronn\Observability\Support\tiny_text;

class Query
{
    /**
     * @param  array{file?: string, line?: int}  $origin
     * @return array<string, mixed>
     */
    public static function make(
        ExecutionState $state,
        QueryExecuted $event,
        array $origin,
        float $slowThresholdMs,
        Clock $clock,
        Location $location,
    ): array {
        $microtime = $clock->microtime();
        $sql = (string) $event->sql;
        $durationMs = (float) $event->time;

        $group = hash('xxh128', $event->connectionName . '|' . self::shapeOf($sql));

        $file = $origin['file'] ?? '';

        return Envelope::build($state, RecordType::Query, $microtime, $group) + [
            'sql' => text($sql),
            'sql_shape' => self::shapeOf($sql),
            'connection' => tiny_text((string) $event->connectionName),
            'duration_ms' => round($durationMs, 3),
            'slow' => $durationMs >= $slowThresholdMs,
            'origin' => [
                'file' => $file !== '' ? $location->normalize($file) : null,
                'line' => $origin['line'] ?? null,
                'is_app_code' => $file !== '' && $location->isAppCode($file),
            ],
        ];
    }

    /**
     * Reduces a SQL query to its canonical "shape" used for grouping:
     * collapses literals, normalizes whitespace. Heuristic — not a real
     * SQL parser.
     */
    private static function shapeOf(string $sql): string
    {
        // String literals
        $shaped = preg_replace("/'([^']|'')*'/", '?', $sql) ?? $sql;
        // Numbers
        $shaped = preg_replace('/\b\d+(\.\d+)?\b/', '?', $shaped) ?? $shaped;
        // IN(?, ?, ?) lists
        $shaped = preg_replace('/\bin\s*\(\s*(\?(\s*,\s*\?)*)\s*\)/i', 'IN (?)', $shaped) ?? $shaped;
        // Whitespace
        $shaped = preg_replace('/\s+/', ' ', $shaped) ?? $shaped;

        return tiny_text(trim($shaped));
    }
}
