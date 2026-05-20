<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\Location;
use Throwable;

use function Kronn\Observability\Support\long_text;
use function Kronn\Observability\Support\text;
use function Kronn\Observability\Support\tiny_text;

class Exception
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        ExecutionState $state,
        Throwable $throwable,
        bool $handled,
        bool $captureSource,
        Clock $clock,
        Location $location,
    ): array {
        $microtime = $clock->microtime();
        $file = $throwable->getFile();
        $normalizedFile = $location->normalize($file);
        $line = $throwable->getLine();

        $group = hash(
            'xxh128',
            $throwable::class . '|' . $throwable->getCode() . '|' . $normalizedFile . '|' . $line,
        );

        return Envelope::build($state, RecordType::Exception, $microtime, $group) + [
            'class' => $throwable::class,
            'message' => text($throwable->getMessage()),
            'code' => (string) $throwable->getCode(),
            'file' => tiny_text($normalizedFile),
            'line' => $line,
            'handled' => $handled,
            'stack' => self::stack($throwable, $location),
            'source' => $captureSource ? self::source($file, $line) : null,
            'previous_class' => $throwable->getPrevious()?->getMessage() !== null
                ? $throwable->getPrevious()::class
                : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function stack(Throwable $throwable, Location $location): array
    {
        $frames = [];

        foreach ($throwable->getTrace() as $frame) {
            $file = (string) ($frame['file'] ?? '');
            $frames[] = [
                'file' => $file !== '' ? $location->normalize($file) : null,
                'line' => $frame['line'] ?? null,
                'class' => $frame['class'] ?? null,
                'function' => $frame['function'] ?? null,
                'is_app_code' => $file !== '' && $location->isAppCode($file),
            ];

            if (count($frames) >= 50) {
                break;
            }
        }

        return $frames;
    }

    /**
     * Captures +/- 5 lines around the offending line.
     *
     * @return array<string, mixed>|null
     */
    private static function source(string $file, int $line): ?array
    {
        if ($file === '' || ! is_readable($file)) {
            return null;
        }

        $contents = @file($file, FILE_IGNORE_NEW_LINES);
        if ($contents === false) {
            return null;
        }

        $start = max(0, $line - 6);
        $end = min(count($contents) - 1, $line + 4);
        $snippet = [];

        for ($i = $start; $i <= $end; $i++) {
            $snippet[$i + 1] = long_text($contents[$i] ?? '');
        }

        return [
            'around_line' => $line,
            'lines' => $snippet,
        ];
    }
}
