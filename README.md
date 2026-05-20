# kronn/observability

Telemetry and APM SDK for the Kronn platform, built for Laravel applications.

Instruments the framework's lifecycle — HTTP requests, Artisan commands, database queries, queued jobs, outgoing HTTP calls, cache events, mail, notifications, exceptions and logs — and ships *records* to the Kronn backend.

> **Status:** `0.1.0-dev`. The Kronn ingest backend is still being built. While that's in progress, set `KRONN_TRANSPORT=log` to inspect the records the SDK would emit, locally at `storage/logs/kronn.ndjson`.

## Supported versions

- PHP **8.2+**
- Laravel **10.x / 11.x / 12.x / 13.x**

## Installation

```bash
composer require kronn/observability
```

## Configuration

### Production (target, once `HttpTransport` ships)

A single environment variable is all that's needed:

```dotenv
KRONN_API_KEY=kn_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
KRONN_TRANSPORT=http
```

> **Note for v0.1.0-dev:** `HttpTransport` is not implemented yet. Once it ships, the default of `KRONN_TRANSPORT` flips to `http` and the production `.env` reduces to just `KRONN_API_KEY`.

The API key is generated inside Kronn (**Observability → Enable**). Paste it into your `.env` and the SDK starts shipping telemetry to the backend automatically.

The backend endpoint is hardcoded to `https://ingest.kronn.io/v1/ingest`. You do not need to configure it.

### Development (no backend running yet)

To inspect the records the SDK *would* send without making any network calls:

```dotenv
KRONN_API_KEY=local-dev
KRONN_TRANSPORT=log
```

Records are written as NDJSON to `storage/logs/kronn.ndjson`, one per line, ready to inspect with `cat`/`jq`.

### Test environment

In `phpunit.xml` (or equivalent):

```xml
<env name="KRONN_TRANSPORT" value="null"/>
```

The `null` driver discards everything silently — tests stay isolated with no side effects.

### Endpoint override (internal dev/staging only)

The `KRONN_INGEST_URL` variable lets you point the SDK at non-production environments — staging, a local ingest instance, etc. **Production customers never need to touch it.**

```dotenv
KRONN_INGEST_URL=http://localhost:8000/v1/ingest
```

### Publishing the config (optional)

For customization beyond what env vars cover (filters, thresholds, etc.):

```bash
php artisan vendor:publish --tag=observability-config
```

## Public API usage

```php
use Kronn\Observability\Facades\Telemetry;

Telemetry::user(fn ($user) => ['id' => $user->id, 'name' => $user->name]);
Telemetry::tag(['tenant' => 'acme', 'feature' => 'new-checkout']);
Telemetry::extra(['cart_size' => 12]);
Telemetry::report($exception); // manually report an exception
```

## Artisan commands

```bash
php artisan observability:status   # show config + run a transport probe
php artisan observability:sample   # emit synthetic records to validate the stack
```

## Recognized environment variables

| Variable | Default | Purpose |
|---|---|---|
| `KRONN_API_KEY` | — | **Required.** Token generated inside Kronn. |
| `KRONN_ENABLED` | `false` | Toggles collection on/off globally. |
| `KRONN_TRANSPORT` | `log` (will flip to `http` once `HttpTransport` ships) | `http` (prod), `log` (dev), `null` (test). |
| `KRONN_INGEST_URL` | `https://ingest.kronn.io/v1/ingest` | Endpoint override (internal dev/staging only). |
| `KRONN_ENV` | `APP_ENV` | Environment label shown in Kronn. |
| `KRONN_SERVER` | `gethostname()` | Host identifier. |
| `KRONN_DEPLOY` | (autodetect) | Current deploy hash/ID. |
| `KRONN_SAMPLE_REQUESTS` | `1.0` | Sampling rate for HTTP requests. |
| `KRONN_SAMPLE_COMMANDS` | `1.0` | Sampling rate for CLI commands. |
| `KRONN_SAMPLE_EXCEPTIONS` | `1.0` | Sampling rate for exceptions. |
| `KRONN_IGNORE_QUERIES` | `false` | Skip query records. |
| `KRONN_IGNORE_CACHE_EVENTS` | `false` | Skip cache event records. |
| `KRONN_IGNORE_OUTGOING_REQUESTS` | `false` | Skip outgoing Guzzle request records. |
| `KRONN_IGNORE_MAIL` | `false` | Skip mail records. |
| `KRONN_IGNORE_NOTIFICATIONS` | `false` | Skip notification records. |
| `KRONN_CAPTURE_PAYLOAD` | `false` | Capture HTTP request bodies (with redaction). |
| `KRONN_CAPTURE_EXCEPTION_SOURCE` | `true` | Include ±5 lines of source around exceptions. |
| `KRONN_REDACT_PAYLOAD` | `_token,password,...` | Payload keys to redact. |
| `KRONN_REDACT_HEADERS` | `Authorization,Cookie,...` | Headers to redact. |
| `KRONN_BUFFER_CAPACITY` | `500` | Max records in the in-memory buffer. |
| `KRONN_HTTP_TIMEOUT` | `2.0` | HTTP transport timeout (seconds). |
| `KRONN_HTTP_RETRIES` | `3` | Retry attempts with exponential backoff. |
| `KRONN_HTTP_COMPRESS` | `true` | Gzip the body before sending. |
| `KRONN_LONG_REQUEST_MS` | `30000` | Watchdog: force a digest if a request runs longer than this. |
| `KRONN_LONG_COMMAND_MS` | `60000` | Watchdog: force a digest if a CLI command runs longer than this. |

## Architecture

See `docs/BACKEND_ARCHITECTURE.md` for the full platform design (SDK + ingest + storage + Kronn main app).

## Acknowledgements

The architecture of this package — client/backend split, per-event *sensors*, producer-side sampling of *records*, *trace id* propagation via `Context` — is inspired by the excellent `laravel/nightwatch` (MIT, © Taylor Otwell). This project is, however, an independent implementation: its own schema, its own protocol, its own API.

## License

MIT — see [`LICENSE`](./LICENSE).
