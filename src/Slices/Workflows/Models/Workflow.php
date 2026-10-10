<?php

namespace LaraSlice\Slices\Workflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workflow extends Model
{
    protected $table = 'laraslice_workflows';

    protected $guarded = [];

    protected $casts = [
        'trigger_conditions' => 'array',
        'is_active' => 'boolean',
    ];

    public function states(): HasMany
    {
        return $this->hasMany(WorkflowState::class, 'workflow_id')->orderBy('position');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'workflow_id')->orderBy('position');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowLog::class, 'workflow_id')->latest();
    }

    public function initialState(): ?WorkflowState
    {
        return $this->states()->where('is_initial', true)->first() ?: $this->states()->first();
    }

    public function getState(string $slug): ?WorkflowState
    {
        return $this->states()->where('slug', $slug)->first();
    }

    public function getTransition(string $slug): ?WorkflowTransition
    {
        return $this->transitions()->where('slug', $slug)->first();
    }
}