<?php

namespace LaraSlice\Slices\Workflows\Examples;

use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

class GrantDisbursementWorkflow
{
    public static function definition(): array
    {
        return [
            'slug' => 'grant_disbursement',
            'name' => 'Social Grant & Capital Disbursement Pipeline',
            'domain' => 'Grants & Subsidies',
            'entity_model' => 'App\Slices\Grants\Applications\Models\GrantApplication',
            'enabled' => true,
            'states' => [
                [
                    'slug' => 'submitted',
                    'label' => 'Submitted / Desk Intake',
                    'color' => 'slate',
                    'icon' => 'file-text',
                    'initial' => true,
                    'sla_hours' => 24,
                ],
                [
                    'slug' => 'eligibility_verified',
                    'label' => 'Eligibility Verified',
                    'color' => 'sky',
                    'icon' => 'check-square',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'committee_sanctioned',
                    'label' => 'Committee Sanction Approved',
                    'color' => 'purple',
                    'icon' => 'users',
                    'sla_hours' => 72,
                ],
                [
                    'slug' => 'pre_audit_cleared',
                    'label' => 'Pre-Audit Passed',
                    'color' => 'amber',
                    'icon' => 'shield-check',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'fund_disbursed',
                    'label' => 'Bank Fund Disbursed',
                    'color' => 'emerald',
                    'icon' => 'credit-card',
                    'terminal' => true,
                ],
                [
                    'slug' => 'rejected',
                    'label' => 'Application Rejected',
                    'color' => 'rose',
                    'icon' => 'x-circle',
                    'terminal' => true,
                ],
            ],
            'transitions' => [
                [
                    'slug' => 'verify_eligibility',
                    'name' => 'Verify Applicant Eligibility',
                    'from' => ['submitted'],
                    'to' => 'eligibility_verified',
                    'permission' => 'grant.scrutiny',
                    'color' => 'primary',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'eligibility_scrutineer',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'reject_intake',
                    'name' => 'Reject for Non-Compliance',
                    'from' => ['submitted'],
                    'to' => 'rejected',
                    'permission' => 'grant.scrutiny',
                    'color' => 'danger',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                ],
                [
                    'slug' => 'submit_committee_batch',
                    'name' => 'Submit to Grants Committee',
                    'from' => ['eligibility_verified'],
                    'to' => 'committee_sanctioned',
                    'permission' => 'grant.committee',
                    'color' => 'purple',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'committee_secretary',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'clear_pre_audit',
                    'name' => 'Pre-Audit Regulatory Clearance',
                    'from' => ['committee_sanctioned'],
                    'to' => 'pre_audit_cleared',
                    'permission' => 'grant.audit',
                    'color' => 'amber',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'audit_controller',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'release_disbursement',
                    'name' => 'Authorize Bank Release & Disburse',
                    'from' => ['pre_audit_cleared'],
                    'to' => 'fund_disbursed',
                    'permission' => 'grant.disburse',
                    'color' => 'success',
                    'requires_remarks' => true,
                    'requires_attachment' => true,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'disbursement_treasurer',
                        'notify' => true,
                    ],
                ],
            ],
            'roles' => [
                [
                    'slug' => 'intake_officer',
                    'name' => 'Intake Verification Officer',
                    'description' => 'Verifies applicant identity and completeness of documentation',
                    'permissions' => ['grant.view', 'grant.intake'],
                ],
                [
                    'slug' => 'eligibility_scrutineer',
                    'name' => 'Eligibility Scrutiny Officer',
                    'description' => 'Calculates quota eligibility and applies verification rules',
                    'permissions' => ['grant.view', 'grant.scrutiny'],
                ],
                [
                    'slug' => 'committee_secretary',
                    'name' => 'Grants Committee Secretary',
                    'description' => 'Records committee consensus and sanctions grant batches',
                    'permissions' => ['grant.view', 'grant.committee'],
                ],
                [
                    'slug' => 'audit_controller',
                    'name' => 'Pre-Audit Controller',
                    'description' => 'Ensures statutory compliance and voucher authorization',
                    'permissions' => ['grant.view', 'grant.audit'],
                ],
                [
                    'slug' => 'disbursement_treasurer',
                    'name' => 'Disbursement Treasurer',
                    'description' => 'Transmits payment instructions and reconciles banking records',
                    'permissions' => ['grant.view', 'grant.disburse'],
                ],
            ],
        ];
    }

    public static function seed(): Workflow
    {
        return app(WorkflowEngineService::class)->syncWorkflowFromManifest(self::definition(), 'Requisitions');
    }
}
