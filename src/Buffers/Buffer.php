<?php

declare(strict_types=1);

namespace Kronn\Observability\Buffers;

use Countable;

/**
 * Fixed-capacity in-memory FIFO of records. Once capacity is reached the
 * `full` flag is set — the caller decides whether to `pull()` (early
 * flush) or to accept losing the oldest record via `forceWrite()`
 * (drop-head strategy).
 *
 * @template-implements Countable
 */
class Buffer implements Countable
{
    /** @var list<array<string, mixed>> */
    private array $records = [];

    public bool $full = false;

    public function __construct(private readonly int $capacity)
    {
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function write(array $record): bool
    {
        if ($this->full) {
            return false;
        }

        $this->records[] = $record;
        $this->full = count($this->records) >= $this->capacity;

        return true;
    }

    /**
     * Writes even when full, discarding the oldest record (drop-head).
     *
     * @param  array<string, mixed>  $record
     */
    public function forceWrite(array $record): void
    {
        if ($this->full) {
            array_shift($this->records);
        }

        $this->records[] = $record;
        $this->full = count($this->records) >= $this->capacity;
    }

    /**
     * Returns every record and resets the buffer.
     *
     * @return list<array<string, mixed>>
     */
    public function pull(): array
    {
        $records = $this->records;
        $this->flush();

        return $records;
    }

    public function flush(): void
    {
        $this->records = [];
        $this->full = false;
    }

    public function count(): int
    {
        return count($this->records);
    }

    public function isEmpty(): bool
    {
        return $this->records === [];
    }

    public function capacity(): int
    {
        return $this->capacity;
    }
}
