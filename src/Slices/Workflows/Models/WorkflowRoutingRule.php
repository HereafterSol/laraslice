<?php

namespace LaraSlice\Slices\Workflows\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowRoutingRule extends Model
{
    protected $table = 'laraslice_workflow_routing_rules';

    protected $guarded = [];

    protected $casts = [
        'notify_parties' => 'boolean',
        'assign_current_user' => 'boolean',
        'target_user_id' => 'integer',
    ];

    public function transition(): BelongsTo
    {
        return $this->belongsTo(WorkflowTransition::class, 'transition_id');
    }
}