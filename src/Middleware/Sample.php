<?php

declare(strict_types=1);

namespace Kronn\Observability\Middleware;

use Closure;
use Kronn\Observability\Core;

/**
 * Overrides the sampling rate for individual routes.
 *
 *   Route::get('/health', ...)->middleware('observability.sample:0.0');
 *   Route::get('/checkout', ...)->middleware('observability.sample:1.0');
 *
 * The rate is interpreted as a probability in [0.0, 1.0]; 0.0 disables
 * collection for the route entirely, 1.0 guarantees it.
 */
class Sample
{
    public function __construct(private readonly Core $core)
    {
    }

    public function handle(mixed $request, Closure $next, string|float $rate = 1.0): mixed
    {
        $this->core->decideSampling((float) $rate);

        return $next($request);
    }
}
