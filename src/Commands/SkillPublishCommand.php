<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;

class SkillPublishCommand extends Command
{
    protected $signature = 'laraslice:skill:publish
                            {--force : Overwrite existing skill and rules files}';

    protected $description = 'Publish LaraSlice AI Skill, Cursor Rules, and MCP configuration for AI coding assistants';

    public function handle(): int
    {
        $this->info('🚀 Publishing LaraSlice AI Skills and MCP configuration...');

        // 1. Locate source SKILL.md
        $sourceSkill = dirname(__DIR__, 2).'/skills/laraslice/SKILL.md';

        // 2. Publish to .agents/skills/laraslice/SKILL.md
        $agentsDir = base_path('.agents/skills/laraslice');
        if (! is_dir($agentsDir)) {
            mkdir($agentsDir, 0755, true);
        }
        $targetSkill = $agentsDir.'/SKILL.md';

        if (file_exists($sourceSkill)) {
            copy($sourceSkill, $targetSkill);
            $this->line('✅ Published Agent Skill: <comment>.agents/skills/laraslice/SKILL.md</comment>');
        }

        // 3. Publish to .cursor/rules/laraslice.mdc
        $cursorRulesDir = base_path('.cursor/rules');
        if (! is_dir($cursorRulesDir)) {
            mkdir($cursorRulesDir, 0755, true);
        }
        $cursorRuleFile = $cursorRulesDir.'/laraslice.mdc';
        $cursorRuleContent = <<<'MDC'
---
description: LaraSlice Vertical Slice Architecture standards, CLI commands, and MCP tools
globs: app/Slices/**/*, src/Slices/**/*
alwaysApply: true
---

# LaraSlice Enterprise Architecture Guidelines
- Follow Vertical Slice Architecture: Slices encapsulate their own Domain, Actions, Models, and Views.
- Cross-slice communication must use explicit Contracts or Events. Never tightly couple slice repositories.
- Use Declarative Blueprints for schema evolution: `php artisan slice:blueprint:plan` and `slice:blueprint:apply`.
- Use Slice Studio (`/laraslice/wizard`) or CLI commands:
  - `php artisan slice:make <Slice>`
  - `php artisan slice:toggle <Slice>`
  - `php artisan slice:wipe <Slice>`
  - `php artisan slice:seed <Slice>`
  - `php artisan slice:destroy <Slice>`
  - `php artisan laraslice:audit:prune`
MDC;
        file_put_contents($cursorRuleFile, $cursorRuleContent);
        $this->line('✅ Published Cursor Rule: <comment>.cursor/rules/laraslice.mdc</comment>');

        // 4. Publish .cursor/mcp.json
        $cursorMcpFile = base_path('.cursor/mcp.json');
        $mcpConfig = [
            'mcpServers' => [
                'laraslice' => [
                    'command' => 'php',
                    'args' => ['artisan', 'laraslice:mcp'],
                ],
            ],
        ];
        file_put_contents($cursorMcpFile, json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('✅ Configured Cursor MCP Server: <comment>.cursor/mcp.json</comment>');

        $this->newLine();
        $this->info('🎉 LaraSlice AI integration ready! Compatible with Antigravity, Cursor, and Laravel Boost.');

        return Command::SUCCESS;
    }
}
