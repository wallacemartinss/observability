<?php

declare(strict_types=1);

namespace Kronn\Observability\Support;

/**
 * Single source of truth for the package name and version. Used to build
 * the User-Agent header sent by the HTTP transport. Keep VERSION in sync
 * with composer.json + version.txt + git tags.
 */
final class Version
{
    public const PACKAGE = 'kronn-observability';

    public const VERSION = '0.4.0';

    public static function userAgent(): string
    {
        return sprintf('%s/%s php/%s', self::PACKAGE, self::VERSION, PHP_VERSION);
    }
}
