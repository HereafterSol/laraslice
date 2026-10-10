<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Slices\Workflows\Services\SlaEscalationManager;

class WorkflowSlaCheckCommand extends Command
{
    protected $signature = 'workflow:sla-check {--notify : Dispatch notifications to assigned supervisors}';

    protected $description = 'Scan active entities across all workflows for SLA breaches and escalations';

    public function handle(SlaEscalationManager $manager): int
    {
        $this->info("Scanning active workflow entities for SLA thresholds...");

        $breached = $manager->scanBreachedEntities();

        if (empty($breached)) {
            $this->info("✓ All active workflows are operating within SLA deadlines. No breaches detected.");
            return 0;
        }

        $this->warn("⚠ Found ".count($breached)." SLA breach(es) requiring attention!");

        $rows = array_map(function ($b) {
            return [
                $b['workflow'],
                class_basename($b['entity_type']).' #'.$b['entity_id'],
                $b['state'],
                $b['sla_hours'].'h',
                $b['breached_by_hours'].'h overdue',
            ];
        }, $breached);

        $this->table(['Workflow', 'Entity', 'Current State', 'SLA Limit', 'Breach Time'], $rows);

        return 0;
    }
}