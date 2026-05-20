<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Kronn\Observability\Core;
use Kronn\Observability\Phase;
use Kronn\Observability\Records\RecordType;
use Kronn\Observability\State\HttpState;
use Kronn\Observability\Support\LaravelFeatures;

/**
 * Fires on every Laravel version. We emit the Request record here.
 * Final delivery to the transport (digest) happens at Terminating when
 * available, or via a PHP shutdown hook on older versions.
 */
class RequestHandledListener
{
    public function __construct(
        private readonly Core $core,
        private readonly LaravelFeatures $features,
    ) {
    }

    public function __invoke(RequestHandled $event): void
    {
        $this->core->sensors->transitionPhase(Phase::Send);

        if (! $this->core->state instanceof HttpState) {
            return;
        }

        $state = $this->core->state;
        $request = $event->request;
        $response = $event->response;

        $this->core->record(
            RecordType::Request,
            fn () => $this->core->sensors->request($request, $response),
        );

        if (! $this->features->hasTerminatingEvent) {
            register_shutdown_function(fn () => $this->core->digest());
        }
    }
}
