<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Support;

use Kronn\Observability\Support\Version;
use PHPUnit\Framework\TestCase;

final class VersionTest extends TestCase
{
    public function test_package_constant_matches_repository_name(): void
    {
        self::assertSame('kronn-observability', Version::PACKAGE);
    }

    public function test_version_constant_is_semver_shape(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[a-z0-9.]+)?$/', Version::VERSION);
    }

    public function test_user_agent_includes_package_version_and_php_version(): void
    {
        $ua = Version::userAgent();

        self::assertStringContainsString('kronn-observability/', $ua);
        self::assertStringContainsString(Version::VERSION, $ua);
        self::assertStringContainsString('php/' . PHP_VERSION, $ua);
    }
}
