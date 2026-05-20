<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use GuzzleHttp\Promise\Create as PromiseCreate;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Guzzle middleware that intercepts every request/response pair and
 * emits an OutgoingRequest record. Two usage modes:
 *
 *  - classic middleware (callable($handler))
 *  - global request/response middleware via Factory
 *
 * Pairing of request and response happens through spl_object_hash;
 * WeakMap would not work because PSR HTTP messages are immutable and
 * the response object differs from the request object.
 */
class GuzzleMiddleware
{
    /** @var array<string, float> spl_object_hash -> start microtime */
    private array $starts = [];

    public function __construct(private readonly Core $core)
    {
    }

    /**
     * Hook for globalRequestMiddleware — records the start timestamp.
     */
    public function onRequest(RequestInterface $request): RequestInterface
    {
        $this->starts[spl_object_hash($request)] = $this->core->clock->microtime();
        return $request;
    }

    /**
     * Hook for globalResponseMiddleware — emits the record using the
     * start timestamp captured earlier. Falls back to current microtime
     * (zero duration) if the start was never recorded.
     */
    public function onResponse(ResponseInterface $response, RequestInterface $request): ResponseInterface
    {
        $end = $this->core->clock->microtime();
        $hash = spl_object_hash($request);
        $start = $this->starts[$hash] ?? $end;
        unset($this->starts[$hash]);

        $this->core->record(
            RecordType::OutgoingRequest,
            fn () => $this->core->sensors->outgoingRequest($start, $end, $request, $response),
        );

        return $response;
    }

    /**
     * Classic middleware shape, for manual handler stacks.
     *
     * @return callable(callable): callable
     */
    public function asHandler(): callable
    {
        $self = $this;

        return static function (callable $handler) use ($self): callable {
            return static function (RequestInterface $request, array $options) use ($self, $handler) {
                $start = $self->core->clock->microtime();

                return $handler($request, $options)->then(
                    static function (ResponseInterface $response) use ($self, $request, $start): ResponseInterface {
                        $end = $self->core->clock->microtime();
                        $self->core->record(
                            RecordType::OutgoingRequest,
                            fn () => $self->core->sensors->outgoingRequest($start, $end, $request, $response),
                        );
                        return $response;
                    },
                    static function (Throwable $reason) {
                        return PromiseCreate::rejectionFor($reason);
                    },
                );
            };
        };
    }
}
