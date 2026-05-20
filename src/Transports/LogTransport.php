<?php

declare(strict_types=1);

namespace Kronn\Observability\Transports;

use Kronn\Observability\Contracts\Transport;
use RuntimeException;

/**
 * Writes one NDJSON line per record into a local file. Handy in
 * development to inspect what's being emitted, and in production as a
 * fallback when the remote transport is unreachable.
 *
 * The file is opened/closed for every batch — we don't keep a handle,
 * and the filesystem serializes concurrent writers via append-only
 * semantics.
 */
class LogTransport implements Transport
{
    public function __construct(private readonly string $path)
    {
    }

    public function ship(array $records): void
    {
        if ($records === []) {
            return;
        }

        $this->ensureDirectory();

        $lines = '';
        foreach ($records as $record) {
            try {
                $lines .= json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) . "\n";
            } catch (\JsonException) {
                // Skip individual broken records rather than killing the batch.
                continue;
            }
        }

        if ($lines === '') {
            return;
        }

        @file_put_contents($this->path, $lines, FILE_APPEND | LOCK_EX);
    }

    public function probe(): bool
    {
        try {
            $this->ensureDirectory();
            return is_writable(dirname($this->path));
        } catch (RuntimeException) {
            return false;
        }
    }

    private function ensureDirectory(): void
    {
        $directory = dirname($this->path);
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }
    }
}
