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
 * The user is looked up across every configured auth guard, default guard
 * first — a multi-panel app (e.g. Filament with separate web/admin guards)
 * authenticates on one of several guards, not only the default one.
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

    /** @var list<string> */
    private array $guards;

    private ?array $resolved = null;
    private bool $hasResolved = false;

    /**
     * @param  list<string>  $guards  Auth guard names to probe, default guard first.
     */
    public function __construct(
        callable $isolatedRunner,
        callable $resolverProvider,
        callable $errorReporter,
        array $guards = [],
    ) {
        $this->isolated = $isolatedRunner;
        $this->resolverProvider = $resolverProvider;
        $this->errorReporter = $errorReporter;
        $this->guards = $guards;
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
        $guards = $this->guards;

        try {
            $this->resolved = ($this->isolated)(static function ($auth) use ($resolver, $guards) {
                $user = self::firstAuthenticatedUser($auth, $guards);
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

    /**
     * First authenticated user across the given guards (default guard first).
     * Falls back to the auth manager's default-guard user when no guard list
     * is configured. Runs inside the caller's ignore block, so the guard
     * lookups never emit telemetry of their own.
     *
     * @param  list<string>  $guards
     */
    private static function firstAuthenticatedUser(mixed $auth, array $guards): mixed
    {
        if (is_object($auth) && method_exists($auth, 'guard')) {
            foreach ($guards as $guard) {
                $user = $auth->guard($guard)->user();
                if ($user !== null) {
                    return $user;
                }
            }
        }

        return is_object($auth) && method_exists($auth, 'user') ? $auth->user() : null;
    }

    public function reset(): void
    {
        $this->hasResolved = false;
        $this->resolved = null;
    }
}
