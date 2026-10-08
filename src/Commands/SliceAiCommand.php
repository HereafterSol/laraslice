<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Generator\FlutterSliceGenerator;
use LaraSlice\Generator\SliceGenerator;

class SliceAiCommand extends Command
{
    protected $signature = 'slice:ai {prompt : Natural language prompt (e.g. "Create an Invoice slice with line items and approval workflow")}
                            {--flutter : Also generate Flutter views}';

    protected $description = 'Scaffold a slice from a short description. Picks the name and workflow by keyword matching; no AI provider is called';

    public function handle(): int
    {
        $prompt = $this->argument('prompt');
        $includeFlutter = (bool) $this->option('flutter');

        $this->info("Reading the description for a slice name and workflow keywords: \"{$prompt}\"");

        // Parse prompt heuristics for slice name and workflow
        $words = preg_split('/\s+/', strtolower($prompt));
        $sliceName = 'Feature';

        foreach (['invoice', 'project', 'ticket', 'task', 'customer', 'lead', 'order', 'booking', 'employee', 'product'] as $keyword) {
            if (str_contains(strtolower($prompt), $keyword)) {
                $sliceName = ucfirst($keyword);
                break;
            }
        }

        $hasWorkflow = str_contains(strtolower($prompt), 'workflow') ||
                       str_contains(strtolower($prompt), 'approval') ||
                       str_contains(strtolower($prompt), 'state');

        $this->line("<fg=cyan>→</> Inferred Slice Name:</> <comment>{$sliceName}</comment>");
        $this->line('<fg=cyan>→</> Workflow Engine:</> <comment>'.($hasWorkflow ? 'Enabled' : 'Disabled').'</comment>');

        $generator = new SliceGenerator;
        $sliceDir = $generator->generate($sliceName, [], $hasWorkflow);

        $this->line("<fg=green>✓</> Scaffolded vertical slice: <comment>{$sliceDir}</comment>");

        if ($includeFlutter) {
            $flutterGen = new FlutterSliceGenerator;
            $flutterDir = $flutterGen->generate($sliceName);
            $this->line("<fg=cyan>✓</> Scaffolded Flutter views: <comment>{$flutterDir}</comment>");
        }

        $this->newLine();
        $this->info("✨ Slice [{$sliceName}] created. Add fields with slice:field or the studio.");

        return Command::SUCCESS;
    }
}
