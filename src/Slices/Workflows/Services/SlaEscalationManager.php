<?php

namespace LaraSlice\Slices\Workflows\Services;

use Carbon\Carbon;
use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Models\WorkflowLog;

class SlaEscalationManager
{
    /**
     * Get SLA metric status for a specific model instance.
     *
     * @return array{has_sla: bool, sla_hours: ?int, entered_at: ?Carbon, expires_at: ?Carbon, is_breached: bool, hours_remaining: ?float}
     */
    public function getSlaStatus($model): array
    {
        $engine = app(WorkflowEngineService::class);
        $workflow = $engine->getWorkflowForModel($model);

        if (! $workflow) {
            return [
                'has_sla' => false,
                'sla_hours' => null,
                'entered_at' => null,
                'expires_at' => null,
                'is_breached' => false,
                'hours_remaining' => null,
            ];
        }

        $currentState = method_exists($model, 'getCurrentState')
            ? $model->getCurrentState()
            : ($model->status ?? 'draft');

        $state = $workflow->getState($currentState);
        if (! $state || empty($state->sla_hours)) {
            return [
                'has_sla' => false,
                'sla_hours' => null,
                'entered_at' => null,
                'expires_at' => null,
                'is_breached' => false,
                'hours_remaining' => null,
            ];
        }

        // Determine when state was entered
        $enteredAt = null;
        if (! empty($model->workflow_state_entered_at)) {
            $enteredAt = Carbon::parse($model->workflow_state_entered_at);
        } else {
            // Find latest log for this state
            $lastLog = WorkflowLog::where('entity_type', get_class($model))
                ->where('entity_id', $model->getKey())
                ->where('to_state', $currentState)
                ->latest('id')
                ->first();

            $enteredAt = $lastLog ? Carbon::parse($lastLog->created_at) : Carbon::parse($model->updated_at ?? now());
        }

        $expiresAt = $enteredAt->copy()->addHours($state->sla_hours);
        $isBreached = now()->greaterThan($expiresAt);
        $hoursRemaining = round(now()->diffInMinutes($expiresAt, false) / 60, 1);

        return [
            'has_sla' => true,
            'sla_hours' => $state->sla_hours,
            'entered_at' => $enteredAt,
            'expires_at' => $expiresAt,
            'is_breached' => $isBreached,
            'hours_remaining' => $hoursRemaining,
        ];
    }

    /**
     * Check all active workflows with SLA-enabled states and return breached items.
     *
     * @return array<array{workflow: string, entity_type: string, entity_id: int, state: string, breached_by_hours: float}>
     */
    public function scanBreachedEntities(): array
    {
        $breached = [];
        $workflows = Workflow::where('is_active', true)->with('states')->get();

        foreach ($workflows as $wf) {
            $modelClass = $wf->entity_model;
            if (! $modelClass || ! class_exists($modelClass)) {
                continue;
            }

            foreach ($wf->states as $state) {
                if (empty($state->sla_hours) || $state->is_terminal) {
                    continue;
                }

                $stateSlug = $state->slug;
                $records = $modelClass::where('status', $stateSlug)->get();

                foreach ($records as $record) {
                    $sla = $this->getSlaStatus($record);
                    if ($sla['is_breached']) {
                        $breached[] = [
                            'workflow' => $wf->name,
                            'entity_type' => $modelClass,
                            'entity_id' => $record->getKey(),
                            'state' => $stateSlug,
                            'sla_hours' => $state->sla_hours,
                            'breached_by_hours' => abs($sla['hours_remaining']),
                        ];
                    }
                }
            }
        }

        return $breached;
    }
}