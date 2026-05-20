<?php

declare(strict_types=1);

namespace Kronn\Observability\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Placeholder for the future Kronn agent.
 *
 * The agent is the daemon that accepts SocketTransport connections and
 * forwards records to the remote Kronn backend — not implemented yet.
 * This command exists so we can reserve the "observability:agent" name
 * and surface a clear signal that the feature is still pending.
 */
#[AsCommand(name: 'observability:agent', description: '(WIP) Run the local Kronn agent.')]
class AgentCommand extends Command
{
    protected $signature = 'observability:agent
        {--listen= : Listen address (e.g. 127.0.0.1:4317)}
        {--upstream= : URL of the remote Kronn backend}';

    protected $description = '(WIP) Run the local Kronn agent.';

    public function handle(): int
    {
        $this->components->error('The Kronn agent is not implemented yet.');
        $this->newLine();
        $this->line('  <comment>kronn/observability</comment> v0.1 only ships the client SDK.');
        $this->line('  Roadmap (under discussion):');
        $this->line('   1. Ingest contract for the Kronn backend.');
        $this->line('   2. Agent process consuming the kronn-v1 protocol.');
        $this->line('   3. Kronn UI to visualise records.');
        $this->newLine();
        $this->line('  During development, set <comment>KRONN_TRANSPORT=log</comment>');
        $this->line('  to inspect records at <comment>storage/logs/kronn.ndjson</comment>.');

        return self::INVALID;
    }
}
