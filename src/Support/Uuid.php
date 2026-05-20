<?php

declare(strict_types=1);

namespace Kronn\Observability\Support;

use Ramsey\Uuid\Uuid as RamseyUuid;

class Uuid
{
    /** @var (callable(): string) */
    private $generator;

    public function __construct(?callable $generator = null)
    {
        $this->generator = $generator ?? static fn (): string => RamseyUuid::uuid4()->toString();
    }

    public function make(): string
    {
        return ($this->generator)();
    }

    public function shortHash(string $value, int $length = 8): string
    {
        return substr(hash('xxh128', $value), 0, $length);
    }
}
