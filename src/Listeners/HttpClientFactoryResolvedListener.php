<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Http\Client\Factory;

/**
 * Installs the Guzzle middleware globally — every Http::client() call
 * becomes instrumented.
 *
 * Uses globalMiddleware() (classic handler-stack shape) rather than the
 * request/response split: globalResponseMiddleware only ever hands the
 * callback a response, so request/response pairing is impossible there.
 * The classic middleware keeps both in one closure.
 */
class HttpClientFactoryResolvedListener
{
    public function __construct(private readonly GuzzleMiddleware $middleware)
    {
    }

    public function __invoke(Factory $factory): void
    {
        $factory->globalMiddleware($this->middleware->asHandler());
    }
}
