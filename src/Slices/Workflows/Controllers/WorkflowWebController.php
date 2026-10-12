<?php

namespace LaraSlice\Slices\Workflows\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Slices\Workflows\Examples\CorporateInvoiceWorkflow;
use LaraSlice\Slices\Workflows\Examples\DisputeAdjudicationWorkflow;
use LaraSlice\Slices\Workflows\Examples\ExecutiveProcurementWorkflow;
use LaraSlice\Slices\Workflows\Examples\GrantDisbursementWorkflow;
use LaraSlice\Slices\Workflows\Examples\SupportTicketWorkflow;
use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Models\WorkflowLog;
use LaraSlice\Slices\Workflows\Models\WorkflowRoutingRule;
use LaraSlice\Slices\Workflows\Models\WorkflowState;
use LaraSlice\Slices\Workflows\Models\WorkflowTransition;

class WorkflowWebController extends Controller
{
    public function index(SliceManager $manager, \LaraSlice\Slices\Workflows\Services\WorkflowEngineService $engine)
    {
        $slices = $manager->getAllSlices();
        $installedSliceNames = collect($slices)->pluck('name')->all();

        // 1. Automatically synchronize any workflows defined in slice.json or slice.yaml of installed slices
        foreach ($slices as $slice) {
            $workflowConfig = $slice->raw['workflow'] ?? null;
            if (empty($workflowConfig) && file_exists($slice->path.'/slice.json')) {
                $sData = json_decode(@file_get_contents($slice->path.'/slice.json'), true) ?: [];
                $workflowConfig = $sData['workflow'] ?? null;
            }
            if (empty($workflowConfig) && file_exists($slice->path.'/slice.yaml') && class_exists(\Symfony\Component\Yaml\Yaml::class)) {
                $yData = \Symfony\Component\Yaml\Yaml::parse(@file_get_contents($slice->path.'/slice.yaml')) ?: [];
                $workflowConfig = $yData['workflow'] ?? null;
            }

            if (! empty($workflowConfig) && is_array($workflowConfig)) {
                if (! isset($workflowConfig['enabled']) || $workflowConfig['enabled'] !== false) {
                    $entityModel = $slice->getModelClass();
                    $engine->syncWorkflowFromManifest($workflowConfig, $slice->name, $entityModel);
                }
            }
        }

        // 2. Display all registered workflows with their state and transition counts
        $workflows = Workflow::withCount(['states', 'transitions'])
            ->latest()
            ->get();

        return view('workflows::index', compact('workflows', 'slices'));
    }

    public function show(int $id)
    {
        $workflow = Workflow::with(['states', 'transitions.routingRules'])->findOrFail($id);

        return view('workflows::show', compact('workflow'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:100'],
            'domain' => ['nullable', 'string', 'max:100'],
            'slice_name' => ['nullable', 'string', 'max:100'],
            'entity_model' => ['nullable', 'string', 'max:255'],
            'initial_state_label' => ['nullable', 'string', 'max:100'],
        ]);

        $workflow = Workflow::create([
            'name' => $validated['name'],
            'slug' => Str::snake($validated['slug']),
            'domain' => $validated['domain'],
            'slice_name' => $validated['slice_name'],
            'entity_model' => $validated['entity_model'],
            'is_active' => true,
        ]);

        // Create default initial state
        WorkflowState::create([
            'workflow_id' => $workflow->id,
            'slug' => 'draft',
            'label' => $validated['initial_state_label'] ?: 'Draft',
            'badge_color' => 'slate',
            'icon' => 'file-text',
            'is_initial' => true,
            'position' => 10,
        ]);

        return redirect()->route('workflows.show', $workflow->id)
            ->with('success', "Workflow '{$workflow->name}' created successfully! You can now add stages and transition rules.");
    }

