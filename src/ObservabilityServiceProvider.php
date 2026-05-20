<?php

declare(strict_types=1);

namespace Kronn\Observability;

use Illuminate\Auth\AuthManager;
use Illuminate\Cache\Events\CacheEvent as IlluminateCacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\RetrievingManyKeys;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\Events\WritingManyKeys;
use Illuminate\Console\Events\ArtisanStarting;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Events\Terminating;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Queue;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Events\ResponsePrepared;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Kronn\Observability\Buffers\Buffer;
use Kronn\Observability\Console\AgentCommand;
use Kronn\Observability\Console\SampleCommand;
use Kronn\Observability\Console\StatusCommand;
use Kronn\Observability\Listeners\ArtisanStartingListener;
use Kronn\Observability\Listeners\BootListener;
use Kronn\Observability\Listeners\CacheEventListener;
use Kronn\Observability\Listeners\CommandFinishedListener;
use Kronn\Observability\Listeners\CommandStartingListener;
use Kronn\Observability\Listeners\CreateQueuePayloadListener;
use Kronn\Observability\Listeners\ExceptionHandlerResolvedListener;
use Kronn\Observability\Listeners\GuzzleMiddleware;
use Kronn\Observability\Listeners\HttpClientFactoryResolvedListener;
use Kronn\Observability\Listeners\JobAttemptListener;
use Kronn\Observability\Listeners\JobProcessingListener;
use Kronn\Observability\Listeners\LivewireListener;
use Kronn\Observability\Listeners\LogoutListener;
use Kronn\Observability\Listeners\MailListener;
use Kronn\Observability\Listeners\NotificationListener;
use Kronn\Observability\Listeners\OctaneListener;
use Kronn\Observability\Listeners\PreparingResponseListener;
use Kronn\Observability\Listeners\QueryExecutedListener;
use Kronn\Observability\Listeners\QueuedJobListener;
use Kronn\Observability\Listeners\RequestHandledListener;
use Kronn\Observability\Listeners\ResponsePreparedListener;
use Kronn\Observability\Listeners\RouteMatchedListener;
use Kronn\Observability\Listeners\ScheduledTaskListener;
use Kronn\Observability\Listeners\TerminatingListener;
use Kronn\Observability\Middleware\Sample as SampleMiddleware;
use Kronn\Observability\Middleware\Tag as TagMiddleware;
use Kronn\Observability\Records\RecordType;
use Kronn\Observability\Sensors\Manager as SensorManager;
use Kronn\Observability\State\CliState;
use Kronn\Observability\State\ExecutionState;
use Kronn\Observability\State\HttpState;
use Kronn\Observability\State\UserResolver;
use Kronn\Observability\Support\Clock;
use Kronn\Observability\Support\LaravelFeatures;
use Kronn\Observability\Support\Location;
use Kronn\Observability\Support\Uuid;
use Kronn\Observability\Transports\Factory as TransportFactory;
use Throwable;

class ObservabilityServiceProvider extends ServiceProvider
{
    private Core $core;

    private float $startedAtMicrotime;

    private bool $isHttp = true;

    /** @var array<string, mixed> */
    private array $config = [];

    private ?Throwable $registerError = null;

    public function register(): void
    {
        try {
            $this->startedAtMicrotime = $this->resolveStartTimestamp();
            $this->isHttp = ! $this->app->runningInConsole() || Env::get('KRONN_FORCE_HTTP') === '1';

            $this->mergeConfigFrom(__DIR__ . '/../config/observability.php', 'observability');
            $this->config = (array) $this->app->make(Repository::class)->get('observability', []);

            $this->buildCore();

            if (! $this->core->enabled) {
                return;
            }

            $this->registerHooks();
        } catch (Throwable $e) {
            $this->registerError = $e;
        }
    }

    public function boot(): void
    {
        if ($this->registerError !== null) {
            $this->reportRegisterFailure();
            return;
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/observability.php' => $this->app->configPath('observability.php'),
            ], ['observability', 'observability-config']);

