<?php

declare(strict_types=1);

namespace Kronn\Observability\State;

class CliState extends ExecutionState
{
    public string $source = 'cli';

    public ?string $commandName = null;

    public ?string $rawCommand = null;

    public ?int $exitCode = null;

    public bool $isQueueWorker = false;

    public bool $isScheduler = false;
}
