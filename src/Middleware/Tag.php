<?php

declare(strict_types=1);

namespace Kronn\Observability\Middleware;

use Closure;
use Kronn\Observability\Core;

/**
 * Attaches tags to every record produced by the current execution.
 * Useful to categorize requests by product area.
 *
 *   Route::middleware('observability.tag:area,billing')->group(...);
 *   Route::middleware(['observability.tag:tenant,acme'])->...
 */
class Tag
{
    public function __construct(private readonly Core $core)
    {
    }

    public function handle(mixed $request, Closure $next, string $key, mixed $value = true): mixed
    {
        $this->core->tag($key, $value);

        return $next($request);
    }
}
