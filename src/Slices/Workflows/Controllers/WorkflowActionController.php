<?php

namespace LaraSlice\Slices\Workflows\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

class WorkflowActionController extends Controller
{
    public function transition(Request $request, string $slice, int|string $id)
    {
        $validated = $request->validate([
            'transition' => ['required', 'string'],
            'remarks' => ['nullable', 'string'],
            'assigned_to_role' => ['nullable', 'string'],
            'assigned_to_department' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'max:10240'],
        ]);

        // Resolve model instance
        $model = $this->resolveModelInstance($slice, $id);
        if (! $model) {
            abort(404, "Could not resolve entity for {$slice} #{$id}");
        }

        $engine = app(WorkflowEngineService::class);

        $payload = [
            'remarks' => $validated['remarks'] ?? null,
            'assigned_to_role' => $validated['assigned_to_role'] ?? null,
            'assigned_to_department' => $validated['assigned_to_department'] ?? null,
            'attachments' => [],
        ];

        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('workflow_attachments', 'public');
            $payload['attachments'][] = $path;
        }

        try {
            $log = $engine->applyTransition($model, $validated['transition'], $payload, $request->user());

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Successfully transitioned to {$log->to_state}",
                    'log' => $log,
                ]);
            }

            return back()->with('success', "Workflow transitioned successfully to [{$log->to_state}]");
        } catch (\Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withErrors(['workflow' => $e->getMessage()])->withInput();
        }
    }

    protected function resolveModelInstance(string $slice, int|string $id)
    {
        $manager = app(SliceManager::class);

        // 1. Try finding slice manifest
        $manifest = $manager->getSlice($slice) ?: $manager->getSlice(Str::studly($slice));
        if ($manifest) {
            $modelClass = $manifest->getModelClass();
            if ($modelClass && class_exists($modelClass)) {
                return $modelClass::find($id);
            }
        }

        // 2. Try common model conventions
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