<?php

declare(strict_types=1);

namespace Kronn\Observability\Transports;

use Closure;
use JsonException;
use Kronn\Observability\Contracts\Transport;
use Kronn\Observability\Support\Version;

/**
 * Ships record batches to the Kronn ingest backend over HTTPS.
 *
 * Wire summary:
 *  - POST <endpoint> with Bearer <api_key>
 *  - JSON body, optionally gzip-compressed
 *  - 202 Accepted on success (the backend may report partial accept inside the body)
 *  - 429 honors Retry-After, retries with backoff
 *  - 5xx and connection errors retry with exponential backoff + jitter
 *  - 4xx (other than 429) drops the batch without retry
 *  - 401 disables the SDK locally via the optional onUnauthorized callback,
 *    so a misconfigured API key does not keep hammering the backend
 *
 * The default HTTP executor uses ext-curl. Tests inject a custom executor
 * to avoid touching the network.
 */
final class HttpTransport implements Transport
{
    private const MAX_BATCH_BYTES = 1_000_000;     // 1 MB

    private const BACKOFF_BASE_MS = 100;

    private const BACKOFF_MAX_MS = 5_000;

    /**
     * Callable invoked once when the backend returns 401. Receives no
     * arguments. Typical use: flip Core::$enabled to false so the SDK
     * stops trying with bad credentials. Set after construction by the
     * ServiceProvider.
     */
    public ?Closure $onUnauthorized = null;

    private bool $disabled = false;

    private readonly string $userAgent;

    /** @var Closure(string, string, string, list<string>): array{0:int, 1:string, 2:?int} */
    private Closure $httpExecutor;

    /**
     * @param  Closure(string, string, string, list<string>): array{0:int, 1:string, 2:?int}|null  $httpExecutor
     *         Custom HTTP executor. Receives (url, method, body, headers) and must return [status, body, retry_after_seconds].
     *         When null, uses the built-in cURL implementation.
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $apiKey,
        private readonly float $timeoutSeconds = 2.0,
        private readonly int $retryAttempts = 3,
        private readonly bool $compress = true,
        private readonly bool $idempotent = false,
        ?string $userAgent = null,
        ?Closure $httpExecutor = null,
    ) {
        $this->userAgent = $userAgent ?? Version::userAgent();
        $this->httpExecutor = $httpExecutor ?? Closure::fromCallable([$this, 'defaultExecutor']);
    }

    public function ship(array $records): void
    {
        if ($this->disabled || $records === []) {
            return;
        }

        foreach ($this->partition($records) as $batch) {
            if ($this->disabled) {
                return;
            }
            $this->shipBatch($batch);
        }
    }

    public function probe(): bool
    {
        [$status] = ($this->httpExecutor)(
            $this->healthUrl(),
            'HEAD',
            '',
            ['User-Agent: ' . $this->userAgent],
        );

        return $status >= 200 && $status < 300;
    }

    /**
     * Splits a list of records so that no single sub-batch exceeds
     * MAX_BATCH_BYTES once JSON-encoded. Recurses by halving.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<list<array<string, mixed>>>
     */
    private function partition(array $records): array
    {
        $encoded = $this->safeEncode($records);
        if ($encoded === null || strlen($encoded) <= self::MAX_BATCH_BYTES) {
            return [$records];
        }

        if (count($records) <= 1) {
            // A single record exceeds the limit by itself. Send anyway and
            // let the backend reject (413). We can't split further.
            return [$records];
        }

        $half = intdiv(count($records), 2);

        return [
            ...$this->partition(array_slice($records, 0, $half)),
            ...$this->partition(array_slice($records, $half)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $batch
     */
    private function shipBatch(array $batch): void
    {
        $body = $this->safeEncode($batch);
        if ($body === null || $body === '') {
            return;
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'User-Agent: ' . $this->userAgent,
        ];

        if ($this->compress) {
            $compressed = @gzencode($body, 6);
            if ($compressed !== false) {
                $body = $compressed;
                $headers[] = 'Content-Encoding: gzip';
            }
        }

        if ($this->idempotent) {
            $headers[] = 'X-Kronn-Batch-Id: ' . bin2hex(random_bytes(16));
        }

        $attempt = 0;
        while (true) {
            [$status, $_responseBody, $retryAfter] = ($this->httpExecutor)(
                $this->endpoint,
                'POST',
                $body,
                $headers,
            );

            if ($status >= 200 && $status < 300) {
                return;
            }

            if ($status === 401) {
                $this->disabled = true;
                if ($this->onUnauthorized !== null) {
                    ($this->onUnauthorized)();
                }
                return;
            }

            if ($status === 429) {
                if ($attempt >= $this->retryAttempts) {
                    return;
                }
                $waitMs = $retryAfter !== null && $retryAfter > 0
                    ? $retryAfter * 1000
                    : $this->backoffMs($attempt);
                $this->sleepMs($waitMs);
                $attempt++;
                continue;
            }

            if ($status >= 400 && $status < 500) {
                // 4xx other than 429 — payload-side problem. No point retrying.
                return;
            }

            // 0 (connection error) or 5xx — retry with backoff.
            if ($attempt >= $this->retryAttempts) {
                return;
            }
            $this->sleepMs($this->backoffMs($attempt));
            $attempt++;
        }
    }

    /**
     * Exponential backoff with full jitter: 100ms, 400ms, 1600ms, capped at 5s.
     */
    private function backoffMs(int $attempt): int
    {
        $base = self::BACKOFF_BASE_MS * (4 ** $attempt);
        $jitter = random_int(0, max(1, (int) ($base * 0.25)));

        return min(self::BACKOFF_MAX_MS, $base + $jitter);
    }

    private function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /**
     * Derives the health endpoint from the ingest endpoint.
     * Example: https://ingest.kronn.io/v1/ingest -> https://ingest.kronn.io/v1/health
     */
    private function healthUrl(): string
    {
        $derived = preg_replace('#/v1/ingest$#', '/v1/health', $this->endpoint);

        return is_string($derived) && $derived !== ''
            ? $derived
            : rtrim($this->endpoint, '/') . '/health';
    }

    /**
     * @param  list<array<string, mixed>>|array<string, mixed>  $value
     */
    private function safeEncode(array $value): ?string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Built-in cURL executor. Returns [status, response_body, retry_after_seconds].
     *
     * @param  list<string>  $headers
     * @return array{0:int, 1:string, 2:?int}
     */
    private function defaultExecutor(string $url, string $method, string $body, array $headers): array
    {
        if (! function_exists('curl_init')) {
            return [0, '', null];
        }

        $ch = curl_init();
        $retryAfter = null;

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->timeoutSeconds * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeoutSeconds * 1000 * 3),
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $header) use (&$retryAfter): int {
                if (stripos($header, 'Retry-After:') === 0) {
                    $value = trim(substr($header, strlen('Retry-After:')));
                    if (is_numeric($value)) {
                        $retryAfter = (int) $value;
                    }
                }
                return strlen($header);
            },
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        } elseif ($method === 'HEAD') {
            $options[CURLOPT_CUSTOMREQUEST] = 'HEAD';
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== '') {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($responseBody === false) {
            return [0, '', $retryAfter];
        }

        return [$status, (string) $responseBody, $retryAfter];
    }
}
