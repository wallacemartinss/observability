<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Transports;

use Kronn\Observability\Transports\NullTransport;
use PHPUnit\Framework\TestCase;

final class NullTransportTest extends TestCase
{
    public function test_ship_is_a_noop_and_does_not_throw(): void
    {
        $transport = new NullTransport();
        $transport->ship([['anything' => 1], ['else' => 2]]);

        $this->expectNotToPerformAssertions();
    }

    public function test_probe_returns_true(): void
    {
        self::assertTrue((new NullTransport())->probe());
    }
}
