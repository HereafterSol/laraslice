<?php

namespace LaraSlice\Core\Workflow;

use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Services\SlaEscalationManager;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

trait HasWorkflow
{
    public function getCurrentState(): string
    {
        $stateField = $this->getWorkflowStateField();

        return (string) ($this->{$stateField} ?? 'draft');
    }

    public function getWorkflowStateField(): string
    {
        return property_exists($this, 'workflowStateField') ? $this->workflowStateField : 'status';
    }

    public function getWorkflowDefinition(): ?Workflow
    {
        return app(WorkflowEngineService::class)->getWorkflowForModel($this);
    }

    public function canTransitionTo(string $transitionName, $user = null): bool
    {
        // 1. Check database-backed workflow engine
        $engine = app(WorkflowEngineService::class);
        $workflow = $engine->getWorkflowForModel($this);

        if ($workflow) {
            return $engine->canTransition($this, $transitionName, $user);
        }

        // 2. Fallback to in-memory WorkflowEngine
        return WorkflowEngine::canTransition($this, $transitionName);
    }

    public function transitionTo(string $transitionName, array $payload = []): mixed
    {
        // 1. Check database-backed workflow engine
        $engine = app(WorkflowEngineService::class);
        $workflow = $engine->getWorkflowForModel($this);

        if ($workflow) {
            return $engine->applyTransition($this, $transitionName, $payload);
        }

        // 2. Fallback to legacy in-memory WorkflowEngine
        $class = get_class($this);
        $transitions = WorkflowEngine::getTransitions($class);

        if (! isset($transitions[$transitionName])) {
            throw new \InvalidArgumentException("Undefined workflow transition: {$transitionName}");
        }

        $transition = $transitions[$transitionName];

        if (! $transition->canApply($this->getCurrentState(), $this)) {
            throw new \LogicException("Cannot apply transition '{$transitionName}' from state '{$this->getCurrentState()}'");
        }

        $stateField = $this->getWorkflowStateField();
        $this->{$stateField} = $transition->to;
        $this->save();

        if (method_exists($this, 'onWorkflowTransitioned')) {
            $this->onWorkflowTransitioned($transition);
        }

        return true;
    }

    public function applyTransition(string $transitionName, array $payload = []): mixed
    {
        return $this->transitionTo($transitionName, $payload);
    }

    public function getAvailableTransitions($user = null): array
    {
        // 1. Check database-backed workflow engine
        $engine = app(WorkflowEngineService::class);
        $workflow = $engine->getWorkflowForModel($this);

        if ($workflow) {
            return $engine->getAvailableTransitions($this, $user);
        }

        // 2. Fallback to in-memory WorkflowEngine
        return WorkflowEngine::getAvailableTransitions($this);
    }

    public function getWorkflowHistory()
    {
        return app(WorkflowEngineService::class)->getHistory($this);
    }

    public function getSlaStatus(): array
    {
        return app(SlaEscalationManager::class)->getSlaStatus($this);
    }

    /* -------------------------------------------------------------------------
     * Eloquent Query Scopes
     * ------------------------------------------------------------------------- */

    public function scopeWhereWorkflowState($query, string|array $state)
    {
        $stateField = $this->getWorkflowStateField();
        return is_array($state) ? $query->whereIn($stateField, $state) : $query->where($stateField, $state);
    }

    public function scopeWhereAssignedToUser($query, int $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }

    public function scopeWhereAssignedToRole($query, string $role)
    {
        return $query->where('assigned_to_role', $role);
    }

    public function scopeWhereAssignedToDepartment($query, string $department)
    {
        return $query->where('assigned_to_department', $department);
    }
}