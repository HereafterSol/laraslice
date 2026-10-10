<?php

namespace LaraSlice\Slices\Workflows\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Models\WorkflowLog;
use LaraSlice\Slices\Workflows\Models\WorkflowRoutingRule;
use LaraSlice\Slices\Workflows\Models\WorkflowState;
use LaraSlice\Slices\Workflows\Models\WorkflowTransition;

class WorkflowEngineService
{
    protected RoutingResolver $routingResolver;

    public function __construct(?RoutingResolver $routingResolver = null)
    {
        $this->routingResolver = $routingResolver ?: new RoutingResolver();
    }

    /**
     * Locate the Workflow definition for a given Model instance or class.
     */
    public function getWorkflowForModel($model): ?Workflow
    {
        $modelClass = is_object($model) ? get_class($model) : $model;

        // 1. Try finding by entity_model
        $workflow = Workflow::where('entity_model', $modelClass)->where('is_active', true)->first();
        if ($workflow) {
            return $workflow;
        }

        // 2. Try finding by slice_name or slug derived from model class
        $parts = explode('\\', $modelClass);
        $shortName = end($parts);
        $slug = Str::snake(Str::plural($shortName));

        return Workflow::where(function ($q) use ($shortName, $slug) {
            $q->where('slice_name', $shortName)
              ->orWhere('slug', $slug)
              ->orWhere('slug', Str::snake($shortName));
        })->where('is_active', true)->first();
    }

    /**
     * Check if a model can undergo a specific transition.
     */
    public function canTransition($model, string $transitionSlug, $user = null): bool
    {
        $workflow = $this->getWorkflowForModel($model);
        if (! $workflow) {
            return false;
        }

        $transition = $workflow->getTransition($transitionSlug);
        if (! $transition) {
            return false;
        }

        $currentState = method_exists($model, 'getCurrentState')
            ? $model->getCurrentState()
            : ($model->status ?? 'draft');

        return $transition->canApply($currentState, $model, $user);
    }

    /**
     * Return all currently available transitions for a model and user.
     *
     * @return array<WorkflowTransition>
     */
    public function getAvailableTransitions($model, $user = null): array
    {
        $workflow = $this->getWorkflowForModel($model);
        if (! $workflow) {
            return [];
        }

        $currentState = method_exists($model, 'getCurrentState')
            ? $model->getCurrentState()
            : ($model->status ?? 'draft');

        $transitions = $workflow->transitions;
        $available = [];

        foreach ($transitions as $transition) {
            if ($transition->canApply($currentState, $model, $user)) {
                $available[] = $transition;
            }
        }

        return $available;
    }

    /**
     * Atomically execute a transition on a model.
     */
    public function applyTransition($model, string $transitionSlug, array $payload = [], $user = null): WorkflowLog
    {
        $workflow = $this->getWorkflowForModel($model);
        if (! $workflow) {
            throw new \InvalidArgumentException("No active workflow configured for model ".get_class($model));
        }

        $transition = $workflow->getTransition($transitionSlug);
        if (! $transition) {
            throw new \InvalidArgumentException("Undefined workflow transition: '{$transitionSlug}' for workflow '{$workflow->name}'");
        }

        $currentUser = $user ?: auth()->user();
        $currentState = method_exists($model, 'getCurrentState')
            ? $model->getCurrentState()
            : ($model->status ?? 'draft');

        if (! $transition->canApply($currentState, $model, $currentUser)) {
            throw new \LogicException("Cannot apply transition '{$transitionSlug}' from current state '{$currentState}'");
        }

        // Validate mandatory remarks if required
        if ($transition->requires_remarks && empty($payload['remarks'])) {
            throw new \InvalidArgumentException("Remarks are required to execute transition '{$transition->label}'");
        }

        return DB::transaction(function () use ($model, $workflow, $transition, $currentState, $payload, $currentUser) {
            $toState = $transition->to_state_slug;

            // 1. Resolve Routing
            $routing = $this->routingResolver->resolve($transition, $model, $payload, $currentUser);

            // 2. Update model state and routing columns if present
            $stateField = method_exists($model, 'getWorkflowStateField') ? $model->getWorkflowStateField() : 'status';
            $model->{$stateField} = $toState;

            if ($this->hasColumn($model, 'assigned_to_user_id') && array_key_exists('user_id', $routing)) {
                $model->assigned_to_user_id = $routing['user_id'];
            }
            if ($this->hasColumn($model, 'assigned_to_role') && array_key_exists('role', $routing)) {
                $model->assigned_to_role = $routing['role'];
            }
            if ($this->hasColumn($model, 'assigned_to_department') && array_key_exists('department', $routing)) {
                $model->assigned_to_department = $routing['department'];
            }
            if ($this->hasColumn($model, 'workflow_state_entered_at')) {
                $model->workflow_state_entered_at = now();
            }

            $model->save();

            // 3. Record immutable WorkflowLog
            $log = WorkflowLog::create([
                'workflow_id' => $workflow->id,
                'entity_type' => get_class($model),
                'entity_id' => $model->getKey(),
                'transition_slug' => $transition->slug,
                'from_state' => $currentState,
                'to_state' => $toState,
                'performed_by_id' => $currentUser?->id,
                'assigned_to_user_id' => $routing['user_id'] ?? null,
                'assigned_to_role' => $routing['role'] ?? null,
                'assigned_to_department' => $routing['department'] ?? null,
                'remarks' => $payload['remarks'] ?? null,
                'attachments' => $payload['attachments'] ?? null,
                'metadata' => array_merge([
                    'transition_label' => $transition->label,
                    'timestamp' => now()->toIso8601String(),
                ], $payload['metadata'] ?? []),
            ]);

            // 4. Trigger actions (events or custom callbacks)
            if (! empty($transition->actions)) {
                foreach ($transition->actions as $action) {
                    if (($action['type'] ?? '') === 'event' && ! empty($action['class']) && class_exists($action['class'])) {
                        event(new $action['class']($model, $transition, $log));
                    }
                }
            }

            // 5. Notify model hook if present
            if (method_exists($model, 'onWorkflowTransitioned')) {
                $model->onWorkflowTransitioned($transition, $log);
            }

            return $log;
        });
    }

