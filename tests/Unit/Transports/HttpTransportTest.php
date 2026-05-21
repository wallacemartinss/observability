<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Transports;

use Closure;
use Kronn\Observability\Transports\HttpTransport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class HttpTransportTest extends TestCase
{
    /** @var list<array{url:string, method:string, body:string, headers:list<string>}> */
    private array $calls = [];

    /** @var list<array{0:int, 1:string, 2:?int}> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->responses = [];
    }

    private function makeTransport(
        int $retryAttempts = 2,
        bool $compress = true,
        bool $idempotent = false,
    ): HttpTransport {
        $calls = &$this->calls;
        $responses = &$this->responses;

        $executor = static function (string $url, string $method, string $body, array $headers) use (&$calls, &$responses): array {
            $calls[] = compact('url', 'method', 'body', 'headers');
            return array_shift($responses) ?? [500, '', null];
        };

        return new HttpTransport(
            endpoint: 'https://example.test/v1/ingest',
            apiKey: 'kn_live_test',
            timeoutSeconds: 0.1,
            retryAttempts: $retryAttempts,
            compress: $compress,
            idempotent: $idempotent,
            httpExecutor: Closure::fromCallable($executor),
        );
    }

    public function test_ship_sends_a_single_post_on_202(): void
    {
        $this->responses = [[202, '{"accepted":1}', null]];
        $transport = $this->makeTransport();

        $transport->ship([['kronn' => ['type' => 'request'], 'duration_ms' => 12]]);

        self::assertCount(1, $this->calls);
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://example.test/v1/ingest', $this->calls[0]['url']);
    }

    public function test_ship_with_empty_batch_is_a_noop(): void
    {
        $transport = $this->makeTransport();

        $transport->ship([]);

        self::assertCount(0, $this->calls);
    }

    public function test_authorization_and_content_type_headers_are_set(): void
    {
        $this->responses = [[202, '', null]];
        $transport = $this->makeTransport();

        $transport->ship([['x' => 1]]);

        $headers = $this->calls[0]['headers'];
        self::assertContains('Authorization: Bearer kn_live_test', $headers);
        self::assertContains('Content-Type: application/json', $headers);
        self::assertTrue($this->hasHeaderPrefix($headers, 'User-Agent: kronn-observability/'));
    }

    public function test_compress_adds_gzip_encoding_header_and_gzipped_body(): void
    {
        $this->responses = [[202, '', null]];
        $transport = $this->makeTransport(compress: true);

        $transport->ship([['x' => 1]]);

        self::assertContains('Content-Encoding: gzip', $this->calls[0]['headers']);
        $decoded = gzdecode($this->calls[0]['body']);
        self::assertNotFalse($decoded);
        self::assertSame([['x' => 1]], json_decode($decoded, true));
    }

    public function test_compress_disabled_sends_plain_json(): void
    {
        $this->responses = [[202, '', null]];
        $transport = $this->makeTransport(compress: false);

        $transport->ship([['x' => 1]]);

        self::assertNotContains('Content-Encoding: gzip', $this->calls[0]['headers']);
        self::assertSame([['x' => 1]], json_decode($this->calls[0]['body'], true));
    }

    public function test_idempotency_header_only_when_enabled(): void
    {
        $this->responses = [[202, '', null]];
        $a = $this->makeTransport(idempotent: false);
        $a->ship([['x' => 1]]);
        $headersWithoutIdempotency = $this->calls[0]['headers'];

        $this->calls = [];
        $this->responses = [[202, '', null]];
        $b = $this->makeTransport(idempotent: true);
        $b->ship([['x' => 1]]);
        $headersWithIdempotency = $this->calls[0]['headers'];

        self::assertFalse($this->hasHeaderPrefix($headersWithoutIdempotency, 'X-Kronn-Batch-Id:'));
        self::assertTrue($this->hasHeaderPrefix($headersWithIdempotency, 'X-Kronn-Batch-Id:'));
    }

    public function test_401_disables_transport_and_invokes_callback(): void
    {
        $this->responses = [[401, '{"error":"invalid_api_key"}', null]];
        $transport = $this->makeTransport();
        $fired = false;
        $transport->onUnauthorized = static function () use (&$fired): void {
            $fired = true;
        };

        $transport->ship([['x' => 1]]);
        $transport->ship([['y' => 2]]); // must be a no-op after 401

        self::assertTrue($fired);
        self::assertCount(1, $this->calls, 'transport must stop calling the executor after 401');
    }

    public function test_4xx_other_than_429_drops_without_retry(): void
    {
        $this->responses = [[400, '{"error":"bad"}', null]];
        $transport = $this->makeTransport();

        $transport->ship([['x' => 1]]);

        self::assertCount(1, $this->calls);
    }

    public function test_429_retries_until_success(): void
    {
        $this->responses = [
            [429, '', 0],                 // first call → 429, retry-after 0
            [202, '{"ok":true}', null],   // second call → success
        ];
        $transport = $this->makeTransport(retryAttempts: 2);

        $transport->ship([['x' => 1]]);

        self::assertCount(2, $this->calls);
    }

    public function test_5xx_retries_until_attempts_exhausted(): void
    {
        $this->responses = [
            [503, '', null],
            [503, '', null],
            [503, '', null],
        ];
        $transport = $this->makeTransport(retryAttempts: 2);

        $transport->ship([['x' => 1]]);

        // Initial attempt + 2 retries = 3 total invocations.
        self::assertCount(3, $this->calls);
    }

    public function test_connection_error_retries(): void
    {
        $this->responses = [
            [0, '', null],
            [202, '', null],
        ];
        $transport = $this->makeTransport(retryAttempts: 1);

        $transport->ship([['x' => 1]]);

        self::assertCount(2, $this->calls);
    }

    public function test_oversized_batch_is_partitioned(): void
    {
        $this->responses = array_fill(0, 16, [202, '', null]);
        // Each record ~9 KB; need ~120 records to cross 1 MB once encoded.
        $blob = str_repeat('x', 9000);
        $records = [];
        for ($i = 0; $i < 250; $i++) {
            $records[] = ['kronn' => ['type' => 'request'], 'blob' => $blob, 'index' => $i];
        }
        $transport = $this->makeTransport(retryAttempts: 0, compress: false);

        $transport->ship($records);

        self::assertGreaterThan(1, count($this->calls), 'oversized payload must split into multiple POSTs');
        foreach ($this->calls as $i => $call) {
            self::assertLessThanOrEqual(
                1_000_000,
                strlen($call['body']),
                "sub-batch #{$i} should be under 1 MB"
            );
        }
    }

    public function test_probe_returns_true_on_2xx(): void
    {
        $this->responses = [[200, '', null]];
        $transport = $this->makeTransport();

        self::assertTrue($transport->probe());
        self::assertSame('HEAD', $this->calls[0]['method']);
        self::assertSame('https://example.test/v1/health', $this->calls[0]['url']);
    }

    public function test_probe_returns_false_on_non_2xx(): void
    {
        $this->responses = [[503, '', null]];
        $transport = $this->makeTransport();

        self::assertFalse($transport->probe());
    }

    public function test_parse_stream_headers_reads_status_and_retry_after(): void
    {
        $parse = new ReflectionMethod(HttpTransport::class, 'parseStreamHeaders');

        self::assertSame(
            [202, null],
            $parse->invoke(null, ['HTTP/1.1 202 Accepted', 'Content-Type: application/json']),
        );

        self::assertSame(
            [429, 30],
            $parse->invoke(null, ['HTTP/1.1 429 Too Many Requests', 'Retry-After: 30']),
        );

        self::assertSame(
            [500, null],
            $parse->invoke(null, ['HTTP/1.0 500 Internal Server Error']),
        );

        // A redirect chain stacks status lines — the last one wins.
        self::assertSame(
            [200, null],
            $parse->invoke(null, ['HTTP/1.1 301 Moved Permanently', 'Location: /x', 'HTTP/1.1 200 OK']),
        );

        // Non-numeric Retry-After (HTTP-date form) is ignored, like the curl path.
        self::assertSame(
            [429, null],
            $parse->invoke(null, ['HTTP/1.1 429 Too Many Requests', 'Retry-After: Wed, 21 May 2026 07:28:00 GMT']),
        );

        // No status line at all → 0, which the ship loop treats as retryable.
        self::assertSame([0, null], $parse->invoke(null, ['Content-Type: text/plain']));
        self::assertSame([0, null], $parse->invoke(null, []));
    }

    /**
     * @param  list<string>  $headers
     */
    private function hasHeaderPrefix(array $headers, string $prefix): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}