            $this->commands([
                StatusCommand::class,
                AgentCommand::class,
                SampleCommand::class,
            ]);
        }

        if (isset($this->core)) {
            $router = $this->app['router'] ?? null;
            if ($router !== null && method_exists($router, 'aliasMiddleware')) {
                $router->aliasMiddleware('observability.sample', SampleMiddleware::class);
                $router->aliasMiddleware('observability.tag', TagMiddleware::class);
            }
        }
    }

    // -------------------------- internal --------------------------

    private function resolveStartTimestamp(): float
    {
        return match (true) {
            defined('LARAVEL_START') => LARAVEL_START,
            default => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
        };
    }

    private function buildCore(): void
    {
        $features = LaravelFeatures::detect($this->app);
        $this->app->instance(LaravelFeatures::class, $features);
        $clock = new Clock();
        $uuid = new Uuid();

        $location = new Location(
            basePath: $this->app->basePath(),
            publicPath: method_exists($this->app, 'publicPath') ? $this->app->publicPath() : null,
        );

        $traceId = $uuid->make();
        $executionId = $uuid->make();

        $userResolver = $this->buildUserResolver();

        $state = $this->isHttp
            ? new HttpState(
                startedAtMicrotime: $this->startedAtMicrotime,
                traceId: $traceId,
                executionId: $executionId,
                deployment: (string) ($this->config['deployment'] ?? ''),
                environment: (string) ($this->config['environment'] ?? 'production'),
                server: (string) ($this->config['server'] ?? ''),
                user: $userResolver,
            )
            : new CliState(
                startedAtMicrotime: $this->startedAtMicrotime,
                traceId: $traceId,
                executionId: $executionId,
                deployment: (string) ($this->config['deployment'] ?? ''),
                environment: (string) ($this->config['environment'] ?? 'production'),
                server: (string) ($this->config['server'] ?? ''),
                user: $userResolver,
            );

        $thresholds = (array) ($this->config['thresholds'] ?? []);
        $privacy = (array) ($this->config['privacy'] ?? []);

        $sensors = new SensorManager(
            state: $state,
            clock: $clock,
            location: $location,
            features: $features,
            options: [
                'slow_query_ms' => (float) ($thresholds['slow_query_ms'] ?? 100),
                'slow_outgoing_ms' => (float) ($thresholds['slow_outgoing_ms'] ?? 1000),
                'capture_payload' => (bool) ($privacy['capture_request_payload'] ?? false),
                'capture_exception_source' => (bool) ($privacy['capture_exception_source'] ?? true),
                'redact_payload' => array_values((array) ($privacy['redact_payload_fields'] ?? [])),
                'redact_headers' => array_values((array) ($privacy['redact_headers'] ?? [])),
            ],
        );

        $buffer = new Buffer((int) ($this->config['buffer']['capacity'] ?? 500));

        $transportFactory = new TransportFactory($uuid);
        $driverName = (string) ($this->config['transport'] ?? 'log');
        $driverConfig = (array) ($this->config['drivers'][$driverName] ?? []);
        $transport = $transportFactory->make($driverName, $driverConfig, (string) ($this->config['api_key'] ?? ''));

        $core = new Core(
            clock: $clock,
            uuid: $uuid,
            sensors: $sensors,
            buffer: $buffer,
            transport: $transport,
            state: $state,
        );

        $core->enabled = (bool) ($this->config['enabled'] ?? false);
        $core->redactPayloadFields = array_values((array) ($privacy['redact_payload_fields'] ?? []));
        $core->redactHeaders = array_values((array) ($privacy['redact_headers'] ?? []));
        $core->capturePayload = (bool) ($privacy['capture_request_payload'] ?? false);
        $core->captureExceptionSource = (bool) ($privacy['capture_exception_source'] ?? true);
        $core->longRequestMs = isset($thresholds['long_request_ms']) ? (float) $thresholds['long_request_ms'] : null;
        $core->longCommandMs = isset($thresholds['long_command_ms']) ? (float) $thresholds['long_command_ms'] : null;

        $core->decideSampling((float) ($this->config['sampling'][$this->isHttp ? 'requests' : 'commands'] ?? 1.0));
        $core->decideExceptionSampling((float) ($this->config['sampling']['exceptions'] ?? 1.0));

        $core->setTypeFilters($this->buildTypeFilters());

        $core->selfFailureReporter = static function (Throwable $throwable): void {
            try {
                Log::channel(config('logging.default'))->warning(
                    '[kronn/observability] internal error: ' . $throwable->getMessage(),
                    ['exception' => $throwable]
                );
            } catch (Throwable) {
                // Last-resort fallback: swallow.
            }
        };

        $this->core = $core;
        $this->app->instance(Core::class, $core);
    }

    private function buildUserResolver(): UserResolver
    {
        $core = function (): ?Core {
            return isset($this->core) ? $this->core : null;
        };

        return new UserResolver(
            isolatedRunner: function (callable $callback) use ($core) {
                $c = $core();
                return $c !== null ? $c->ignore(fn () => $callback($this->app->make(AuthManager::class))) : null;
            },
            resolverProvider: function () use ($core): ?callable {
                $c = $core();
                return $c?->userDetailsResolver;
            },
            errorReporter: function (Throwable $e) use ($core): void {
                $core()?->reportSelfFailure($e);
            },
        );
    }

    /**
     * @return array<string, bool>
     */
    private function buildTypeFilters(): array
    {
        $filters = (array) ($this->config['filters'] ?? []);

        return [
            RecordType::Query->value => (bool) ($filters['ignore_queries'] ?? false),
            RecordType::CacheEvent->value => (bool) ($filters['ignore_cache_events'] ?? false),
            RecordType::OutgoingRequest->value => (bool) ($filters['ignore_outgoing_requests'] ?? false),
            RecordType::Mail->value => (bool) ($filters['ignore_mail'] ?? false),
            RecordType::Notification->value => (bool) ($filters['ignore_notifications'] ?? false),
        ];
    }

    private function registerHooks(): void
    {
        $core = $this->core;
        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);

        $this->registerCommonHooks($core, $events);

        if ($this->isHttp) {
            $this->registerHttpHooks($core, $events);
        } else {
            $this->registerCliHooks($core, $events);
        }
    }

    private function registerCommonHooks(Core $core, Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, (new QueryExecutedListener($core))(...));

        $events->listen([
            CacheHit::class, CacheMissed::class, RetrievingKey::class, RetrievingManyKeys::class,
            WritingKey::class, WritingManyKeys::class, KeyWritten::class, KeyWriteFailed::class,
            ForgettingKey::class, KeyForgotten::class, KeyForgetFailed::class,
        ], (new CacheEventListener($core))(...));

        $events->listen([MessageSending::class, MessageSent::class], (new MailListener($core))(...));
        $events->listen([NotificationSending::class, NotificationSent::class], (new NotificationListener($core))(...));
        $events->listen([JobQueueing::class, JobQueued::class], (new QueuedJobListener($core))(...));

        $this->callAfterResolving(ExceptionHandler::class, (new ExceptionHandlerResolvedListener($core))(...));

        $guzzle = new GuzzleMiddleware($core);
        $this->app->instance(GuzzleMiddleware::class, $guzzle);
        $this->callAfterResolving(HttpFactory::class, (new HttpClientFactoryResolvedListener($guzzle))(...));

        // Propagate trace id into queued job payloads (read back when the worker rehydrates the job).
        Queue::createPayloadUsing(new CreateQueuePayloadListener($core));
    }

    private function registerHttpHooks(Core $core, Dispatcher $events): void
    {
        $this->app->booted((new BootListener($core, isHttp: true))(...));
        $events->listen(RouteMatched::class, (new RouteMatchedListener($core))(...));
        $events->listen(PreparingResponse::class, (new PreparingResponseListener($core))(...));
        $events->listen(ResponsePrepared::class, (new ResponsePreparedListener($core))(...));
        $events->listen(Logout::class, (new LogoutListener($core))(...));

        $features = $this->app->make(LaravelFeatures::class);
        $events->listen(RequestHandled::class, (new RequestHandledListener($core, $features))(...));

        if (class_exists(Terminating::class)) {
            $events->listen(Terminating::class, (new TerminatingListener($core))(...));
        }

        $this->registerOctaneHooks($core, $events);
        $this->registerLivewireHooks($core);
    }

    private function registerCliHooks(Core $core, Dispatcher $events): void
    {
        $this->app->booted((new BootListener($core, isHttp: false))(...));
        $events->listen(ArtisanStarting::class, (new ArtisanStartingListener($core))(...));
        $events->listen(CommandStarting::class, (new CommandStartingListener($core))(...));
        $events->listen(CommandFinished::class, (new CommandFinishedListener($core))(...));
        $events->listen(JobProcessing::class, (new JobProcessingListener($core))(...));
        $events->listen([
            JobProcessed::class,
            JobReleasedAfterException::class,
            JobFailed::class,
        ], (new JobAttemptListener($core))(...));
        $events->listen([
            ScheduledTaskFinished::class,
            ScheduledTaskSkipped::class,
            ScheduledTaskFailed::class,
        ], (new ScheduledTaskListener($core))(...));
    }

    /**
     * Wire the Octane RequestReceived event when Octane is installed.
     * Without this, a long-lived Octane worker carries state from the
     * previous request into the next.
     */
    private function registerOctaneHooks(Core $core, Dispatcher $events): void
    {
        $octaneEvent = 'Laravel\\Octane\\Events\\RequestReceived';
        if (! class_exists($octaneEvent)) {
            return;
        }

        $events->listen($octaneEvent, (new OctaneListener($core, $core->clock))(...));
    }

    /**
     * Wire Livewire 2/3 hydration hooks when Livewire is installed.
     */
    private function registerLivewireHooks(Core $core): void
    {
        $livewireClass = 'Livewire\\Livewire';
        if (! class_exists($livewireClass)) {
            return;
        }

        $this->app->booted(static function () use ($livewireClass, $core): void {
            $listener = new LivewireListener($core);

            // Livewire 2
            $livewireClass::listen('component.hydrate.subsequent', $listener->componentHydrateSubsequent(...));

            // Livewire 3
            $livewireClass::listen('hydrate', $listener->hydrate(...));
        });
    }

    private function reportRegisterFailure(): void
    {
        try {
            Log::channel(config('logging.default'))->error(
                '[kronn/observability] fatal error during package boot',
                ['exception' => $this->registerError]
            );
        } catch (Throwable) {
            // Swallow.
        }
        $this->registerError = null;
    }
}
