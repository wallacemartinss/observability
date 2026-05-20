<?php

declare(strict_types=1);

namespace Kronn\Observability\Support;

class Clock
{
    /** @var (callable(): float)|null */
    private $microtimeFn;

    /** @var (callable(): int)|null */
    private $timeFn;

    public function __construct(?callable $microtimeFn = null, ?callable $timeFn = null)
    {
        $this->microtimeFn = $microtimeFn;
        $this->timeFn = $timeFn;
    }

    public function microtime(): float
    {
        return $this->microtimeFn !== null ? ($this->microtimeFn)() : microtime(true);
    }

    public function unixSeconds(): int
    {
        return $this->timeFn !== null ? ($this->timeFn)() : time();
    }

    /**
     * Milliseconds elapsed between two timestamps (seconds with fraction).
     */
    public function elapsedMs(float $startSeconds, ?float $endSeconds = null): float
    {
        return (($endSeconds ?? $this->microtime()) - $startSeconds) * 1000.0;
    }

    public function nowMicrotime(): float
    {
        return $this->microtimeFn !== null ? ($this->microtimeFn)() : microtime(true);
    }
}
