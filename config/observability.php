<?php

declare(strict_types=1);

return [

    /*
    |----------------------------------------------------------------------
    | Master switch
    |----------------------------------------------------------------------
    |
    | When false the ServiceProvider still registers the Core binding (so
    | the container does not error if the Facade is resolved) but no
    | listener is wired and no record is emitted. Zero cost.
    |
    */
    'enabled' => env('KRONN_ENABLED', false),

    'api_key' => env('KRONN_API_KEY'),

    'environment' => env('KRONN_ENV', env('APP_ENV', 'production')),

    'deployment' => env(
        'KRONN_DEPLOY',
        env('LARAVEL_CLOUD_DEPLOY_UUID', env('FORGE_DEPLOY_COMMIT', env('VAPOR_COMMIT_HASH')))
    ),

    'server' => env('KRONN_SERVER', (string) gethostname()),

    /*
    |----------------------------------------------------------------------
    | Transport
    |----------------------------------------------------------------------
    |
    | Driver responsible for shipping records. Built-in implementations:
    |  - "null"   discards everything (no cost; useful in tests).
    |  - "log"    writes NDJSON to storage/logs/kronn.ndjson.
    |  - "socket" sends over TCP to the local Kronn agent (port 4317).
    |
    | The interface lives at Kronn\Observability\Contracts\Transport.
    | Register custom drivers via Transports\Factory::extend().
    |
    */
    'transport' => env('KRONN_TRANSPORT', 'log'),

    'drivers' => [
        'null' => [
            'driver' => 'null',
        ],
        'log' => [
            'driver' => 'log',
            'path' => storage_path('logs/kronn.ndjson'),
        ],
        'http' => [
            'driver' => 'http',
            // Hardcoded default — production customers never set KRONN_INGEST_URL.
            // The env var exists only for our own dev/staging environments.
            'endpoint' => env('KRONN_INGEST_URL', 'https://ingest.kronn.io/v1/ingest'),
            'timeout' => env('KRONN_HTTP_TIMEOUT', 2.0),
            'retries' => env('KRONN_HTTP_RETRIES', 3),
            'compress' => env('KRONN_HTTP_COMPRESS', true),
            'idempotent' => env('KRONN_HTTP_IDEMPOTENT', false),
        ],
        'socket' => [
            'driver' => 'socket',
            'endpoint' => env('KRONN_SOCKET_ENDPOINT', '127.0.0.1:4317'),
            'connect_timeout' => env('KRONN_SOCKET_CONNECT_TIMEOUT', 0.5),
            'read_timeout' => env('KRONN_SOCKET_READ_TIMEOUT', 0.5),
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Buffer
    |----------------------------------------------------------------------
    |
    | How many records to accumulate in memory before forcing an early
    | flush. Rarely reached on short HTTP requests; matters for long-lived
    | CLI/worker processes where it bounds memory growth.
    |
    */
    'buffer' => [
        'capacity' => env('KRONN_BUFFER_CAPACITY', 500),
        'flush_when_full' => env('KRONN_BUFFER_FLUSH_WHEN_FULL', true),
    ],

    /*
    |----------------------------------------------------------------------
    | Sampling
    |----------------------------------------------------------------------
    |
    | Probability [0.0, 1.0] of keeping a record. The decision is made at
    | the start of each execution (request/command) and propagated via
    | Context — every child record follows the same decision.
    |
    */
    'sampling' => [
        'requests' => env('KRONN_SAMPLE_REQUESTS', 1.0),
        'commands' => env('KRONN_SAMPLE_COMMANDS', 1.0),
        'exceptions' => env('KRONN_SAMPLE_EXCEPTIONS', 1.0),
        'scheduled_tasks' => env('KRONN_SAMPLE_SCHEDULED_TASKS', 1.0),
    ],

    /*
    |----------------------------------------------------------------------
    | Filters
    |----------------------------------------------------------------------
    |
    | Disable entire categories of records without touching code. Useful
    | to silence noisy workloads.
    |
    */
    'filters' => [
        'ignore_cache_events' => env('KRONN_IGNORE_CACHE_EVENTS', false),
        'ignore_queries' => env('KRONN_IGNORE_QUERIES', false),
        'ignore_outgoing_requests' => env('KRONN_IGNORE_OUTGOING_REQUESTS', false),
        'ignore_mail' => env('KRONN_IGNORE_MAIL', false),
        'ignore_notifications' => env('KRONN_IGNORE_NOTIFICATIONS', false),
        'log_level' => env('KRONN_LOG_LEVEL', env('LOG_LEVEL', 'debug')),

        // Opt-in: enables Model::preventLazyLoading() and records every
        // Eloquent lazy-loading violation (model + relation + origin).
        // The violation handler is non-throwing — the app keeps working,
        // we just observe. Off by default since it flips a global
        // Eloquent setting.
        'detect_lazy_loading' => env('KRONN_DETECT_LAZY_LOADING', false),
    ],

    /*
    |----------------------------------------------------------------------
    | Privacy
    |----------------------------------------------------------------------
    |
    | Payload keys and header names to redact before serialization.
    | capture_request_payload must be opted into explicitly.
    |
    */
    'privacy' => [
        'capture_request_payload' => env('KRONN_CAPTURE_PAYLOAD', false),
        'capture_exception_source' => env('KRONN_CAPTURE_EXCEPTION_SOURCE', true),
        'redact_payload_fields' => array_filter(array_map('trim', explode(
            ',',
            env('KRONN_REDACT_PAYLOAD', '_token,password,password_confirmation,api_key,secret')
        ))),
        'redact_headers' => array_filter(array_map('trim', explode(
            ',',
            env('KRONN_REDACT_HEADERS', 'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN,X-API-Key')
        ))),
    ],

    /*
    |----------------------------------------------------------------------
    | Thresholds (watchdog)
    |----------------------------------------------------------------------
    |
    | Force an early digest when a long-running execution crosses a
    | threshold. All values in milliseconds.
    |
    */
    'thresholds' => [
        'slow_query_ms' => env('KRONN_SLOW_QUERY_MS', 100),
        'slow_outgoing_ms' => env('KRONN_SLOW_OUTGOING_MS', 1000),
        'long_request_ms' => env('KRONN_LONG_REQUEST_MS', 30_000),
        'long_command_ms' => env('KRONN_LONG_COMMAND_MS', 60_000),
    ],

];
