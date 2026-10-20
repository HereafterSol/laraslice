<?php

namespace LaraSlice\Slices\Workflows\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
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

    /**
     * Apply the same workflow transition to many records (index bulk action).
     * Body: transition, ids[] (or ids comma-separated), optional remarks / assignment.
     */
    public function bulkTransition(Request $request, string $slice)
    {
        $validated = $request->validate([
            'transition' => ['required', 'string'],
            'ids' => ['required'],
            'remarks' => ['nullable', 'string'],
            'assigned_to_role' => ['nullable', 'string'],
            'assigned_to_department' => ['nullable', 'string'],
        ]);

        $ids = $validated['ids'];
        if (is_string($ids)) {
            $ids = array_filter(array_map('trim', explode(',', $ids)));
        }
        if (! is_array($ids) || $ids === []) {
            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => 'No record ids provided.'], 422)
                : back()->withErrors(['workflow' => 'Select at least one record.']);
        }

        $engine = app(WorkflowEngineService::class);
        $payload = [
            'remarks' => $validated['remarks'] ?? null,
            'assigned_to_role' => $validated['assigned_to_role'] ?? null,
            'assigned_to_department' => $validated['assigned_to_department'] ?? null,
            'attachments' => [],
        ];

        $ok = 0;
        $fail = [];
        foreach ($ids as $id) {
            $model = $this->resolveModelInstance($slice, $id);
            if (! $model) {
                $fail[] = "{$id}: not found";
                continue;
            }
            try {
                $engine->applyTransition($model, $validated['transition'], $payload, $request->user());
                $ok++;
            } catch (\Throwable $e) {
                $fail[] = "{$id}: ".$e->getMessage();
            }
        }

        $message = "Bulk transition: {$ok} succeeded".($fail !== [] ? ', '.count($fail).' failed' : '');
        if ($request->wantsJson()) {
            return response()->json([
                'success' => $fail === [],
                'message' => $message,
                'succeeded' => $ok,
                'failures' => $fail,
            ], $fail === [] ? 200 : 422);
        }

        if ($fail !== []) {
            return back()->with('warning', $message)->withErrors(['workflow' => implode('; ', array_slice($fail, 0, 5))]);
        }

        return back()->with('success', $message);
    }

    protected function resolveModelInstance(string $slice, int|string $id)
    {
        $manager = app(SliceManager::class);

        // 1. Try finding via workflow configuration entity_model
        $wf = DB::table('laraslice_workflows')
            ->where('slice_name', $slice)
            ->orWhere('slice_name', Str::studly($slice))
            ->orWhere('slug', $slice)
            ->first();

        if ($wf && $wf->entity_model && class_exists($wf->entity_model)) {
            return $wf->entity_model::find($id);
        }

        // 2. Try finding slice manifest
        $manifest = $manager->getSlice($slice) ?: $manager->getSlice(Str::studly($slice));
        if ($manifest) {
            $modelClass = $manifest->getModelClass();
            if ($modelClass && class_exists($modelClass)) {
                return $modelClass::find($id);
            }
        }

        // 3. Try common model conventions
        $studlySlice = Str::studly($slice);
        $singularModel = Str::studly(Str::singular($slice));

        $candidates = [
            "App\\Slices\\Support\\{$studlySlice}\\Models\\{$singularModel}",
            "App\\Slices\\Billing\\{$studlySlice}\\Models\\{$singularModel}",
            "App\\Slices\\Legal\\{$studlySlice}\\Models\\{$singularModel}",
            "App\\Slices\\Grants\\{$studlySlice}\\Models\\{$singularModel}",
            "App\\Models\\{$singularModel}",
            "App\\Models\\{$studlySlice}",
        ];

        foreach ($candidates as $cls) {
            if (class_exists($cls)) {
                return $cls::find($id);
            }
        }

        return null;
    }
}