<?php

declare(strict_types=1);

namespace Kronn\Observability\Concerns;

use Kronn\Observability\Records\RecordType;

/**
 * Holds the sampling decision for the current execution plus static,
 * per-record-type filters. Policy: the keep/drop decision is taken at
 * the start of the execution (request/command) and applied uniformly to
 * every record built within it — exception records use a dedicated
 * sampling rate.
 */
trait RejectsRecords
{
    public bool $sampling = true;

    public bool $exceptionSampling = true;

    public bool $scheduledTaskSampling = true;

    /** @var array<string, bool> Map of RecordType value -> drop flag */
    protected array $typeFilters = [];

    public function decideSampling(float $rate): bool
    {
        $this->sampling = $rate >= 1.0 || ($rate > 0.0 && (mt_rand() / mt_getrandmax()) < $rate);

        return $this->sampling;
    }

    public function decideExceptionSampling(float $rate): bool
    {
        $this->exceptionSampling = $rate >= 1.0 || ($rate > 0.0 && (mt_rand() / mt_getrandmax()) < $rate);

        return $this->exceptionSampling;
    }

    public function decideScheduledTaskSampling(float $rate): bool
    {
        $this->scheduledTaskSampling = $rate >= 1.0 || ($rate > 0.0 && (mt_rand() / mt_getrandmax()) < $rate);

        return $this->scheduledTaskSampling;
    }

    /**
     * @param  array<string, bool>  $filters  RecordType value -> drop flag
     */
    public function setTypeFilters(array $filters): void
    {
        $this->typeFilters = $filters;
    }

    public function ignoreType(RecordType $type): bool
    {
        return $this->typeFilters[$type->value] ?? false;
    }

    public function shouldKeep(RecordType $type): bool
    {
        if ($this->ignoreType($type)) {
            return false;
        }

        return match ($type) {
            RecordType::Exception => $this->exceptionSampling,
            RecordType::ScheduledTask => $this->scheduledTaskSampling,
            default => $this->sampling,
        };
    }
}
