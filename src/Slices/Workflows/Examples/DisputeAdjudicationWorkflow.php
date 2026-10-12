<?php

namespace LaraSlice\Slices\Workflows\Examples;

use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

class DisputeAdjudicationWorkflow
{
    public static function definition(): array
    {
        return [
            'slug' => 'dispute_adjudication',
            'name' => 'Legal Grievance & Dispute Adjudication Pipeline',
            'domain' => 'Legal & Compliance',
            'entity_model' => 'App\Slices\Legal\Disputes\Models\Dispute',
            'enabled' => true,
            'states' => [
                [
                    'slug' => 'lodged',
                    'label' => 'Lodged / Fresh Scrutiny',
                    'color' => 'slate',
                    'icon' => 'inbox',
                    'initial' => true,
                ],
                [
                    'slug' => 'preliminary_hearing',
                    'label' => 'Preliminary Hearing Scheduled',
                    'color' => 'amber',
                    'icon' => 'calendar',
                ],
                [
                    'slug' => 'under_investigation',
                    'label' => 'Under Investigation / Evidence',
                    'color' => 'indigo',
                    'icon' => 'search',
                ],
                [
                    'slug' => 'findings_submitted',
                    'label' => 'Inquiry Findings Submitted',
                    'color' => 'sky',
                    'icon' => 'file-text',
                ],
                [
                    'slug' => 'adjudication_hearing',
                    'label' => 'Adjudication Hearing & Arguments',
                    'color' => 'purple',
                    'icon' => 'scale',
                ],
                [
                    'slug' => 'speaking_order_issued',
                    'label' => 'Final Speaking Order Pronounced',
                    'color' => 'emerald',
                    'icon' => 'award',
                    'terminal' => true,
                ],
                [
                    'slug' => 'dismissed',
                    'label' => 'Dismissed / Inadmissible',
                    'color' => 'rose',
                    'icon' => 'x-circle',
                    'terminal' => true,
                ],
            ],
            'transitions' => [
                [
                    'slug' => 'admit_for_hearing',
                    'name' => 'Admit Petition & Schedule Hearing',
                    'from' => ['lodged'],
                    'to' => 'preliminary_hearing',
                    'permission' => 'dispute.scrutiny',
                    'color' => 'primary',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'hearing_examiner',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'dismiss_at_scrutiny',
                    'name' => 'Dismiss on Maintainability Grounds',
                    'from' => ['lodged'],
                    'to' => 'dismissed',
                    'permission' => 'dispute.scrutiny',
                    'color' => 'danger',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                ],
                [
                    'slug' => 'commence_investigation',
                    'name' => 'Refer to Investigation Officer',
                    'from' => ['preliminary_hearing'],
                    'to' => 'under_investigation',
                    'permission' => 'dispute.hear',
                    'color' => 'indigo',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'investigation_officer',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'submit_inquiry_report',
                    'name' => 'Submit Findings & Evidence Report',
                    'from' => ['under_investigation'],
                    'to' => 'findings_submitted',
                    'permission' => 'dispute.investigate',
                    'color' => 'info',
                    'requires_remarks' => true,
                    'requires_attachment' => true,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'hearing_examiner',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'schedule_final_arguments',
                    'name' => 'Schedule Final Adjudication Arguments',
                    'from' => ['findings_submitted'],
                    'to' => 'adjudication_hearing',
                    'permission' => 'dispute.hear',
                    'color' => 'purple',
                    'requires_remarks' => true,
                    'requires_attachment' => false,
                    'routing' => [
                        'type' => 'role_direct',
                        'target_role' => 'chief_adjudicator',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'pronounce_final_order',
                    'name' => 'Pronounce Final Speaking Order',
                    'from' => ['adjudication_hearing'],
                    'to' => 'speaking_order_issued',
                    'permission' => 'dispute.decide',
                    'color' => 'success',
                    'requires_remarks' => true,
                    'requires_attachment' => true,
                ],
            ],
            'roles' => [
                [
                    'slug' => 'scrutiny_officer',
                    'name' => 'Scrutiny Officer',
                    'description' => 'Examines legal maintainability and jurisdictional requirements',
                    'permissions' => ['dispute.view', 'dispute.scrutiny'],
                ],
                [
                    'slug' => 'hearing_examiner',
                    'name' => 'Hearing Examiner',
                    'description' => 'Conducts formal hearings and records party submissions',
                    'permissions' => ['dispute.view', 'dispute.hear'],
                ],
                [
                    'slug' => 'investigation_officer',
                    'name' => 'Investigation Officer',
                    'description' => 'Gathers factual evidence and prepares inquiry reports',
                    'permissions' => ['dispute.view', 'dispute.investigate'],
                ],
                [
                    'slug' => 'chief_adjudicator',
                    'name' => 'Chief Adjudicator',
                    'description' => 'Pronounces enforceable administrative orders and final rulings',
                    'permissions' => ['dispute.view', 'dispute.decide'],
                ],
            ],
        ];
    }

    public static function seed(): Workflow
    {
        $def = self::definition();
        $model = class_exists('App\Slices\LegalCompliance\Disputes\Models\Dispute')
            ? 'App\Slices\LegalCompliance\Disputes\Models\Dispute'
            : 'App\Slices\Legal\Disputes\Models\Dispute';

        $workflow = app(WorkflowEngineService::class)->syncWorkflowFromManifest(
            $def,
            'Disputes',
            $model
        );

        if (! empty($def['roles'])) {
            (new \LaraSlice\Core\Security\SliceRoleProvisioner)->provision(
                $def['roles'],
                'Disputes',
                'Legal & Compliance',
                true
            );
        }

        return $workflow;
    }
}
