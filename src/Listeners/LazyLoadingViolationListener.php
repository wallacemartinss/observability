<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Illuminate\Database\Eloquent\Model;
use Kronn\Observability\Core;
use Kronn\Observability\Records\RecordType;

/**
 * Receives Eloquent lazy-loading violations and emits a kronn-v1
 * "lazy_load" record naming the model + relation that should have
 * been eager loaded.
 *
 * Registered via Model::handleLazyLoadingViolationUsing only when
 * KRONN_DETECT_LAZY_LOADING is on. The handler is non-throwing: it
 * replaces Laravel's default LazyLoadingViolationException, so the
 * app keeps lazy-loading normally — we only observe.
 */
class LazyLoadingViolationListener
{
    public function __construct(private readonly Core $core)
    {
    }

    public function __invoke(Model $model, string $relation): void
    {
        $this->core->record(
            RecordType::LazyLoad,
            fn () => $this->core->sensors->lazyLoad($model::class, $relation),
        );
    }
}
