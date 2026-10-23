<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Unit\Support;

use LaraSlice\Support\SystemOperate;
use PHPUnit\Framework\TestCase;

class SystemOperateTest extends TestCase
{
    public function test_manual_is_assembled_from_the_mind(): void
    {
        $md = SystemOperate::manualMarkdown([
            'system' => ['name' => 'Ops ERP', 'purpose' => 'Run the work.'],
            'actors' => [['name' => 'Admin', 'kind' => 'internal', 'description' => 'Runs it.']],
            'roles' => ['clubs' => [['name' => 'Admin', 'permissions' => ['hr.*']]], 'overrides' => 'Per-person grants apply.'],
            'journeys' => [['name' => 'Deliver', 'actor' => 'Admin', 'steps' => ['Plan', 'Ship'], 'outcome' => 'Paid.']],
            'operations' => ['backups' => 'daily'],
        ]);
        $this->assertStringContainsString('# Ops ERP — Operator Manual', $md);
        $this->assertStringContainsString('**Admin**', $md);
        $this->assertStringContainsString('Plan → Ship', $md);
        $this->assertStringContainsString('backups', $md);
    }
}
