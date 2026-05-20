<?php

declare(strict_types=1);

namespace Kronn\Observability\State;

class HttpState extends ExecutionState
{
    public string $source = 'http';

    public ?string $routeName = null;

    public ?string $routeAction = null;

    public ?string $method = null;

    public ?string $path = null;

    public ?int $statusCode = null;

    public int $bytesIn = 0;

    public int $bytesOut = 0;
}
