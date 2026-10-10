<?php

namespace LaraSlice\Slices\Workflows\Examples;

use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

class SupportTicketWorkflow
{
    public static function definition(): array
    {
        return [
            'slug' => 'support_tickets',
            'name' => 'Enterprise Support Ticket & SLA Escalation',
            'domain' => 'Customer Service',
            'entity_model' => 'App\Slices\Support\Tickets\Models\Ticket',
            'enabled' => true,
            'states' => [
                [
                    'slug' => 'new',
                    'label' => 'New / Unassigned',
                    'color' => 'slate',
                    'icon' => 'inbox',
                    'initial' => true,
                    'sla_hours' => 2,
                ],
                [
                    'slug' => 'triaged',
                    'label' => 'Triaged & Assigned',
                    'color' => 'sky',
                    'icon' => 'user-check',
                    'sla_hours' => 8,
                ],
                [
                    'slug' => 'in_progress',
                    'label' => 'Under Investigation',
                    'color' => 'amber',
                    'icon' => 'clock',
                    'sla_hours' => 24,
                ],
                [
                    'slug' => 'escalated_engineering',
                    'label' => 'Escalated to Engineering',
                    'color' => 'purple',
                    'icon' => 'code',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'escalated_billing',
                    'label' => 'Escalated to Billing',
                    'color' => 'indigo',
                    'icon' => 'dollar-sign',
                    'sla_hours' => 24,
                ],
                [
                    'slug' => 'resolved',
                    'label' => 'Resolved',
                    'color' => 'emerald',
                    'icon' => 'check-circle',
                ],
                [
                    'slug' => 'closed',
                    'label' => 'Closed',
                    'color' => 'slate',
                    'icon' => 'archive',
                    'terminal' => true,
                ],
            ],
            'transitions' => [
                [
                    'slug' => 'triage_and_assign',
                    'name' => 'Triage & Assign',
                    'from' => ['new'],
                    'to' => 'triaged',
                    'permission' => 'tickets.triage',
                    'color' => 'primary',
                    'icon' => 'user-plus',
                    'routing' => [
                        'type' => 'role',
                        'target_role' => 'support_agent',
                        'target_department' => 'Customer Support',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'start_progress',
                    'name' => 'Start Work',
                    'from' => ['triaged'],
                    'to' => 'in_progress',
                    'permission' => 'tickets.handle',
                    'color' => 'primary',
                    'icon' => 'play',
                    'routing' => [
                        'type' => 'assign_current_user',
                        'assign_current_user' => true,
                    ],
                ],
                [
                    'slug' => 'escalate_to_engineering',
                    'name' => 'Escalate to Engineering (Bug)',
                    'from' => ['in_progress', 'triaged'],
                    'to' => 'escalated_engineering',
                    'permission' => 'tickets.escalate',
                    'requires_remarks' => true,
                    'color' => 'purple',
                    'icon' => 'arrow-up-right',
                    'routing' => [
                        'type' => 'department',
                        'target_department' => 'Engineering',
                        'target_role' => 'software_engineer',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'escalate_to_billing',
                    'name' => 'Forward to Billing (Refund / Dispute)',
                    'from' => ['in_progress', 'triaged'],
                    'to' => 'escalated_billing',
                    'permission' => 'tickets.escalate',
                    'requires_remarks' => true,
                    'color' => 'indigo',
                    'icon' => 'arrow-right-circle',
                    'routing' => [
                        'type' => 'department',
                        'target_department' => 'Billing',
                        'target_role' => 'billing_specialist',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'resolve',
                    'name' => 'Mark as Resolved',
                    'from' => ['in_progress', 'escalated_engineering', 'escalated_billing'],
                    'to' => 'resolved',
                    'permission' => 'tickets.resolve',
                    'requires_remarks' => true,
                    'color' => 'success',
                    'icon' => 'check-circle',
                ],
                [
                    'slug' => 'close',
                    'name' => 'Close Ticket',
                    'from' => ['resolved'],
                    'to' => 'closed',
                    'permission' => 'tickets.close',
                    'color' => 'secondary',
                    'icon' => 'check-check',
                ],
                [
                    'slug' => 'reopen',
                    'name' => 'Reopen Ticket',
                    'from' => ['resolved', 'closed'],
                    'to' => 'in_progress',
                    'permission' => 'tickets.reopen',
                    'requires_remarks' => true,
                    'color' => 'warning',
                    'icon' => 'refresh-cw',
                ],
            ],
        ];
    }

    public static function seed(): Workflow
    {
        return app(WorkflowEngineService::class)->syncWorkflowFromManifest(
            static::definition(),
            'Tickets',
            'App\Slices\Support\Tickets\Models\Ticket'
        );
    }
}