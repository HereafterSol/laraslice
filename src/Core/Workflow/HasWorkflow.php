<?php

namespace LaraSlice\Core\Workflow;

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

    public function canTransitionTo(string $transitionName): bool
    {
        return WorkflowEngine::canTransition($this, $transitionName);
    }

    public function transitionTo(string $transitionName): bool
    {
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

    public function getAvailableTransitions(): array
    {
        return WorkflowEngine::getAvailableTransitions($this);
    }
}
