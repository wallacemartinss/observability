<?php

declare(strict_types=1);

namespace Kronn\Observability\Facades;

use Illuminate\Support\Facades\Facade;
use Kronn\Observability\Core;

/**
 * Fachada pública da API de telemetria.
 *
 * @method static void user(callable $resolver)
 * @method static void tag(string|array $tag, mixed $value = true)
 * @method static void extra(array $extras)
 * @method static void report(\Throwable $throwable, bool $handled = true)
 * @method static mixed ignore(callable $callback)
 * @method static string trace()
 * @method static string executionId()
 * @method static void digest()
 *
 * @see \Kronn\Observability\Core
 */
class Telemetry extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Core::class;
    }
}
