<?php

namespace LaraSlice\Slices\Workflows\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowLog extends Model
{
    protected $table = 'laraslice_workflow_logs';

    protected $guarded = [];

    protected $casts = [
        'attachments' => 'array',
        'metadata' => 'array',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function getPerformerNameAttribute(): string
    {
        if ($this->performer) {
            return $this->performer->name ?? 'User #'.$this->performed_by_id;
        }

        return $this->performed_by_id ? 'User #'.$this->performed_by_id : 'System Automation';
    }

    public function getAssigneeNameAttribute(): ?string
    {
        if ($this->assignee) {
            return $this->assignee->name ?? 'User #'.$this->assigned_to_user_id;
        }

        if ($this->assigned_to_role) {
            return 'Role: '.ucwords(str_replace(['_', '-'], ' ', $this->assigned_to_role));
        }

        if ($this->assigned_to_department) {
            return 'Dept: '.$this->assigned_to_department;
        }

        return null;
    }
}