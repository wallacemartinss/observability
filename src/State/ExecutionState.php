<?php

declare(strict_types=1);

namespace Kronn\Observability\State;

use Kronn\Observability\Phase;

/**
 * Base execution state (request or command). Properties are public on
 * purpose: this is a data carrier mutated by the Core and the sensors.
 * Keep behavior out of here.
 */
abstract class ExecutionState
{
    public string $source = 'http'; // http | cli | queue | scheduler

    public float $startedAtMicrotime;

    public Phase $phase = Phase::Boot;

    public float $phaseStartedAtMicrotime;

    /** @var array<string, float> Phase value -> accumulated milliseconds */
    public array $phaseDurationsMs = [];

    public string $traceId;

    public string $executionId;

    public ?string $parentTraceId = null;

    public string $deployment;

    public string $environment;

    public string $server;

    public ?UserResolver $user = null;

    /** @var array<string, mixed> Free-form tags attached by the application */
    public array $tags = [];

    /** @var array<string, mixed> Extra fields attached to the current record */
    public array $extras = [];

    // -------------------- aggregate counters --------------------

    public int $queryCount = 0;
    public int $slowQueryCount = 0;
    public float $queryDurationMs = 0.0;
    public int $lazyLoadCount = 0;

    public int $cacheHitCount = 0;
    public int $cacheMissCount = 0;
    public int $cacheWriteCount = 0;
    public int $cacheForgetCount = 0;

    public int $outgoingRequestCount = 0;
    public int $slowOutgoingRequestCount = 0;
    public float $outgoingRequestDurationMs = 0.0;

    public int $mailCount = 0;
    public int $notificationCount = 0;
    public int $queuedJobCount = 0;

    public int $handledExceptionCount = 0;
    public int $unhandledExceptionCount = 0;

    /** @var array<string, int> Level -> count */
    public array $logCountsByLevel = [];

    public function __construct(
        float $startedAtMicrotime,
        string $traceId,
        string $executionId,
        string $deployment,
        string $environment,
        string $server,
        ?UserResolver $user = null,
    ) {
        $this->startedAtMicrotime = $startedAtMicrotime;
        $this->phaseStartedAtMicrotime = $startedAtMicrotime;
        $this->traceId = $traceId;
        $this->executionId = $executionId;
        $this->deployment = $deployment;
        $this->environment = $environment;
        $this->server = $server;
        $this->user = $user;
    }

    public function recordLog(string $level): void
    {
        $this->logCountsByLevel[$level] = ($this->logCountsByLevel[$level] ?? 0) + 1;
    }

    /**
     * Zero the state while keeping fixed identity (deploy, server, env,
     * user provider). Used by queue workers between jobs.
     */
    public function resetForNextExecution(float $startedAtMicrotime, string $traceId, string $executionId): void
    {
        $this->startedAtMicrotime = $startedAtMicrotime;
        $this->phaseStartedAtMicrotime = $startedAtMicrotime;
        $this->phase = Phase::Boot;
        $this->phaseDurationsMs = [];
        $this->traceId = $traceId;
        $this->executionId = $executionId;
        $this->parentTraceId = null;
        $this->tags = [];
        $this->extras = [];

        $this->queryCount = 0;
        $this->slowQueryCount = 0;
        $this->queryDurationMs = 0.0;
        $this->lazyLoadCount = 0;
        $this->cacheHitCount = 0;
        $this->cacheMissCount = 0;
        $this->cacheWriteCount = 0;
        $this->cacheForgetCount = 0;
        $this->outgoingRequestCount = 0;
        $this->slowOutgoingRequestCount = 0;
        $this->outgoingRequestDurationMs = 0.0;
        $this->mailCount = 0;
        $this->notificationCount = 0;
        $this->queuedJobCount = 0;
        $this->handledExceptionCount = 0;
        $this->unhandledExceptionCount = 0;
        $this->logCountsByLevel = [];

        $this->user?->reset();
    }
}
