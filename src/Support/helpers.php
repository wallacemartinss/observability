<?php

declare(strict_types=1);

namespace Kronn\Observability\Support;

if (! function_exists(__NAMESPACE__ . '\\truncate_utf8')) {
    /**
     * Truncate a string to at most $maxBytes bytes while preserving UTF-8.
     */
    function truncate_utf8(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0 || strlen($value) <= $maxBytes) {
            return $value;
        }

        $truncated = substr($value, 0, $maxBytes);

        // Strip stray invalid bytes at the tail (a UTF-8 sequence cut in half).
        return mb_convert_encoding($truncated, 'UTF-8', 'UTF-8');
    }
}

if (! function_exists(__NAMESPACE__ . '\\tiny_text')) {
    function tiny_text(?string $value): string
    {
        return truncate_utf8((string) $value, 255);
    }
}

if (! function_exists(__NAMESPACE__ . '\\text')) {
    function text(?string $value): string
    {
        return truncate_utf8((string) $value, 65_535);
    }
}

if (! function_exists(__NAMESPACE__ . '\\long_text')) {
    function long_text(?string $value): string
    {
        return truncate_utf8((string) $value, 1_048_576);
    }
}
