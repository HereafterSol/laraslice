<?php

namespace LaraSlice\Slices\Workflows\Examples;

use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

class ExecutiveProcurementWorkflow
{
    public static function definition(): array
    {
        return [
            'slug' => 'executive_procurement',
            'name' => 'Executive Requisition & Capital Expenditure Approval',
            'domain' => 'Procurement & Operations',
            'entity_model' => 'App\Slices\Procurement\Requisitions\Models\Requisition',
            'enabled' => true,
            'states' => [
                [
                    'slug' => 'draft',
                    'label' => 'Draft / Requisitioned',
                    'color' => 'slate',
                    'icon' => 'file-text',
                    'initial' => true,
                    'sla_hours' => 24,
                ],
                [
                    'slug' => 'department_endorsed',
                    'label' => 'Department Endorsed',
                    'color' => 'sky',
                    'icon' => 'user-check',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'budget_scrutinized',
                    'label' => 'Budget Scrutinized',
                    'color' => 'amber',
                    'icon' => 'calculator',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'board_sanctioned',
                    'label' => 'Executive Board Sanctioned',
                    'color' => 'purple',
                    'icon' => 'shield-check',
                    'sla_hours' => 72,
                ],
                [
                    'slug' => 'po_generated',
                    'label' => 'Purchase Order Issued',
                    'color' => 'emerald',
                    'icon' => 'shopping-cart',
                    'terminal' => true,
                ],
                [
                    'slug' => 'returned_observation',
                    'label' => 'Returned with Observation',
                    'color' => 'rose',
                    'icon' => 'alert-triangle',
                    'terminal' => false,
                ],
            ],
            'transitions' => [
                [
                    'slug' => 'endorse_department',
                    'name' => 'Endorse Departmental Requisition',
                    'from' => ['draft', 'returned_observation'],
                    'to' => 'department_endorsed',
                    'permission' => 'procurement.endorse',
                    'color' => 'primary',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'department_director',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'scrutinize_budget',
                    'name' => 'Verify Budget & Allocation Codes',
                    'from' => ['department_endorsed'],
                    'to' => 'budget_scrutinized',
                    'permission' => 'procurement.scrutiny',
                    'color' => 'amber',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'budget_scrutineer',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'return_with_observation',
                    'name' => 'Mark Back with Budget Observation',
                    'from' => ['department_endorsed', 'budget_scrutinized'],
                    'to' => 'returned_observation',
                    'permission' => 'procurement.scrutiny',
                    'color' => 'danger',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'requisition_author',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'board_sanction',
                    'name' => 'Grant Executive Board Approval',
                    'from' => ['budget_scrutinized'],
                    'to' => 'board_sanctioned',
                    'permission' => 'procurement.sanction',
                    'color' => 'purple',
                    'requires_remarks' => true,
                    'requires_attachment' => true,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'executive_board',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'issue_purchase_order',
                    'name' => 'Generate & Dispatch Purchase Order',
                    'from' => ['board_sanctioned'],
                    'to' => 'po_generated',
                    'permission' => 'procurement.issue',
                    'color' => 'success',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                ],
            ],
            'roles' => [
                [
                    'slug' => 'requisition_author',
                    'name' => 'Requisition Author',
                    'description' => 'Drafts procurement requests and responds to observations',
                    'permissions' => ['procurement.view', 'procurement.create'],
                ],
                [
                    'slug' => 'department_director',
                    'name' => 'Department Director',
                    'description' => 'Endorses departmental requisitions before financial submission',
                    'permissions' => ['procurement.view', 'procurement.endorse'],
                ],
                [
                    'slug' => 'budget_scrutineer',
                    'name' => 'Budget Scrutineer',
                    'description' => 'Verifies ledger codes, availability of funds, and compliance',
                    'permissions' => ['procurement.view', 'procurement.scrutiny'],
                ],
                [
                    'slug' => 'executive_board',
                    'name' => 'Executive Sanctioning Authority',
                    'description' => 'Grants final corporate financial approval for capital purchases',
                    'permissions' => ['procurement.view', 'procurement.sanction', 'procurement.issue'],
                ],
            ],
        ];
    }

    public static function seed(): Workflow
    {
        return app(WorkflowEngineService::class)->syncWorkflowFromManifest(self::definition(), 'Requisitions');
    }
}
