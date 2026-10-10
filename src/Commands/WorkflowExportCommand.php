<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Slices\Workflows\Models\Workflow;

class WorkflowExportCommand extends Command
{
    protected $signature = 'workflow:export {slug : The workflow slug (e.g. invoices, support_tickets)} {--format=mermaid : Output format (mermaid, plantuml, json)}';

    protected $description = 'Export visual pipeline diagram (Mermaid / PlantUML) for a workflow';

    public function handle(): int
    {
        $slug = $this->argument('slug');
        $format = strtolower($this->option('format'));

        $workflow = Workflow::where('slug', $slug)->with(['states', 'transitions'])->first();
        if (! $workflow) {
            $this->error("Workflow not found with slug: {$slug}");
            return 1;
        }

        if ($format === 'json') {
            $this->line(json_encode($workflow->toArray(), JSON_PRETTY_PRINT));
            return 0;
        }

        if ($format === 'mermaid') {
            $this->line("```mermaid");
            $this->line("stateDiagram-v2");
            foreach ($workflow->states as $state) {
                if ($state->is_initial) {
                    $this->line("    [*] --> {$state->slug}");
                }
                if ($state->is_terminal) {
                    $this->line("    {$state->slug} --> [*]");
                }
            }

            foreach ($workflow->transitions as $trans) {
                $froms = array_map('trim', explode(',', $trans->from_state_slug));
                foreach ($froms as $from) {
                    $fromName = $from === '*' ? '[*]' : $from;
                    $this->line("    {$fromName} --> {$trans->to_state_slug} : {$trans->label}");
                }
            }
            $this->line("```");
            return 0;
        }

        $this->error("Unsupported format: {$format}. Choose mermaid or json.");
        return 1;
    }
}