<?php

namespace LaraSlice\Core\Workflow;

class WorkflowTransition
{
    public string $name;
    public string|array $from;
    public string $to;
    public ?string $permission;
    public ?\Closure $guard;

    public function __construct(string $name, string|array $from, string $to, ?string $permission = null, ?\Closure $guard = null)
    {
        $this->name = $name;
        $this->from = is_array($from) ? $from : [$from];
        $this->to = $to;
        $this->permission = $permission;
        $this->guard = $guard;
    }

    public function canApply(string $currentState, $model = null): bool
    {
        if (!in_array($currentState, $this->from) && !in_array('*', $this->from)) {
            return false;
        }

        if ($this->guard && !($this->guard)($model)) {
            return false;
        }

        if ($this->permission !== null && ! $this->userMayApply()) {
            return false;
        }

        return true;
    }

    /**
     * Web requests need a user holding the transition's permission. Console code
     * (jobs, commands, schedulers) without a signed-in user is trusted.
     */
    protected function userMayApply(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return app()->runningInConsole() && ! app()->runningUnitTests();
        }

        return \LaraSlice\Core\Security\Access::allows($user, $this->permission);
    }
}
