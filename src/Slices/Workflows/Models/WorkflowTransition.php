<?php

namespace LaraSlice\Slices\Workflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LaraSlice\Core\Security\Access;

class WorkflowTransition extends Model
{
    protected $table = 'laraslice_workflow_transitions';

    protected $guarded = [];

    protected $casts = [
        'requires_remarks' => 'boolean',
        'requires_attachment' => 'boolean',
        'confirmation_dialog' => 'boolean',
        'guard_rules' => 'array',
        'actions' => 'array',
        'position' => 'integer',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function routingRules(): HasMany
    {
        return $this->hasMany(WorkflowRoutingRule::class, 'transition_id');
    }

    public function canApply(string $currentState, $model = null, $user = null): bool
    {
        // 1. Verify from_state
        $fromStates = array_map('trim', explode(',', $this->from_state_slug));
        if (! in_array($currentState, $fromStates) && ! in_array('*', $fromStates)) {
            return false;
        }

        // 2. Permission Gate
        if ($this->permission_gate && ! $this->userMayApply($user)) {
            return false;
        }

        // 3. Guard Rules (JSON declarative guards)
        if ($model && ! empty($this->guard_rules)) {
            if (! $this->passesGuards($model)) {
                return false;
            }
        }

        return true;
    }

    protected function userMayApply($user = null): bool
    {
        $user = $user ?: auth()->user();

        if (! $user) {
            return app()->runningInConsole() && ! app()->runningUnitTests();
        }

        if (Access::isSuperAdmin($user)) {
            return true;
        }

        return Access::allows($user, $this->permission_gate);
    }

    public function passesGuards($model): bool
    {
        if (empty($this->guard_rules)) {
            return true;
        }

        foreach ($this->guard_rules as $rule) {
            $field = $rule['field'] ?? null;
            $operator = $rule['operator'] ?? '==';
            $expected = $rule['value'] ?? null;

            if (! $field) {
                continue;
            }

            $actual = data_get($model, $field);

            $passed = match ($operator) {
                '==' => $actual == $expected,
                '===' => $actual === $expected,
                '!=' => $actual != $expected,
                '!==' => $actual !== $expected,
                '>' => $actual > $expected,
                '>=' => $actual >= $expected,
                '<' => $actual < $expected,
                '<=' => $actual <= $expected,
                'in' => is_array($expected) && in_array($actual, $expected),
                'not_in' => is_array($expected) && ! in_array($actual, $expected),
                default => true,
            };

            if (! $passed) {
                return false;
            }
        }

        return true;
    }
}