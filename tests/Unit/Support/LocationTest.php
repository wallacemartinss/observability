<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Support;

use Kronn\Observability\Support\Location;
use PHPUnit\Framework\TestCase;

final class LocationTest extends TestCase
{
    public function test_normalizes_paths_with_known_prefixes(): void
    {
        $location = new Location('/srv/app', '/srv/app/public', '/srv/app/vendor');

        self::assertSame('{base}/app/Models/User.php', $location->normalize('/srv/app/app/Models/User.php'));
        self::assertSame('{vendor}/laravel/framework/foo.php', $location->normalize('/srv/app/vendor/laravel/framework/foo.php'));
        self::assertSame('{public}/index.php', $location->normalize('/srv/app/public/index.php'));
    }

    public function test_unknown_path_is_returned_verbatim(): void
    {
        $location = new Location('/srv/app');

        self::assertSame('/usr/share/php/Something.php', $location->normalize('/usr/share/php/Something.php'));
    }

    public function test_empty_path_returns_empty(): void
    {
        self::assertSame('', (new Location('/srv/app'))->normalize(''));
    }

    public function test_is_vendor_and_is_app_code(): void
    {
        $location = new Location('/srv/app', '/srv/app/public', '/srv/app/vendor');

        self::assertTrue($location->isVendor('/srv/app/vendor/foo/bar.php'));
        self::assertFalse($location->isVendor('/srv/app/app/Models/User.php'));

        self::assertTrue($location->isAppCode('/srv/app/app/Models/User.php'));
        self::assertFalse($location->isAppCode('/srv/app/vendor/foo/bar.php'));
        self::assertFalse($location->isAppCode('/elsewhere/Foo.php'));
    }

    public function test_trailing_separator_is_handled(): void
    {
        // Path passed without trailing slash; class should still match.
        $location = new Location('/srv/app');

        self::assertSame('{base}/file.php', $location->normalize('/srv/app/file.php'));
    }
}
