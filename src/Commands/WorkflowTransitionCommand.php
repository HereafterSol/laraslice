<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

class WorkflowTransitionCommand extends Command
{
    protected $signature = 'workflow:transition 
                            {slice : Name of slice or entity model (e.g. Invoices, Tickets)}
                            {id : Primary key of the entity record}
                            {transition : Slug of the transition to apply (e.g. approve, reject, forward)}
                            {--remarks= : Official remarks or explanation for the transition}
                            {--role= : Optional target role forwarding override}
                            {--department= : Optional target department forwarding override}';

    protected $description = 'Execute a workflow state transition on an entity via CLI';

    public function handle(SliceManager $manager, WorkflowEngineService $engine): int
    {
        $slice = $this->argument('slice');
        $id = $this->argument('id');
        $transition = $this->argument('transition');
        $remarks = $this->option('remarks') ?: 'CLI automated transition';

        $model = $this->resolveModel($manager, $slice, $id);
        if (! $model) {
            $this->error("Could not find record #{$id} for entity {$slice}");
            return 1;
        }

        $this->info("Current State: [{$model->status}] for {$slice} #{$id}");

        $payload = [
            'remarks' => $remarks,
            'assigned_to_role' => $this->option('role'),
            'assigned_to_department' => $this->option('department'),
        ];

        try {
            $log = $engine->applyTransition($model, $transition, $payload);
            $this->info("✓ Successfully applied transition '{$transition}'!");
            $this->table(
                ['Attribute', 'Value'],
                [
                    ['Entity', get_class($model).' #'.$id],
                    ['Previous State', $log->from_state],
                    ['New State', $log->to_state],
                    ['Transition', $log->transition_slug],
                    ['Assigned Role', $log->assigned_to_role ?: '-'],
                    ['Assigned Dept', $log->assigned_to_department ?: '-'],
                    ['Remarks', $log->remarks ?: '-'],
                    ['Timestamp', $log->created_at->toDateTimeString()],
                ]
            );

            return 0;
        } catch (\Throwable $e) {
            $this->error("Transition failed: ".$e->getMessage());
            return 1;
        }
    }

    protected function resolveModel(SliceManager $manager, string $slice, $id)
    {
        $manifest = $manager->getSlice($slice) ?: $manager->getSlice(Str::studly($slice));
        if ($manifest && method_exists($manifest, 'getModelClass')) {
            $modelClass = $manifest->getModelClass();
            if ($modelClass && class_exists($modelClass)) {
                return $modelClass::find($id);
            }
        }

        $studlySlice = Str::studly($slice);
        $singularStudly = Str::studly(Str::singular($slice));

        $candidates = [
            "App\\Slices\\Billing\\{$studlySlice}\\Models\\{$singularStudly}",
            "App\\Slices\\Billing\\{$slice}\\Models\\".Str::singular($slice),
            "App\\Slices\\Support\\{$studlySlice}\\Models\\{$singularStudly}",
            "App\\Slices\\Legal\\{$studlySlice}\\Models\\{$singularStudly}",
            "App\\Models\\{$singularStudly}",
            "App\\Models\\{$studlySlice}",
        ];

        foreach ($candidates as $cls) {
            if (class_exists($cls)) {
                return $cls::find($id);
            }
        }

        return null;
    }

    protected function resolveModelLegacy(SliceManager $manager, string $slice, $id)
    {
        $manifest = $manager->getSlice($slice) ?: $manager->getSlice(Str::studly($slice));
        if ($manifest) {
            $modelClass = $manifest->getModelClass();
            if ($modelClass && class_exists($modelClass)) {
                return $modelClass::find($id);
            }
        }

        $candidates = [
            "App\\Slices\\Billing\\{$slice}\\Models\\".Str::singular($slice),
            "App\\Slices\\Support\\{$slice}\\Models\\".Str::singular($slice),
            "App\\Slices\\Legal\\{$slice}\\Models\\".Str::singular($slice),
            "App\\Models\\".Str::singular($slice),
            "App\\Models\\".Str::studly($slice),
        ];

        foreach ($candidates as $cls) {
            if (class_exists($cls)) {
                return $cls::find($id);
            }
        }

        return null;
    }
}