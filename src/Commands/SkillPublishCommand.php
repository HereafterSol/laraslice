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

        // 1+2. Publish the whole skill family: every skills/<name>/
        // directory (SKILL.md + examples/ + references/) lands in
        // .agents/skills/<name>/, the layout agent runtimes and
        // skills.sh discover. The core `laraslice` skill keeps its
        // historic path, so existing installs keep working.
        $skillsRoot = dirname(__DIR__, 2).'/skills';
        foreach (glob($skillsRoot.'/*', GLOB_ONLYDIR) ?: [] as $skillDir) {
            $name = basename($skillDir);
            $sourceSkill = $skillDir.'/SKILL.md';
            if (! file_exists($sourceSkill)) {
                continue;
            }
            $targetDir = base_path('.agents/skills/'.$name);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($skillDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $item) {
                $dest = $targetDir.'/'.str_replace('\\', '/', $iterator->getSubPathname());
                if ($item->isDir()) {
                    if (! is_dir($dest)) {
                        mkdir($dest, 0755, true);
                    }
                } else {
                    copy($item->getPathname(), $dest);
                }
            }
            $this->line("✅ Published Agent Skill: <comment>.agents/skills/{$name}/SKILL.md</comment>");
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
