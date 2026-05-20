<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Monolog\LogRecord;

use function Kronn\Observability\Support\long_text;
use function Kronn\Observability\Support\tiny_text;

class Log
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        ExecutionState $state,
        LogRecord $logRecord,
        Clock $clock,
    ): array {
        $microtime = $clock->microtime();
        $level = strtolower($logRecord->level->getName());
        $channel = (string) $logRecord->channel;

        $group = hash('xxh128', $level . '|' . $channel);

        return Envelope::build($state, RecordType::Log, $microtime, $group) + [
            'level' => $level,
            'channel' => tiny_text($channel),
            'message' => long_text($logRecord->message),
            'context' => self::scalarize($logRecord->context),
        ];
    }

    /**
     * Flattens Monolog context into a safely serializable structure.
     *
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    private static function scalarize(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $out[$key] = match (true) {
                is_scalar($value), $value === null => $value,
                is_array($value) => self::scalarize($value),
                is_object($value) && method_exists($value, '__toString') => (string) $value,
                is_object($value) => $value::class,
                default => gettype($value),
            };
        }

        return $out;
    }
}
