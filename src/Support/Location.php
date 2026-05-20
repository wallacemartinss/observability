<?php

declare(strict_types=1);

namespace Kronn\Observability\Support;

/**
 * Normalizes filesystem paths into portable names. Absolute prefixes get
 * replaced with short tokens — {base}, {public}, {vendor} — so that
 * stack traces can be grouped across machines (dev / staging / prod).
 */
class Location
{
    private string $base;
    private string $vendor;
    private string $public;

    public function __construct(string $basePath, ?string $publicPath = null, ?string $vendorPath = null)
    {
        $this->base = $this->trail($basePath);
        $this->public = $this->trail($publicPath ?? $basePath . DIRECTORY_SEPARATOR . 'public');
        $this->vendor = $this->trail($vendorPath ?? $basePath . DIRECTORY_SEPARATOR . 'vendor');
    }

    public function normalize(string $path): string
    {
        if ($path === '') {
            return '';
        }

        // Order matters: vendor and public are sub-paths of base.
        foreach (['{vendor}' => $this->vendor, '{public}' => $this->public, '{base}' => $this->base] as $token => $prefix) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return $token . '/' . substr($path, strlen($prefix));
            }
        }

        return $path;
    }

    public function isVendor(string $path): bool
    {
        return $this->vendor !== '' && str_starts_with($path, $this->vendor);
    }

    public function isAppCode(string $path): bool
    {
        return $this->base !== '' && str_starts_with($path, $this->base) && ! $this->isVendor($path);
    }

    private function trail(string $path): string
    {
        return rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
}
