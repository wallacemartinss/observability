<?php

declare(strict_types=1);

namespace Kronn\Observability\Listeners;

use Kronn\Observability\Core;

/**
 * Hooks into Livewire's hydration lifecycle. Each subsequent hydration
 * (after the initial page load) is effectively a separate "action"
 * inside the same HTTP request — we tag the state so the resulting
 * Request record can be filtered as Livewire traffic in the backend.
 *
 * Wire-up differs by Livewire version:
 *  - Livewire 2 fires "component.hydrate.subsequent"
 *  - Livewire 3 fires "hydrate"
 *
 * The provider checks `class_exists(Livewire::class)` before binding
 * either of these listeners.
 */
class LivewireListener
{
    public function __construct(private readonly Core $core)
    {
    }

    /** Livewire 3 hook signature: hydrate($component). */
    public function hydrate(mixed $component): void
    {
        $this->markComponent($component);
    }

    /** Livewire 2 hook signature: component.hydrate.subsequent($component, $request). */
    public function componentHydrateSubsequent(mixed $component, mixed $request = null): void
    {
        $this->markComponent($component);
    }

    private function markComponent(mixed $component): void
    {
        if (! is_object($component)) {
            return;
        }

        $this->core->tag([
            'livewire' => true,
            'livewire_component' => $component::class,
        ]);
    }
}
