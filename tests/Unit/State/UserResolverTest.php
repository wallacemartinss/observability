<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\State;

use Kronn\Observability\State\UserResolver;
use PHPUnit\Framework\TestCase;
use Throwable;

final class UserResolverTest extends TestCase
{
    public function test_resolves_user_from_a_non_default_guard(): void
    {
        $auth = $this->fakeAuth(['web' => null, 'admin' => $this->fakeUser(42)]);

        $resolver = new UserResolver(
            isolatedRunner: fn (callable $cb) => $cb($auth),
            resolverProvider: fn () => null,
            errorReporter: fn (Throwable $e) => null,
            guards: ['web', 'admin'],
        );

        self::assertSame(['id' => 42], $resolver->details());
        self::assertSame('42', $resolver->resolvedId());
    }

    public function test_default_guard_wins_when_several_guards_have_a_user(): void
    {
        $auth = $this->fakeAuth(['web' => $this->fakeUser(1), 'admin' => $this->fakeUser(2)]);

        $resolver = new UserResolver(
            isolatedRunner: fn (callable $cb) => $cb($auth),
            resolverProvider: fn () => null,
            errorReporter: fn (Throwable $e) => null,
            guards: ['web', 'admin'],
        );

        self::assertSame(['id' => 1], $resolver->details());
    }

    public function test_resolves_to_null_when_no_guard_has_a_user(): void
    {
        $auth = $this->fakeAuth(['web' => null, 'admin' => null]);

        $resolver = new UserResolver(
            isolatedRunner: fn (callable $cb) => $cb($auth),
            resolverProvider: fn () => null,
            errorReporter: fn (Throwable $e) => null,
            guards: ['web', 'admin'],
        );

        self::assertNull($resolver->details());
        self::assertSame('', $resolver->resolvedId());
    }

    public function test_custom_resolver_enriches_the_resolved_user(): void
    {
        $auth = $this->fakeAuth(['web' => null, 'admin' => $this->fakeUser(7)]);

        $resolver = new UserResolver(
            isolatedRunner: fn (callable $cb) => $cb($auth),
            resolverProvider: fn () => fn (object $user): array => [
                'id' => $user->getAuthIdentifier(),
                'name' => 'Ada Lovelace',
            ],
            errorReporter: fn (Throwable $e) => null,
            guards: ['web', 'admin'],
        );

        self::assertSame(['id' => 7, 'name' => 'Ada Lovelace'], $resolver->details());
    }

    public function test_falls_back_to_default_guard_user_when_no_guards_listed(): void
    {
        $auth = $this->fakeAuth([], defaultUser: $this->fakeUser(99));

        $resolver = new UserResolver(
            isolatedRunner: fn (callable $cb) => $cb($auth),
            resolverProvider: fn () => null,
            errorReporter: fn (Throwable $e) => null,
            guards: [],
        );

        self::assertSame(['id' => 99], $resolver->details());
    }

    public function test_resolution_is_memoized_until_reset(): void
    {
        $runs = 0;
        $auth = $this->fakeAuth(['web' => $this->fakeUser(1)]);

        $resolver = new UserResolver(
            isolatedRunner: function (callable $cb) use ($auth, &$runs) {
                $runs++;
                return $cb($auth);
            },
            resolverProvider: fn () => null,
            errorReporter: fn (Throwable $e) => null,
            guards: ['web'],
        );

        $resolver->details();
        $resolver->details();
        self::assertSame(1, $runs, 'resolution must run only once');

        $resolver->reset();
        $resolver->details();
        self::assertSame(2, $runs, 'reset must allow re-resolution');
    }

    private function fakeUser(int|string $id): object
    {
        return new class($id)
        {
            public function __construct(private readonly int|string $id) {}

            public function getAuthIdentifier(): int|string
            {
                return $this->id;
            }
        };
    }

    /**
     * @param  array<string, object|null>  $usersByGuard
     */
    private function fakeAuth(array $usersByGuard, ?object $defaultUser = null): object
    {
        return new class($usersByGuard, $defaultUser)
        {
            /**
             * @param  array<string, object|null>  $usersByGuard
             */
            public function __construct(
                private readonly array $usersByGuard,
                private readonly ?object $defaultUser,
            ) {}

            public function guard(string $name): object
            {
                $user = $this->usersByGuard[$name] ?? null;

                return new class($user)
                {
                    public function __construct(private readonly ?object $user) {}

                    public function user(): ?object
                    {
                        return $this->user;
                    }
                };
            }

            public function user(): ?object
            {
                return $this->defaultUser;
            }
        };
    }
}
