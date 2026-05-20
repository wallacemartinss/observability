<?php

declare(strict_types=1);

namespace Kronn\Observability\Sensors;

use Illuminate\Http\Request;
use Kronn\Observability\Records\Request as RequestRecord;
use Kronn\Observability\State\HttpState;
use Kronn\Observability\Support\Clock;
use Symfony\Component\HttpFoundation\Response;

class RequestSensor
{
    /**
     * @param  list<string>  $redactPayloadFields
     * @param  list<string>  $redactHeaders
     */
    public function __construct(
        private readonly HttpState $state,
        private readonly Clock $clock,
        private readonly bool $capturePayload,
        private readonly array $redactPayloadFields,
        private readonly array $redactHeaders,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(Request $request, Response $response): array
    {
        $this->state->method = $request->method();
        $this->state->path = '/' . ltrim($request->path(), '/');
        $this->state->statusCode = $response->getStatusCode();

        return RequestRecord::make(
            state: $this->state,
            request: $request,
            response: $response,
            clock: $this->clock,
            capturePayload: $this->capturePayload,
            redactPayloadFields: $this->redactPayloadFields,
            redactHeaders: $this->redactHeaders,
        );
    }
}
