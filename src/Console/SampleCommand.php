<?php

declare(strict_types=1);

namespace Kronn\Observability\Console;

use Illuminate\Console\Command;
use Kronn\Observability\Core;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Emits one synthetic record per type. Used to verify the configured
 * transport can reach its destination (log file or remote agent).
 */
#[AsCommand(name: 'observability:sample', description: 'Emit synthetic records to validate the transport.')]
class SampleCommand extends Command
{
    protected $signature = 'observability:sample {--count=1 : How many records of each type to emit}';

    protected $description = 'Emit synthetic records to validate the transport.';

    public function handle(Core $core): int
    {
        $count = max(1, (int) $this->option('count'));

        $this->components->info("Emitting {$count} records of each type...");

        for ($i = 0; $i < $count; $i++) {
            $core->report(new RuntimeException("sample exception #{$i}"), handled: true);
            $core->tag('sample', true);
            $core->extra(['sample_index' => $i]);
        }

        $core->digest();
        $this->components->success('Records emitted. Check the destination of the configured transport.');

        return self::SUCCESS;
    }
}
