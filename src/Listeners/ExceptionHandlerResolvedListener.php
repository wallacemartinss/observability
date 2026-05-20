<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;
use Throwable;

/**
 * Fires when the framework's ExceptionHandler is resolved out of the
 * container. We use the `reportable` macro (available on every Laravel
 * 10-13 release) to register a hook that sees every exception reported
 * by the application.
 *
 * We return `false` so the native handler keeps running — we observe,
 * we don't replace the framework's reporting behavior.
 */
class ExceptionHandlerResolvedListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(ExceptionHandler $handler): void
    {
        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $core = $this->core;

        $handler->reportable(static function (Throwable $throwable) use ($core): bool {
            if (! $core->wants(RecordType::Exception)) {
                return false;
            }

            $core->record(
                RecordType::Exception,
                static fn () => $core->sensors->exception($throwable, handled: true),
            );

            return false; // do not interrupt native reporting
        });
    }
}
