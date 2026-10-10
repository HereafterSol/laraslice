<?php

namespace LaraSlice\Slices\Workflows\Examples;

use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

class CorporateInvoiceWorkflow
{
    public static function definition(): array
    {
        return [
            'slug' => 'invoices',
            'name' => 'Corporate Invoice Approval & Payment Flow',
            'domain' => 'Billing',
            'entity_model' => 'App\Slices\Billing\Invoices\Models\Invoice',
            'enabled' => true,
            'states' => [
                [
                    'slug' => 'draft',
                    'label' => 'Draft',
                    'color' => 'slate',
                    'icon' => 'file-text',
                    'initial' => true,
                ],
                [
                    'slug' => 'pending_lead',
                    'label' => 'Team Lead Review',
                    'color' => 'amber',
                    'icon' => 'clock',
                    'sla_hours' => 24,
                ],
                [
                    'slug' => 'pending_cfo',
                    'label' => 'CFO Approval',
                    'color' => 'indigo',
                    'icon' => 'shield-check',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'paid',
                    'label' => 'Paid & Reconciled',
                    'color' => 'emerald',
                    'icon' => 'check-circle',
                    'terminal' => true,
                ],
                [
                    'slug' => 'rejected',
                    'label' => 'Rejected',
                    'color' => 'rose',
                    'icon' => 'x-circle',
                    'terminal' => true,
                ],
            ],
            'transitions' => [
                [
                    'slug' => 'submit',
                    'name' => 'Submit for Approval',
                    'from' => ['draft'],
                    'to' => 'pending_lead',
                    'permission' => 'invoices.edit',
                    'color' => 'primary',
                    'icon' => 'send',
                    'routing' => [
                        'type' => 'hierarchy_manager',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'lead_endorse',
                    'name' => 'Endorse to CFO',
                    'from' => ['pending_lead'],
                    'to' => 'pending_cfo',
                    'permission' => 'invoices.lead_approve',
                    'requires_remarks' => true,
                    'color' => 'primary',
                    'icon' => 'arrow-right-circle',
                    'routing' => [
                        'type' => 'role',
                        'target_role' => 'cfo',
                        'target_department' => 'Executive Office',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'cfo_approve',
                    'name' => 'CFO Sign-Off & Pay',
                    'from' => ['pending_cfo'],
                    'to' => 'paid',
                    'permission' => 'invoices.cfo_approve',
                    'requires_remarks' => true,
                    'color' => 'success',
                    'icon' => 'dollar-sign',
                ],
                [
                    'slug' => 'reject_to_draft',
                    'name' => 'Reject back to Draft',
                    'from' => ['pending_lead', 'pending_cfo'],
                    'to' => 'draft',
                    'permission' => 'invoices.lead_approve',
                    'requires_remarks' => true,
                    'color' => 'danger',
                    'icon' => 'rotate-ccw',
                ],
                [
                    'slug' => 'cancel',
                    'name' => 'Cancel Invoice',
                    'from' => ['draft', 'pending_lead', 'pending_cfo'],
                    'to' => 'rejected',
                    'permission' => 'invoices.delete',
                    'requires_remarks' => true,
                    'color' => 'danger',
                    'icon' => 'x-circle',
                ],
            ],
        ];
    }

    public static function seed(): Workflow
    {
        return app(WorkflowEngineService::class)->syncWorkflowFromManifest(
            static::definition(),
            'Invoices',
            'App\Slices\Billing\Invoices\Models\Invoice'
        );
    }
}