    /**
     * Get complete workflow history for an entity.
     */
    public function getHistory($model)
    {
        return WorkflowLog::where('entity_type', get_class($model))
            ->where('entity_id', $model->getKey())
            ->with(['performer', 'assignee'])
            ->latest('id')
            ->get();
    }

    /**
     * Synchronize a workflow declared in slice.json or array format into the database.
     */
    public function syncWorkflowFromManifest(array $workflowConfig, string $sliceName, ?string $entityModel = null): Workflow
    {
        $slug = $workflowConfig['slug'] ?? Str::snake(Str::plural($sliceName));
        $name = $workflowConfig['name'] ?? ucwords(str_replace('_', ' ', $slug)).' Workflow';

        $workflow = Workflow::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'domain' => $workflowConfig['domain'] ?? null,
                'entity_model' => $entityModel ?: ($workflowConfig['entity_model'] ?? null),
                'slice_name' => $sliceName,
                'trigger_conditions' => $workflowConfig['trigger_conditions'] ?? null,
                'is_active' => $workflowConfig['enabled'] ?? true,
            ]
        );

        // Sync States
        $statePosition = 0;
        foreach ($workflowConfig['states'] ?? [] as $stateData) {
            $statePosition += 10;
            WorkflowState::updateOrCreate(
                [
                    'workflow_id' => $workflow->id,
                    'slug' => $stateData['slug'],
                ],
                [
                    'label' => $stateData['label'] ?? ucfirst($stateData['slug']),
                    'badge_color' => $stateData['color'] ?? ($stateData['badge_color'] ?? 'slate'),
                    'icon' => $stateData['icon'] ?? null,
                    'is_initial' => ! empty($stateData['initial']),
                    'is_terminal' => ! empty($stateData['terminal']),
                    'sla_hours' => $stateData['sla_hours'] ?? null,
                    'position' => $stateData['position'] ?? $statePosition,
                ]
            );
        }

        // Sync Transitions
        $transitionPosition = 0;
        foreach ($workflowConfig['transitions'] ?? [] as $transData) {
            $transitionPosition += 10;
            $fromStates = is_array($transData['from'] ?? null)
                ? implode(',', $transData['from'])
                : (string) ($transData['from'] ?? '*');

            $transition = WorkflowTransition::updateOrCreate(
                [
                    'workflow_id' => $workflow->id,
                    'slug' => $transData['slug'],
                ],
                [
                    'label' => $transData['name'] ?? ($transData['label'] ?? ucfirst($transData['slug'])),
                    'from_state_slug' => $fromStates,
                    'to_state_slug' => $transData['to'],
                    'permission_gate' => $transData['permission'] ?? null,
                    'button_color' => $transData['button_color'] ?? ($transData['color'] ?? 'primary'),
                    'button_icon' => $transData['button_icon'] ?? ($transData['icon'] ?? null),
                    'requires_remarks' => ! empty($transData['requires_remarks']),
                    'requires_attachment' => ! empty($transData['requires_attachment']),
                    'confirmation_dialog' => ! empty($transData['confirmation_dialog']),
                    'guard_rules' => $transData['guards'] ?? null,
                    'actions' => $transData['actions'] ?? null,
                    'position' => $transData['position'] ?? $transitionPosition,
                ]
            );

            // Sync Routing Rule if present
            if (! empty($transData['routing'])) {
                $r = $transData['routing'];
                WorkflowRoutingRule::updateOrCreate(
                    ['transition_id' => $transition->id],
                    [
                        'routing_type' => $r['type'] ?? 'role',
                        'target_role' => $r['target_role'] ?? null,
                        'target_department' => $r['target_department'] ?? null,
                        'target_user_id' => $r['target_user_id'] ?? null,
                        'user_field' => $r['user_field'] ?? null,
                        'notify_parties' => ! empty($r['notify']),
                        'assign_current_user' => ! empty($r['assign_current_user']),
                    ]
                );
            }
        }

        return $workflow->load(['states', 'transitions.routingRules']);
    }

    protected function hasColumn(Model $model, string $column): bool
    {
        try {
            return $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column);
        } catch (\Throwable) {
            return false;
        }
    }
}