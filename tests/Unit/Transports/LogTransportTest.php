<?php

declare(strict_types=1);

namespace Kronn\Observability\Tests\Unit\Transports;

use Kronn\Observability\Transports\LogTransport;
use PHPUnit\Framework\TestCase;

final class LogTransportTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/kronn-obs-test-' . bin2hex(random_bytes(4)) . '/log.ndjson';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        @rmdir(dirname($this->path));
    }

    public function test_ship_writes_one_json_line_per_record(): void
    {
        $transport = new LogTransport($this->path);

        $transport->ship([
            ['kronn' => ['type' => 'request'], 'n' => 1],
            ['kronn' => ['type' => 'query'], 'n' => 2],
        ]);

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);
        self::assertCount(2, $lines);
        self::assertSame(['kronn' => ['type' => 'request'], 'n' => 1], json_decode($lines[0], true));
        self::assertSame(['kronn' => ['type' => 'query'], 'n' => 2], json_decode($lines[1], true));
    }

    public function test_ship_creates_missing_directory(): void
    {
        self::assertFalse(is_dir(dirname($this->path)));

        (new LogTransport($this->path))->ship([['ok' => true]]);

        self::assertTrue(is_file($this->path));
    }

    public function test_ship_with_empty_batch_is_noop(): void
    {
        (new LogTransport($this->path))->ship([]);

        self::assertFalse(is_file($this->path));
    }

    public function test_probe_succeeds_when_directory_is_writable(): void
    {
        self::assertTrue((new LogTransport($this->path))->probe());
    }
}
