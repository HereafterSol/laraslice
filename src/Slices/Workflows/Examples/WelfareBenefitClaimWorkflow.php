<?php

namespace LaraSlice\Slices\Workflows\Examples;

use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

class WelfareBenefitClaimWorkflow
{
    public static function definition(): array
    {
        return [
            'slug' => 'welfare_claims',
            'name' => 'WWF Welfare Grant Scrutiny & Disbursement Pipeline',
            'domain' => 'Welfare & Grants',
            'entity_model' => 'App\Slices\Grants\Claims\Models\BenefitClaim',
            'enabled' => true,
            'states' => [
                [
                    'slug' => 'submitted',
                    'label' => 'Submitted / Desk Receipt',
                    'color' => 'slate',
                    'icon' => 'file-text',
                    'initial' => true,
                    'sla_hours' => 24,
                ],
                [
                    'slug' => 'clerk_verified',
                    'label' => 'Clerk Documentation Verified',
                    'color' => 'sky',
                    'icon' => 'check-circle',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'dy_director_endorsed',
                    'label' => 'Deputy Director Endorsed',
                    'color' => 'indigo',
                    'icon' => 'user-check',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'director_approved',
                    'label' => 'Director Approved',
                    'color' => 'purple',
                    'icon' => 'award',
                    'sla_hours' => 72,
                ],
                [
                    'slug' => 'committee_cleared',
                    'label' => 'Scrutiny Committee Cleared',
                    'color' => 'amber',
                    'icon' => 'users',
                    'sla_hours' => 96,
                ],
                [
                    'slug' => 'audit_passed',
                    'label' => 'Pre-Audit Clearance Given',
                    'color' => 'sky',
                    'icon' => 'shield-check',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'disbursed',
                    'label' => 'Funds Disbursed to Bank',
                    'color' => 'emerald',
                    'icon' => 'dollar-sign',
                    'terminal' => true,
                ],
                [
                    'slug' => 'deficient_returned',
                    'label' => 'Deficient / Returned to Applicant',
                    'color' => 'rose',
                    'icon' => 'alert-triangle',
                ],
                [
                    'slug' => 'rejected',
                    'label' => 'Rejected / Ineligible',
                    'color' => 'red',
                    'icon' => 'x-circle',
                    'terminal' => true,
                ],
            ],
            'transitions' => [
                [
                    'slug' => 'clerk_verify',
                    'name' => 'Verify Physical Dossier',
                    'from' => ['submitted', 'deficient_returned'],
                    'to' => 'clerk_verified',
                    'permission' => 'claims.clerk_verify',
                    'color' => 'primary',
                    'routing' => [
                        'type' => 'role',
                        'target_role' => 'deputy_director',
                        'target_department' => 'Grants Directorate',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'mark_deficient',
                    'name' => 'Return for Missing Documents',
                    'from' => ['submitted', 'clerk_verified', 'dy_director_endorsed'],
                    'to' => 'deficient_returned',
                    'permission' => 'claims.scrutiny',
                    'requires_remarks' => true,
                    'color' => 'warning',
                    'routing' => [
                        'type' => 'dynamic_field',
                        'user_field' => 'applicant_user_id',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'dy_director_endorse',
                    'name' => 'Deputy Director Endorsement',
                    'from' => ['clerk_verified'],
                    'to' => 'dy_director_endorsed',
                    'permission' => 'claims.dy_director',
                    'requires_remarks' => true,
                    'color' => 'primary',
                    'routing' => [
                        'type' => 'role',
                        'target_role' => 'director',
                        'target_department' => 'Grants Directorate',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'director_approve',
                    'name' => 'Director Approval',
                    'from' => ['dy_director_endorsed'],
                    'to' => 'director_approved',
                    'permission' => 'claims.director_approve',
                    'requires_remarks' => true,
                    'color' => 'primary',
                    'routing' => [
                        'type' => 'department',
                        'target_department' => 'Independent Scrutiny Committee',
                        'target_role' => 'committee_secretary',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'committee_clear',
                    'name' => 'Committee Ratification',
                    'from' => ['director_approved'],
                    'to' => 'committee_cleared',
                    'permission' => 'claims.committee_clear',
                    'requires_remarks' => true,
                    'color' => 'primary',
                    'routing' => [
                        'type' => 'department',
                        'target_department' => 'Pre-Audit Wing',
                        'target_role' => 'audit_officer',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'audit_clear',
                    'name' => 'Pre-Audit Greenlight',
                    'from' => ['committee_cleared'],
                    'to' => 'audit_passed',
                    'permission' => 'claims.audit_clear',
                    'requires_remarks' => true,
                    'color' => 'primary',
                    'routing' => [
                        'type' => 'department',
                        'target_department' => 'Finance & Accounts',
                        'target_role' => 'finance_officer',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'disburse_payment',
                    'name' => 'Disburse EFT / Cheque',
                    'from' => ['audit_passed'],
                    'to' => 'disbursed',
                    'permission' => 'claims.disburse',
                    'requires_remarks' => true,
                    'color' => 'success',
                ],
                [
                    'slug' => 'reject',
                    'name' => 'Final Rejection',
                    'from' => ['submitted', 'clerk_verified', 'dy_director_endorsed', 'director_approved'],
                    'to' => 'rejected',
                    'permission' => 'claims.director_approve',
                    'requires_remarks' => true,
                    'color' => 'danger',
                ],
            ],
        ];
    }

    public static function seed(): Workflow
    {
        return app(WorkflowEngineService::class)->syncWorkflowFromManifest(
            static::definition(),
            'Claims',
            'App\Slices\Grants\Claims\Models\BenefitClaim'
        );
    }
}