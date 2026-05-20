<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Http\Client\Factory;

/**
 * Installs the Guzzle middleware globally — every Http::client() call
 * becomes instrumented.
 */
class HttpClientFactoryResolvedListener
{
    public function __construct(private readonly GuzzleMiddleware $middleware)
    {
    }

    public function __invoke(Factory $factory): void
    {
        $middleware = $this->middleware;
        $factory->globalRequestMiddleware(static fn ($request) => $middleware->onRequest($request));
        $factory->globalResponseMiddleware(static fn ($response, $request = null) => $middleware->onResponse($response, $request));
    }
}
