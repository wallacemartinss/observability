<?php

declare(strict_types=1);

namespace Kronn\Observability\Transports;

use Kronn\Observability\Contracts\Transport;
use RuntimeException;

/**
 * Ships records to the local Kronn agent over TCP.
 *
 * Wire protocol (Kronn v1, line-delimited):
 *
 *   kronn-v1\n
 *   <api-key-hash>\n
 *   <JSON-records>\n
 *
 * Expected reply:
 *
 *   OK\n            -> accepted
 *   ERR <reason>\n  -> rejected (logged and swallowed)
 *
 * Design notes: line-delimited (rather than length-prefix) makes ad-hoc
 * debugging via nc/telnet trivial; the efficiency hit is negligible for
 * the payload sizes we expect.
 *
 * The Kronn agent that consumes this protocol does not exist yet — this
 * class is the client half. Once the agent ships, just point
 * KRONN_SOCKET_ENDPOINT at it.
 */
class SocketTransport implements Transport
{
    public function __construct(
        private readonly string $endpoint,
        private readonly float $connectTimeout,
        private readonly float $readTimeout,
        private readonly string $apiKeyHash,
    ) {
    }

    public function ship(array $records): void
    {
        if ($records === []) {
            return;
        }

        try {
            $payload = json_encode($records, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return;
        }

        $frame = "kronn-v1\n{$this->apiKeyHash}\n{$payload}\n";
        $this->sendFrame($frame);
    }

    public function probe(): bool
    {
        try {
            $stream = $this->open();
            $this->sendOnStream($stream, "kronn-v1\n{$this->apiKeyHash}\nPING\n");
            $reply = $this->readLine($stream);
            $this->close($stream);

            return $reply === 'OK';
        } catch (RuntimeException) {
            return false;
        }
    }

    private function sendFrame(string $frame): void
    {
        try {
            $stream = $this->open();
            $this->sendOnStream($stream, $frame);
            $reply = $this->readLine($stream);
            $this->close($stream);

            if ($reply !== 'OK') {
                throw new RuntimeException("Agent rejected frame: '{$reply}'");
            }
        } catch (RuntimeException) {
            // Silent failure — we'd rather drop telemetry than break the host app.
        }
    }

    /** @return resource */
    private function open()
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            "tcp://{$this->endpoint}",
            $errno,
            $errstr,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
        );

        if ($stream === false) {
            throw new RuntimeException("Failed to connect to {$this->endpoint}: {$errstr} ({$errno})");
        }

        $sec = (int) $this->readTimeout;
        $usec = (int) (($this->readTimeout - $sec) * 1_000_000);
        stream_set_timeout($stream, $sec, $usec);

        return $stream;
    }

    /** @param  resource  $stream */
    private function sendOnStream($stream, string $data): void
    {
        $remaining = $data;
        while ($remaining !== '') {
            $written = @fwrite($stream, $remaining);
            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write to the agent stream');
            }
            $remaining = substr($remaining, $written);
        }
    }

    /** @param  resource  $stream */
    private function readLine($stream): string
    {
        $line = '';
        while (! feof($stream)) {
            $byte = @fgetc($stream);
            if ($byte === false || $byte === "\n") {
                break;
            }
            $line .= $byte;
            if (strlen($line) > 256) {
                break;
            }
        }

        return $line;
    }

    /** @param  resource  $stream */
    private function close($stream): void
    {
        @fclose($stream);
    }
}
