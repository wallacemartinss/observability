<?php

declare(strict_types=1);

namespace Kronn\Observability\Records;

use Illuminate\Http\Request as IlluminateRequest;
use Kronn\Observability\State\HttpState;
use Kronn\Observability\Support\Clock;
use Symfony\Component\HttpFoundation\Response;

use function Kronn\Observability\Support\text;
use function Kronn\Observability\Support\tiny_text;

class Request
{
    /**
     * @param  list<string>  $redactPayloadFields
     * @param  list<string>  $redactHeaders
     * @return array<string, mixed>
     */
    public static function make(
        HttpState $state,
        IlluminateRequest $request,
        Response $response,
        Clock $clock,
        bool $capturePayload,
        array $redactPayloadFields,
        array $redactHeaders,
    ): array {
        $endMicrotime = $clock->microtime();
        $route = $request->route();
        $routeName = $route !== null && is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
        $routeAction = $route !== null && is_object($route) && method_exists($route, 'getActionName') ? $route->getActionName() : null;
        $routeUri = $route !== null && is_object($route) && method_exists($route, 'uri') ? $route->uri() : null;

        $group = hash('xxh128', sprintf(
            '%s|%s|%d',
            $request->method(),
            $routeUri ?? $request->path(),
            self::statusBucket($response->getStatusCode()),
        ));

        return Envelope::build($state, RecordType::Request, $endMicrotime, $group) + [
            'method' => $request->method(),
            'scheme' => $request->getScheme(),
            'host' => tiny_text($request->getHost()),
            'path' => tiny_text('/' . ltrim($request->path(), '/')),
            'route' => [
                'name' => $routeName !== null ? tiny_text((string) $routeName) : null,
                'action' => $routeAction !== null ? tiny_text((string) $routeAction) : null,
                'uri' => $routeUri !== null ? tiny_text((string) $routeUri) : null,
            ],
            'status_code' => $response->getStatusCode(),
            'duration_ms' => round(($endMicrotime - $state->startedAtMicrotime) * 1000, 3),
            'phases_ms' => $state->phaseDurationsMs,
            'bytes_in' => $state->bytesIn,
            'bytes_out' => $state->bytesOut,
            'ip' => $request->ip(),
            'user_agent' => tiny_text((string) $request->userAgent()),
            'referer' => tiny_text((string) $request->headers->get('Referer', '')),
            'user' => $state->user?->details(),
            'counters' => self::counters($state),
            'headers' => self::headers($request, $redactHeaders),
            'payload' => $capturePayload ? self::redactedPayload($request, $redactPayloadFields) : null,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private static function counters(HttpState $state): array
    {
        return [
            'queries' => $state->queryCount,
            'slow_queries' => $state->slowQueryCount,
            'query_duration_ms' => round($state->queryDurationMs, 3),
            'lazy_loads' => $state->lazyLoadCount,
            'cache_hits' => $state->cacheHitCount,
            'cache_misses' => $state->cacheMissCount,
            'cache_writes' => $state->cacheWriteCount,
            'outgoing_requests' => $state->outgoingRequestCount,
            'slow_outgoing_requests' => $state->slowOutgoingRequestCount,
            'outgoing_duration_ms' => round($state->outgoingRequestDurationMs, 3),
            'mail' => $state->mailCount,
            'notifications' => $state->notificationCount,
            'queued_jobs' => $state->queuedJobCount,
            'exceptions_handled' => $state->handledExceptionCount,
            'exceptions_unhandled' => $state->unhandledExceptionCount,
        ];
    }

    /**
     * @param  list<string>  $redactHeaders
     * @return array<string, string>
     */
    private static function headers(IlluminateRequest $request, array $redactHeaders): array
    {
        $redactSet = array_change_key_case(array_flip(array_map('strval', $redactHeaders)));
        $out = [];

        foreach ($request->headers->all() as $name => $values) {
            $key = strtolower((string) $name);
            $value = is_array($values) ? implode(', ', array_map('strval', $values)) : (string) $values;
            $out[$key] = isset($redactSet[$key]) ? '[redacted]' : tiny_text($value);
        }

        return $out;
    }

    /**
     * @param  list<string>  $redactFields
     * @return array<string, mixed>
     */
    private static function redactedPayload(IlluminateRequest $request, array $redactFields): array
    {
        $payload = $request->all();
        $set = array_flip(array_map('strval', $redactFields));

        return self::redactRecursive($payload, $set);
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<string, int>  $redactSet
     * @return array<mixed>
     */
    private static function redactRecursive(array $value, array $redactSet): array
    {
        foreach ($value as $key => $inner) {
            if (is_string($key) && isset($redactSet[$key])) {
                $value[$key] = '[redacted]';
                continue;
            }
            if (is_array($inner)) {
                $value[$key] = self::redactRecursive($inner, $redactSet);
                continue;
            }
            if (is_string($inner)) {
                $value[$key] = text($inner);
            }
        }

        return $value;
    }

    private static function statusBucket(int $status): int
    {
        return intdiv($status, 100) * 100;
    }
}
