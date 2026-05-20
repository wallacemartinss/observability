<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;

class QueryExecutedListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(QueryExecuted $event): void
    {
        $this->core->record(
            RecordType::Query,
            fn () => $this->core->sensors->query($event),
        );
    }
}
