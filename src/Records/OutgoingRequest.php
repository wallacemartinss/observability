<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Kronn\Observability\State\ExecutionState;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

use function Kronn\Observability\Support\tiny_text;

class OutgoingRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function make(
        ExecutionState $state,
        float $startMicrotime,
        float $endMicrotime,
        RequestInterface $request,
        ResponseInterface $response,
        float $slowThresholdMs,
    ): array {
        $uri = $request->getUri();
        $durationMs = ($endMicrotime - $startMicrotime) * 1000.0;
        $statusCode = $response->getStatusCode();

        $group = hash('xxh128', sprintf(
            '%s|%s|%s|%d',
            $request->getMethod(),
            $uri->getHost(),
            self::pathShape($uri->getPath()),
            intdiv($statusCode, 100) * 100,
        ));

        return Envelope::build($state, RecordType::OutgoingRequest, $endMicrotime, $group) + [
            'method' => $request->getMethod(),
            'scheme' => $uri->getScheme(),
            'host' => tiny_text($uri->getHost()),
            'port' => $uri->getPort(),
            'path' => tiny_text($uri->getPath()),
            'path_shape' => tiny_text(self::pathShape($uri->getPath())),
            'status_code' => $statusCode,
            'duration_ms' => round($durationMs, 3),
            'slow' => $durationMs >= $slowThresholdMs,
            'request_bytes' => $request->getBody()->getSize(),
            'response_bytes' => $response->getBody()->getSize(),
        ];
    }

    /**
     * Replaces numeric path segments with {id} so similar paths group
     * together. Example: /users/123/orders/456 -> /users/{id}/orders/{id}.
     */
    private static function pathShape(string $path): string
    {
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            if ($segment === '' || ! preg_match('/[a-z]/i', $segment)) {
                if (preg_match('/^[0-9a-f-]{8,}$/i', $segment)) {
                    $segments[$i] = '{id}';
                }
            } elseif (preg_match('/^\d+$/', $segment)) {
                $segments[$i] = '{id}';
            }
        }

        return implode('/', $segments);
    }
}
