<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\Location;

use function Kronn\Observability\Support\tiny_text;

class LazyLoad
{
    /**
     * @param  array{file?: string, line?: int}  $origin
     * @return array<string, mixed>
     */
    public static function make(
        ExecutionState $state,
        string $modelClass,
        string $relation,
        array $origin,
        Clock $clock,
        Location $location,
    ): array {
        $microtime = $clock->microtime();
        $file = $origin['file'] ?? '';

        // Same model+relation always groups together — that is the N+1.
        $group = hash('xxh128', $modelClass . '|' . $relation);

        return Envelope::build($state, RecordType::LazyLoad, $microtime, $group) + [
            'model' => tiny_text($modelClass),
            'relation' => tiny_text($relation),
            'origin' => [
                'file' => $file !== '' ? $location->normalize($file) : null,
                'line' => $origin['line'] ?? null,
                'is_app_code' => $file !== '' && $location->isAppCode($file),
            ],
        ];
    }
}
