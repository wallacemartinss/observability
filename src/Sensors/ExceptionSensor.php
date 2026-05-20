<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Kronn\Observability\Records\Exception as ExceptionRecord;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\Location;
use Throwable;

class ExceptionSensor
{
    public function __construct(
        private readonly ExecutionState $state,
        private readonly Clock $clock,
        private readonly Location $location,
        private readonly bool $captureSource,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Throwable $throwable, bool $handled): array
    {
        if ($handled) {
            $this->state->handledExceptionCount++;
        } else {
            $this->state->unhandledExceptionCount++;
        }

        return ExceptionRecord::make(
            state: $this->state,
            throwable: $throwable,
            handled: $handled,
            captureSource: $this->captureSource,
            clock: $this->clock,
            location: $this->location,
        );
    }
}
