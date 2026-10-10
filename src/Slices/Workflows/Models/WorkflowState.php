<?php

namespace LaraSlice\Slices\Workflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowState extends Model
{
    protected $table = 'laraslice_workflow_states';

    protected $guarded = [];

    protected $casts = [
        'is_initial' => 'boolean',
        'is_terminal' => 'boolean',
        'sla_hours' => 'integer',
        'position' => 'integer',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function outgoingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'workflow_id', 'workflow_id')
            ->where(function ($q) {
                $q->where('from_state_slug', $this->slug)
                  ->orWhere('from_state_slug', '*');
            });
    }

    public function incomingTransitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'workflow_id', 'workflow_id')
            ->where('to_state_slug', $this->slug);
    }
}