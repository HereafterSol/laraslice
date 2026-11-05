<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Core\Routing\RouteTruthIndex;

/**
 * Emit a JSON route index `{uri, name, slice_id}` for MCP and agent tooling.
 */
final class SliceRouteTruthCommand extends Command
{
    protected $signature = 'slice:route-truth
                            {--path= : Only include routes whose URI starts with this prefix}
                            {--output= : Write JSON to this file instead of stdout}
                            {--pretty : Pretty-print JSON}';

    protected $description = 'Emit JSON route truth index (uri, name, slice_id) for agents and MCP.';

    public function handle(RouteTruthIndex $index): int
    {
        $path = $this->option('path');
        $pathPrefix = is_string($path) && $path !== '' ? $path : null;
        $payload = $index->build($pathPrefix);

        $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($payload, $flags);

        $outputPath = $this->option('output');
        if (is_string($outputPath) && $outputPath !== '') {
            file_put_contents($outputPath, $json.PHP_EOL);

            $this->info("Wrote {$payload['route_count']} route(s) to {$outputPath}");

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
