<?php

declare(strict_types=1);

namespace Kronn\Observability\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Kronn\Observability\Core;
use Kronn\Observability\Support\LaravelFeatures;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'observability:status', description: 'Show the Kronn telemetry state and run transport probes.')]
class StatusCommand extends Command
{
    protected $signature = 'observability:status';

    protected $description = 'Show the Kronn telemetry state and run transport probes.';

    public function handle(Core $core, Repository $config, LaravelFeatures $features): int
    {
        $driver = (string) $config->get('observability.transport', 'log');
        $enabled = (bool) $config->get('observability.enabled', false);

        $this->components->twoColumnDetail('Enabled', $enabled ? '<info>yes</info>' : '<comment>no</comment>');
        $this->components->twoColumnDetail('Environment', (string) $config->get('observability.environment'));
        $this->components->twoColumnDetail('Server', (string) $config->get('observability.server'));
        $this->components->twoColumnDetail('Deployment', (string) ($config->get('observability.deployment') ?? '-'));
        $this->components->twoColumnDetail('API key', $config->get('observability.api_key') !== null ? '<info>configured</info>' : '<comment>not configured</comment>');
        $this->components->twoColumnDetail('Transport', $driver);
        $this->components->twoColumnDetail('Buffer capacity', (string) $config->get('observability.buffer.capacity'));
        $this->components->twoColumnDetail('Laravel', $features->laravelVersion);
        $this->components->twoColumnDetail('PHP', $features->phpVersion);

        $this->newLine();
        $this->components->info('Running transport probe...');
        $ok = $core->transport->probe();
        $this->components->twoColumnDetail(
            'Probe',
            $ok ? '<info>OK</info>' : '<error>FAILED</error>',
        );

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
