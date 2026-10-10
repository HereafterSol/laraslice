<?php

namespace LaraSlice\Slices\Workflows\Examples;

use LaraSlice\Slices\Workflows\Models\Workflow;
use LaraSlice\Slices\Workflows\Services\WorkflowEngineService;

class LegalComplaintWorkflow
{
    public static function definition(): array
    {
        return [
            'slug' => 'legal_complaints',
            'name' => 'FOSPAH Legal Scrutiny & Ombudsman Adjudication',
            'domain' => 'Legal & Grievance',
            'entity_model' => 'App\Slices\Legal\Complaints\Models\Complaint',
            'enabled' => true,
            'states' => [
                [
                    'slug' => 'lodged',
                    'label' => 'Lodged / Fresh Scrutiny',
                    'color' => 'slate',
                    'icon' => 'inbox',
                    'initial' => true,
                    'sla_hours' => 24,
                ],
                [
                    'slug' => 'preliminary_hearing',
                    'label' => 'Preliminary Hearing Scheduled',
                    'color' => 'amber',
                    'icon' => 'calendar',
                    'sla_hours' => 72,
                ],
                [
                    'slug' => 'under_investigation',
                    'label' => 'Under Investigation / Evidence',
                    'color' => 'indigo',
                    'icon' => 'search',
                    'sla_hours' => 120,
                ],
                [
                    'slug' => 'findings_submitted',
                    'label' => 'Inquiry Findings Submitted',
                    'color' => 'sky',
                    'icon' => 'file-text',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'dg_reviewed',
                    'label' => 'DG Endorsement Completed',
                    'color' => 'purple',
                    'icon' => 'shield-check',
                    'sla_hours' => 48,
                ],
                [
                    'slug' => 'ombudsman_adjudicated',
                    'label' => 'Decided by Federal Ombudsman',
                    'color' => 'emerald',
                    'icon' => 'award',
                    'terminal' => true,
                ],
                [
                    'slug' => 'dismissed',
                    'label' => 'Dismissed at Scrutiny',
                    'color' => 'rose',
                    'icon' => 'x-circle',
                    'terminal' => true,
                ],
            ],
            'transitions' => [
                [
                    'slug' => 'registrar_scrutiny_pass',
                    'name' => 'Pass Scrutiny & Schedule Hearing',
                    'from' => ['lodged'],
                    'to' => 'preliminary_hearing',
                    'permission' => 'complaints.scrutiny',
                    'requires_remarks' => true,
                    'color' => 'primary',
                    'routing' => [
                        'type' => 'role',
                        'target_role' => 'hearing_officer',
                        'target_department' => 'Registrar Section',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'registrar_dismiss',
                    'name' => 'Dismiss for Want of Jurisdiction',
                    'from' => ['lodged'],
                    'to' => 'dismissed',
                    'permission' => 'complaints.scrutiny',
                    'requires_remarks' => true,
                    'color' => 'danger',
                ],
                [
                    'slug' => 'refer_to_investigation',
                    'name' => 'Refer to Investigation Officer',
                    'from' => ['preliminary_hearing'],
                    'to' => 'under_investigation',
                    'permission' => 'complaints.hearing',
                    'requires_remarks' => true,
                    'color' => 'primary',
                    'routing' => [
                        'type' => 'role',
                        'target_role' => 'investigation_officer',
                        'target_department' => 'Investigation Wing',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'submit_inquiry_report',
                    'name' => 'Submit Formal Inquiry Findings',
                    'from' => ['under_investigation'],
                    'to' => 'findings_submitted',
                    'permission' => 'complaints.investigate',
                    'requires_remarks' => true,
                    'requires_attachment' => true,
                    'color' => 'primary',
                    'routing' => [
                        'type' => 'role',
                        'target_role' => 'director_general',
                        'target_department' => 'Executive Office',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'dg_endorse',
                    'name' => 'DG Endorse & Submit to Ombudsman',
                    'from' => ['findings_submitted'],
                    'to' => 'dg_reviewed',
                    'permission' => 'complaints.dg_endorse',
                    'requires_remarks' => true,
                    'color' => 'primary',
                    'routing' => [
                        'type' => 'role',
                        'target_role' => 'ombudsman',
                        'target_department' => 'Ombudsman Chamber',
                        'notify' => true,
                    ],
                ],
                [
                    'slug' => 'ombudsman_final_order',
                    'name' => 'Pass Final Speaking Order',
                    'from' => ['dg_reviewed'],
                    'to' => 'ombudsman_adjudicated',
                    'permission' => 'complaints.ombudsman_decide',
                    'requires_remarks' => true,
                    'requires_attachment' => true,
                    'color' => 'success',
                ],
            ],
        ];
    }

    public static function seed(): Workflow
    {
        return app(WorkflowEngineService::class)->syncWorkflowFromManifest(
            static::definition(),
            'Complaints',
            'App\Slices\Legal\Complaints\Models\Complaint'
        );
    }
}