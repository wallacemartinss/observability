<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Illuminate\Cache\Events\CacheEvent as IlluminateCacheEvent;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Kronn\Observability\Phase;
use Kronn\Observability\State\CliState;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\State\HttpState;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\LaravelFeatures;
use Kronn\Observability\Support\Location;
use Monolog\LogRecord;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lazy factory for sensors. Each sensor is instantiated on first use and
 * kept across invocations (but reset between jobs inside a queue worker
 * via reset()).
 *
 * Sensor dependencies (slow thresholds, redaction lists, source capture)
 * are read from the options array provided by the Core — there is no
 * reference to the Laravel Config repository here, to keep the module
 * isolated.
 */
class Manager
{
    private ?PhaseSensor $phase = null;
    private ?RequestSensor $request = null;
    private ?CommandSensor $command = null;
    private ?QuerySensor $query = null;
    private ?ExceptionSensor $exception = null;
    private ?OutgoingRequestSensor $outgoingRequest = null;
    private ?CacheEventSensor $cacheEvent = null;
    private ?MailSensor $mail = null;
    private ?NotificationSensor $notification = null;
    private ?QueuedJobSensor $queuedJob = null;
    private ?JobAttemptSensor $jobAttempt = null;
    private ?ScheduledTaskSensor $scheduledTask = null;
    private ?LogSensor $log = null;
    private ?LazyLoadSensor $lazyLoad = null;

    /**
     * @param  array{
     *   slow_query_ms: float,
     *   slow_outgoing_ms: float,
     *   capture_payload: bool,
     *   capture_exception_source: bool,
     *   redact_payload: list<string>,
     *   redact_headers: list<string>
     * }  $options
     */
    public function __construct(
        private ExecutionState $state,
        private readonly Clock $clock,
        private readonly Location $location,
        private readonly LaravelFeatures $features,
        private array $options,
    ) {
    }

    public function transitionPhase(Phase $phase): void
    {
        ($this->phase ??= new PhaseSensor($this->state, $this->clock))->transition($phase);
    }

    /**
     * @return array<string, mixed>
     */
    public function request(Request $request, Response $response): array
    {
        $sensor = $this->request ??= new RequestSensor(
            state: $this->httpState(),
            clock: $this->clock,
            capturePayload: $this->options['capture_payload'],
            redactPayloadFields: $this->options['redact_payload'],
            redactHeaders: $this->options['redact_headers'],
        );

        return $sensor($request, $response);
    }

    /**
     * @return array<string, mixed>
     */
    public function command(InputInterface $input, int $exitCode): array
    {
        $sensor = $this->command ??= new CommandSensor($this->cliState(), $this->clock);

        return $sensor($input, $exitCode);
    }

    /**
     * @return array<string, mixed>
     */
    public function query(QueryExecuted $event): array
    {
        $sensor = $this->query ??= new QuerySensor(
            state: $this->state,
            clock: $this->clock,
            location: $this->location,
            slowThresholdMs: $this->options['slow_query_ms'],
        );

        return $sensor($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function exception(Throwable $throwable, bool $handled): array
    {
        $sensor = $this->exception ??= new ExceptionSensor(
            state: $this->state,
            clock: $this->clock,
            location: $this->location,
            captureSource: $this->options['capture_exception_source'],
        );

        return $sensor($throwable, $handled);
    }

    /**
     * @return array<string, mixed>
     */
    public function outgoingRequest(
        float $startMicrotime,
        float $endMicrotime,
        RequestInterface $request,
        ResponseInterface $response,
    ): array {
        $sensor = $this->outgoingRequest ??= new OutgoingRequestSensor(
            state: $this->state,
            slowThresholdMs: $this->options['slow_outgoing_ms'],
        );

        return $sensor($startMicrotime, $endMicrotime, $request, $response);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cacheEvent(IlluminateCacheEvent $event): ?array
    {
        $sensor = $this->cacheEvent ??= new CacheEventSensor($this->state, $this->clock, $this->features);

        return $sensor($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function mail(MessageSending|MessageSent $event): array
    {
        $sensor = $this->mail ??= new MailSensor($this->state, $this->clock, $this->features);

        return $sensor($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function notification(NotificationSending|NotificationSent $event): array
    {
        $sensor = $this->notification ??= new NotificationSensor($this->state, $this->clock);

        return $sensor($event);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function queuedJob(JobQueueing|JobQueued $event): ?array
    {
        $sensor = $this->queuedJob ??= new QueuedJobSensor($this->state, $this->clock);

        return $sensor($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function jobAttempt(JobProcessed|JobReleasedAfterException|JobFailed $event): array
    {
        $sensor = $this->jobAttempt ??= new JobAttemptSensor($this->cliState(), $this->clock);

        return $sensor($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduledTask(ScheduledTaskFinished|ScheduledTaskSkipped|ScheduledTaskFailed $event): array
    {
        $sensor = $this->scheduledTask ??= new ScheduledTaskSensor($this->cliState(), $this->clock);

        return $sensor($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function log(LogRecord $record): array
    {
        $sensor = $this->log ??= new LogSensor($this->state, $this->clock);

        return $sensor($record);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lazyLoad(string $modelClass, string $relation): ?array
    {
        $sensor = $this->lazyLoad ??= new LazyLoadSensor($this->state, $this->clock, $this->location);

        return $sensor($modelClass, $relation);
    }

    /**
     * Reset called by queue workers between jobs.
     */
    public function reset(): void
    {
        $this->phase = null;
        $this->request = null;
        $this->command = null;
        $this->query = null;
        $this->exception = null;
        $this->outgoingRequest = null;
        $this->cacheEvent = null;
        $this->mail = null;
        $this->notification = null;
        $this->queuedJob = null;
        $this->jobAttempt = null;
        $this->scheduledTask = null;
        $this->log = null;
        $this->lazyLoad = null;
    }

    public function setState(ExecutionState $state): void
    {
        $this->state = $state;
        $this->reset();
    }

    public function state(): ExecutionState
    {
        return $this->state;
    }

    private function httpState(): HttpState
    {
        if (! $this->state instanceof HttpState) {
            throw new \LogicException('SensorManager is in CLI mode; httpState() is not applicable.');
        }

        return $this->state;
    }

    private function cliState(): CliState
    {
        if (! $this->state instanceof CliState) {
            throw new \LogicException('SensorManager is in HTTP mode; cliState() is not applicable.');
        }

        return $this->state;
    }
}
