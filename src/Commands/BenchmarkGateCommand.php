<?php

declare(strict_types=1);

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Support\BenchmarkGate;

class BenchmarkGateCommand extends Command
{
    protected $signature = 'slice:benchmark-gate {spec : Path to the benchmark spec YAML (written from the source system)} {--evidence= : Walkthrough captures directory (default: <spec dir>/evidence)} {--heart= : Slice Heart (slice.yaml) for the behaviour column} {--threshold= : Override the pass threshold} {--format= : Output format: text (default) or json}';

    protected $description = 'Benchmark gate: grade a port surface against a source-written spec — surface columns from walkthrough DOM captures, behaviour column from Heart declarations. Exits 1 on FAIL or NOT-CHECKED evidence.';

    public function handle(): int
    {
        $specPath = (string) $this->argument('spec');
        try {
            $spec = BenchmarkGate::loadSpec($specPath);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if ($this->option('threshold') !== null && $this->option('threshold') !== '') {
            $spec['threshold'] = (float) $this->option('threshold');
        }
        $evidence = (string) ($this->option('evidence') ?: dirname($specPath).'/evidence');
        $heart = $this->option('heart') ? (string) $this->option('heart') : null;

        $result = BenchmarkGate::score($spec, $evidence, $heart);

        if ($this->option('format') === 'json') {
            $this->line((string) json_encode(['command' => 'slice:benchmark-gate'] + $result, JSON_PRETTY_PRINT));
        } else {
            $this->info("Benchmark gate — {$result['slice']} (threshold {$result['threshold']})");
            foreach ($result['columns'] as $name => $column) {
                if (! empty($column['not_checked'])) {
                    $this->warn("  {$name}: NOT CHECKED");

                    continue;
                }
                $widths = isset($column['widths']) && $column['widths'] !== [] ? ' @ '.implode('/', $column['widths']) : '';
                $this->line("  {$name}: {$column['score']}%{$widths}");
                foreach ($column['elements'] as $el) {
                    if (! $el['pass']) {
                        $this->line("    ✗ {$el['label']}");
                    }
                }
            }
            foreach ($result['not_checked'] as $item) {
                $this->warn("  NOT CHECKED: {$item}");
            }
            $this->line("  OVERALL: {$result['overall']}% — {$result['verdict']}");
            $this->line('  Note: the behaviour column grades declaration presence in the Heart; live behaviour proofs remain the gold standard.');
        }

        return $result['verdict'] === 'PASS' ? self::SUCCESS : self::FAILURE;
    }
}