    public function addState(Request $request, int $id)
    {
        $workflow = Workflow::findOrFail($id);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:100'],
            'badge_color' => ['nullable', 'string', 'max:50'],
            'icon' => ['nullable', 'string', 'max:50'],
            'sla_hours' => ['nullable', 'integer', 'min:1'],
            'is_initial' => ['nullable', 'boolean'],
            'is_terminal' => ['nullable', 'boolean'],
        ]);

        $slug = Str::snake($validated['slug']);

        if ($workflow->states()->where('slug', $slug)->exists()) {
            return back()->withErrors(['state' => "State slug '{$slug}' already exists in this workflow."]);
        }

        // If setting as initial, remove existing initial
        if (! empty($validated['is_initial'])) {
            $workflow->states()->update(['is_initial' => false]);
        }

        $maxPos = $workflow->states()->max('position') ?: 0;

        WorkflowState::create([
            'workflow_id' => $workflow->id,
            'slug' => $slug,
            'label' => $validated['label'],
            'badge_color' => $validated['badge_color'] ?: 'slate',
            'icon' => $validated['icon'] ?: 'circle',
            'sla_hours' => $validated['sla_hours'] ?: null,
            'is_initial' => ! empty($validated['is_initial']),
            'is_terminal' => ! empty($validated['is_terminal']),
            'position' => $maxPos + 10,
        ]);

        return back()->with('success', "Added stage '{$validated['label']}' to workflow pipeline.");
    }

    public function updateState(Request $request, int $id, int $stateId)
    {
        $workflow = Workflow::findOrFail($id);
        $state = $workflow->states()->findOrFail($stateId);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'badge_color' => ['nullable', 'string'],
            'sla_hours' => ['nullable', 'integer', 'min:1'],
            'is_terminal' => ['nullable', 'boolean'],
            'is_initial' => ['nullable', 'boolean'],
        ]);

        $state->update([
            'label' => $validated['label'],
            'badge_color' => $validated['badge_color'] ?: 'slate',
            'sla_hours' => $validated['sla_hours'] ?: null,
            'is_terminal' => ! empty($validated['is_terminal']),
            'is_initial' => ! empty($validated['is_initial']),
        ]);

        return back()->with('success', "Updated stage '{$state->label}'.");
    }

    public function deleteState(int $id, int $stateId)
    {
        $workflow = Workflow::findOrFail($id);
        $state = $workflow->states()->findOrFail($stateId);

        // Delete associated transitions
        $workflow->transitions()->where(function ($q) use ($state) {
            $q->where('from_state_slug', $state->slug)
              ->orWhere('to_state_slug', $state->slug);
        })->delete();

        $label = $state->label;
        $state->delete();

        return back()->with('success', "Deleted stage '{$label}' and cleaned up connected transitions.");
    }

    public function addTransition(Request $request, int $id)
    {
        $workflow = Workflow::findOrFail($id);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:100'],
            'from_state_slug' => ['required', 'string'],
            'to_state_slug' => ['required', 'string'],
            'button_color' => ['nullable', 'string'],
            'permission_gate' => ['nullable', 'string'],
            'requires_remarks' => ['nullable', 'boolean'],
            'requires_attachment' => ['nullable', 'boolean'],
            'routing_type' => ['nullable', 'string'],
            'target_role' => ['nullable', 'string'],
            'target_department' => ['nullable', 'string'],
        ]);

        $slug = Str::snake($validated['slug']);

        $maxPos = $workflow->transitions()->max('position') ?: 0;

        $transition = WorkflowTransition::create([
            'workflow_id' => $workflow->id,
            'slug' => $slug,
            'label' => $validated['label'],
            'from_state_slug' => $validated['from_state_slug'],
            'to_state_slug' => $validated['to_state_slug'],
            'button_color' => $validated['button_color'] ?: 'primary',
            'permission_gate' => $validated['permission_gate'] ?: null,
            'requires_remarks' => ! empty($validated['requires_remarks']),
            'requires_attachment' => ! empty($validated['requires_attachment']),
            'position' => $maxPos + 10,
        ]);

        if (! empty($validated['routing_type']) || ! empty($validated['target_role']) || ! empty($validated['target_department'])) {
            WorkflowRoutingRule::create([
                'transition_id' => $transition->id,
                'routing_type' => $validated['routing_type'] ?: 'role',
                'target_role' => $validated['target_role'] ?: null,
                'target_department' => $validated['target_department'] ?: null,
                'notify_parties' => true,
            ]);
        }

        return back()->with('success', "Added transition '{$validated['label']}' from [{$validated['from_state_slug']}] to [{$validated['to_state_slug']}].");
    }

    public function updateTransition(Request $request, int $id, int $transId)
    {
        $workflow = Workflow::findOrFail($id);
        $transition = $workflow->transitions()->findOrFail($transId);

        $validated = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'from_state_slug' => ['required', 'string'],
            'to_state_slug' => ['required', 'string'],
            'button_color' => ['nullable', 'string'],
            'permission_gate' => ['nullable', 'string'],
            'requires_remarks' => ['nullable', 'boolean'],
            'requires_attachment' => ['nullable', 'boolean'],
            'routing_type' => ['nullable', 'string'],
            'target_role' => ['nullable', 'string'],
            'target_department' => ['nullable', 'string'],
        ]);

        $transition->update([
            'label' => $validated['label'],
            'from_state_slug' => $validated['from_state_slug'],
            'to_state_slug' => $validated['to_state_slug'],
            'button_color' => $validated['button_color'] ?: 'primary',
            'permission_gate' => $validated['permission_gate'] ?: null,
            'requires_remarks' => ! empty($validated['requires_remarks']),
            'requires_attachment' => ! empty($validated['requires_attachment']),
        ]);

        if (! empty($validated['routing_type']) || ! empty($validated['target_role']) || ! empty($validated['target_department'])) {
            $transition->routingRule()->updateOrCreate(
                ['transition_id' => $transition->id],
                [
                    'routing_type' => $validated['routing_type'] ?: 'role',
                    'target_role' => $validated['target_role'] ?: null,
                    'target_department' => $validated['target_department'] ?: null,
                    'notify_parties' => true,
                ]
            );
        } else {
            $transition->routingRule()?->delete();
        }

        return back()->with('success', "Updated transition rule '{$transition->label}'.");
    }

    public function deleteTransition(int $id, int $transId)
    {
        $workflow = Workflow::findOrFail($id);
        $transition = $workflow->transitions()->findOrFail($transId);

        $label = $transition->label;
        $transition->delete();

        return back()->with('success', "Deleted transition rule '{$label}'.");
    }

    public function syncManifest(int $id, SliceManager $manager)
    {
        $workflow = Workflow::with(['states', 'transitions.routingRules'])->findOrFail($id);

        if (! $workflow->slice_name) {
            return back()->withErrors(['manifest' => 'This workflow is not linked to a specific slice.']);
        }

        $slice = $manager->getSlice($workflow->slice_name);
        if (! $slice) {
            return back()->withErrors(['manifest' => "Could not locate slice directory for '{$workflow->slice_name}'."]);
        }

        $manifestFile = $slice->path.'/slice.json';
        if (! File::exists($manifestFile)) {
            return back()->withErrors(['manifest' => "Manifest file not found at {$manifestFile}"]);
        }

        $manifest = json_decode(File::get($manifestFile), true) ?: [];

        $manifest['workflow'] = [
            'enabled' => $workflow->is_active,
            'slug' => $workflow->slug,
            'name' => $workflow->name,
            'domain' => $workflow->domain,
            'entity_model' => $workflow->entity_model,
            'states' => $workflow->states->map(function ($s) {
                return [
                    'slug' => $s->slug,
                    'label' => $s->label,
                    'color' => $s->badge_color,
                    'icon' => $s->icon,
                    'initial' => $s->is_initial,
                    'terminal' => $s->is_terminal,
                    'sla_hours' => $s->sla_hours,
                ];
            })->toArray(),
            'transitions' => $workflow->transitions->map(function ($t) {
                $r = $t->routingRules->first();
                $arr = [
                    'slug' => $t->slug,
                    'name' => $t->label,
                    'from' => explode(',', $t->from_state_slug),
                    'to' => $t->to_state_slug,
                    'permission' => $t->permission_gate,
                    'color' => $t->button_color,
                    'requires_remarks' => (bool) $t->requires_remarks,
                    'requires_attachment' => (bool) $t->requires_attachment,
                ];
                if ($r) {
                    $arr['routing'] = [
                        'type' => $r->routing_type,
                        'target_role' => $r->target_role,
                        'target_department' => $r->target_department,
                        'notify' => (bool) $r->notify_parties,
                    ];
                }
                return $arr;
            })->toArray(),
        ];

        File::put($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return back()->with('success', "Workflow synchronized into {$workflow->slice_name}/slice.json for GitOps & multi-environment deployment!");
    }

    public function inbox(Request $request)
    {
        $user = $request->user();

        $query = WorkflowLog::query();
        if ($user) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_to_user_id', $user->id);
                if (! empty($user->role)) {
                    $q->orWhere('assigned_to_role', $user->role);
                }
                if (! empty($user->department)) {
                    $q->orWhere('assigned_to_department', $user->department);
                }
            });
        }

        $items = $query->latest('id')->take(50)->get();

        return view('workflows::inbox', compact('items'));
    }

    public function logs()
    {
        $logs = WorkflowLog::with(['performer', 'assignee'])
            ->latest('id')
            ->paginate(25);

        return view('workflows::logs', compact('logs'));
    }

    public function seedExamples(SliceManager $manager)
    {
        $installedNames = collect($manager->getAllSlices())->pluck('name')->map(fn($n) => strtolower($n))->all();

        $seeded = [];
        if (in_array('disputes', $installedNames)) {
            DisputeAdjudicationWorkflow::seed();
            $seeded[] = 'Disputes';
        }
        if (in_array('tickets', $installedNames)) {
            SupportTicketWorkflow::seed();
            $seeded[] = 'Tickets';
        }
        if (in_array('invoices', $installedNames)) {
            CorporateInvoiceWorkflow::seed();
            $seeded[] = 'Invoices';
        }
        if (in_array('requisitions', $installedNames) || in_array('procurement', $installedNames)) {
            ExecutiveProcurementWorkflow::seed();
            $seeded[] = 'Requisitions';
        }
        if (in_array('grants', $installedNames) || in_array('claims', $installedNames)) {
            GrantDisbursementWorkflow::seed();
            $seeded[] = 'Grants';
        }

        $msg = count($seeded) > 0
            ? 'Successfully seeded enterprise workflows for installed slices: ' . implode(', ', $seeded)
            : 'No matching installed slices found to seed examples for.';

        return redirect()->route('workflows.index')->with('success', $msg);
    }
}