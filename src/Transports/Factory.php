<?php

declare(strict_types=1);

namespace Kronn\Observability\Transports;

use InvalidArgumentException;
use Kronn\Observability\Contracts\Transport;
use Kronn\Observability\Support\Uuid;

/**
 * Resolves a transport driver from configuration. Drivers are looked up
 * by the "driver" key of config('observability.drivers.<name>').
 *
 * To register custom drivers, resolve the Factory instance and call
 * extend().
 */
class Factory
{
    /** @var array<string, callable(array<string, mixed>, string): Transport> */
    private array $factories = [];

    public function __construct(private readonly Uuid $uuid)
    {
        $this->registerDefaults();
    }

    /**
     * @param  callable(array<string, mixed>, string): Transport  $factory
     */
    public function extend(string $name, callable $factory): self
    {
        $this->factories[$name] = $factory;
        return $this;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function make(string $driver, array $config, string $apiKey = ''): Transport
    {
        if (! isset($this->factories[$driver])) {
            throw new InvalidArgumentException("Unknown transport driver: {$driver}");
        }

        return ($this->factories[$driver])($config, $apiKey);
    }

    private function registerDefaults(): void
    {
        $this->factories['null'] = static fn (): Transport => new NullTransport();

        $this->factories['log'] = static fn (array $config): Transport => new LogTransport(
            path: (string) ($config['path'] ?? sys_get_temp_dir() . '/kronn.ndjson'),
        );

        $uuid = $this->uuid;
        $this->factories['socket'] = static fn (array $config, string $apiKey): Transport => new SocketTransport(
            endpoint: (string) ($config['endpoint'] ?? '127.0.0.1:4317'),
            connectTimeout: (float) ($config['connect_timeout'] ?? 0.5),
            readTimeout: (float) ($config['read_timeout'] ?? 0.5),
            apiKeyHash: $apiKey === '' ? '' : $uuid->shortHash($apiKey, 12),
        );
    }
}
