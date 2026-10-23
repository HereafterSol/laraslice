<?php

declare(strict_types=1);

namespace LaraSlice\Core\Workflow\Listeners;

use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Workflow\Events\WorkflowTransitioned;
use LaraSlice\Core\Workflow\Notifications\WorkflowTransitionNotification;

/**
 * Approval notifications: after a transition, notify the routed
 * assignee and (when different) the record's owner — never the
 * person who performed the transition. Active only when the
 * host app carries the notifications table (database channel);
 * otherwise it quietly does nothing and hosts can listen for
 * WorkflowTransitioned themselves.
 */
class NotifyWorkflowTransition
{
    public function handle(WorkflowTransitioned $event): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $userClass = config('auth.providers.users.model') ?: 'App\\Models\\User';
        if (! class_exists($userClass)) {
            return;
        }

        $recipients = collect();
        $assigneeId = $event->log->assigned_to_user_id ?? null;
        if ($assigneeId) {
            $recipients->push($assigneeId);
        }
        $model = $event->model;
        foreach (['user_id', 'created_by', 'added_by'] as $ownerKey) {
            if (isset($model->{$ownerKey}) && $model->{$ownerKey}) {
                $recipients->push($model->{$ownerKey});
                break;
            }
        }

        $recipients = $recipients
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn ($id) => $event->performedById !== null && $id === (int) $event->performedById)
            ->values();
        if ($recipients->isEmpty()) {
            return;
        }

        $users = $userClass::query()->whereIn('id', $recipients->all())->get();
        if ($users->isEmpty()) {
            return;
        }

        $label = class_basename($model);
        $transitionLabel = $event->log->metadata['transition_label'] ?? $event->log->transition_slug;
        $title = $assigneeId && $recipients->contains((int) $assigneeId)
            ? "{$label} assigned to you"
            : "{$label} updated: {$transitionLabel}";
        $body = "{$label} #{$model->getKey()} moved to '{$event->log->to_state}' via {$transitionLabel}.";

        Notification::send($users, new WorkflowTransitionNotification($title, $body, get_class($model), $model->getKey()));
    }
}
