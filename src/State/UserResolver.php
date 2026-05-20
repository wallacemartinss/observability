<?php

declare(strict_types=1);

namespace Kronn\Observability\State;

use Throwable;

/**
 * Resolves the authenticated user once per execution. The callback that
 * the application registers (via Telemetry::user(...)) is invoked lazily
 * inside an "ignore block" — any telemetry produced during resolution is
 * dropped to avoid feedback loops.
 *
 * If no resolver is registered, falls back to ['id' => $user->getAuthIdentifier()]
 * so records still carry the authenticated user's ID — the Authenticatable
 * contract guarantees this method.
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

        try {
            $this->resolved = ($this->isolated)(static function ($auth) use ($resolver) {
                $user = method_exists($auth, 'user') ? $auth->user() : null;
                if ($user === null) {
                    return null;
                }

                if (is_callable($resolver)) {
                    $details = $resolver($user);
                    return is_array($details) ? $details : null;
                }

                $id = method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null;
                return $id === null ? null : ['id' => $id];
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
