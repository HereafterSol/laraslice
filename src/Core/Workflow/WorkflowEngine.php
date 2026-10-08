<?php

namespace LaraSlice\Core\Workflow;

class WorkflowEngine
{
    /** @var array<string, array<string, WorkflowTransition>> */
    protected static array $workflows = [];

    public static function define(string $modelClass, array $transitions): void
    {
        static::$workflows[$modelClass] = $transitions;
    }

    public static function getTransitions(string $modelClass): array
    {
        return static::$workflows[$modelClass] ?? [];
    }

    public static function getAvailableTransitions($model): array
    {
        $class = get_class($model);
        $transitions = static::getTransitions($class);
        $currentState = $model->getCurrentState();

        return array_filter($transitions, fn (WorkflowTransition $t) => $t->canApply($currentState, $model));
    }

    public static function canTransition($model, string $transitionName): bool
    {
        $class = get_class($model);
        $transitions = static::getTransitions($class);

        if (! isset($transitions[$transitionName])) {
            return false;
        }

        return $transitions[$transitionName]->canApply($model->getCurrentState(), $model);
    }
}
