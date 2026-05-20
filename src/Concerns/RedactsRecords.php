<?php

declare(strict_types=1);

namespace Kronn\Observability\Concerns;

/**
 * Source of truth for redaction rules. Records receive the lists as
 * parameters; the trait also exposes them for ad-hoc lookups (e.g.
 * inside middleware).
 */
trait RedactsRecords
{
    /** @var list<string> */
    public array $redactPayloadFields = [];

    /** @var list<string> */
    public array $redactHeaders = [];

    public bool $capturePayload = false;

    public bool $captureExceptionSource = true;

    public function shouldRedactPayloadKey(string $key): bool
    {
        return in_array($key, $this->redactPayloadFields, true);
    }

    public function shouldRedactHeader(string $name): bool
    {
        $normalized = strtolower($name);
        foreach ($this->redactHeaders as $header) {
            if (strtolower($header) === $normalized) {
                return true;
            }
        }

        return false;
    }
}
