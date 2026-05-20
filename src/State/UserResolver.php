<?php

declare(strict_types=1);

namespace Kronn\Observability\State;

use Throwable;

/**
 * Resolves the authenticated user once per execution. The callback that
 * the application registers (via Telemetry::user(...)) is invoked lazily
 * inside an "ignore block" — any telemetry produced during resolution is
 * dropped to avoid feedback loops.
 */
class UserResolver
{
    /** @var (callable(callable): mixed) */
    private $isolated;

    /** @var (callable(): callable|null) */
    private $resolverProvider;

    /** @var (callable(Throwable): void) */
    private $errorReporter;

    private ?array $resolved = null;
    private bool $hasResolved = false;

    public function __construct(
        callable $isolatedRunner,
        callable $resolverProvider,
        callable $errorReporter,
    ) {
        $this->isolated = $isolatedRunner;
        $this->resolverProvider = $resolverProvider;
        $this->errorReporter = $errorReporter;
    }

    public function resolvedId(): string
    {
        $details = $this->details();

        return isset($details['id']) ? (string) $details['id'] : '';
    }

    /**
     * @return array{id?: mixed, name?: mixed, username?: mixed, email?: mixed}|null
     */
    public function details(): ?array
    {
        if ($this->hasResolved) {
            return $this->resolved;
        }

        $this->hasResolved = true;

        $resolver = ($this->resolverProvider)();
        if (! is_callable($resolver)) {
            return $this->resolved = null;
        }

        try {
            $this->resolved = ($this->isolated)(static function ($auth) use ($resolver) {
                $user = method_exists($auth, 'user') ? $auth->user() : null;
                if ($user === null) {
                    return null;
                }

                $details = $resolver($user);

                return is_array($details) ? $details : null;
            });
        } catch (Throwable $e) {
            ($this->errorReporter)($e);
            $this->resolved = null;
        }

        return $this->resolved;
    }

    public function reset(): void
    {
        $this->hasResolved = false;
        $this->resolved = null;
    }
}
