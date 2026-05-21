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
 * emits an OutgoingRequest record.
 *
 * Installed as classic handler-stack middleware (callable($handler)):
 * request and response live in the same closure, so they pair up
 * naturally without spl_object_hash bookkeeping.
 */
class GuzzleMiddleware
{
    public function __construct(private readonly Core $core)
    {
    }

    /**
     * Classic middleware shape, for handler stacks and Factory::globalMiddleware.
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
