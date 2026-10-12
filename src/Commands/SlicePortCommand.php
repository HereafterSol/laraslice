<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Porting\SlicePortService;

/**
 * Enterprise Monolith Reverse-Engineering & Legacy System Porting Command.
 *
 * Introspects legacy Laravel schemas, classifies tables into domain-bounded vertical slices,
 * generates canonical YAML blueprints, extracts taxonomy seeders, and migrates operational data + files.
 */
class SlicePortCommand extends Command
{
    protected $signature = 'slice:port
                            {source? : Path to legacy Laravel project root or database}
                            {--domain= : Target Domain Group (default: Adjudication)}
                            {--mode= : Porting mode: system-base (taxonomies & dropdown seeders) or full-data (historical data migration)}
                            {--with-files : Copy physical file attachments from storage/app/public}
                            {--dry-run : Preview table-to-slice mapping without writing files}';

    protected $description = 'Reverse-engineer and port a legacy Laravel application into domain-bounded LaraSlice vertical slices';

    public function handle(SlicePortService $portService): int
    {
        $this->info('====================================================');
        $this->info('   LaraSlice Enterprise Legacy Porting Engine       ');
        $this->info('====================================================');

        $source = $this->argument('source');
        if (! $source) {
            $source = $this->ask('Enter path to legacy Laravel project root (e.g. e:/laragon/www/fospha-complaint-managment or .):', '.');
        }

        $domain = $this->option('domain');
        if (! $domain) {
            $domain = $this->ask('Enter target Domain name (e.g. Adjudication, LegalCompliance, CRM):', 'Adjudication');
        }

        $mode = $this->option('mode');
        if (! $mode) {
            $modeChoice = $this->choice(
                'Select Data Porting Mode:',
                [
                    'system-base' => 'System Base Only (Extract master taxonomies, 24 statuses, categories & seeders - 0 blank dropdowns)',
                    'full-data' => 'Full Historical Data Migration (Port all petitions, hearings, notices, approvals + file attachments)',
                ],
                'system-base'
            );
            $mode = ($modeChoice === 'System Base Only (Extract master taxonomies, 24 statuses, categories & seeders - 0 blank dropdowns)') ? 'system-base' : 'full-data';
        }

        $withFiles = (bool) $this->option('with-files');
        if ($mode === 'full-data' && ! $this->option('with-files')) {
            $withFiles = $this->confirm('Do you want to copy physical file attachments from legacy storage/app/public?', true);
        }

        $this->line('');
        $this->comment("🔍 Introspecting legacy schema at: {$source}...");

        try {
            $inspection = $portService->inspect($source, $domain);
        } catch (\Throwable $e) {
            $this->error("Inspection failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("✓ Discovered {$inspection['total_tables']} total database tables.");
        $this->line('');

        // Render Table-to-Slice Mapping Summary
        $rows = [];
        foreach ($inspection['slice_mapping'] as $sName => $info) {
            $children = empty($info['child_tables']) ? 'None' : implode(', ', $info['child_tables']);
            $rows[] = [$sName, $info['type'], $info['primary_table'], $children];
        }

        $this->table(['Target Slice', 'Architecture Type', 'Primary Table', 'Attached Child / Pivot Tables'], $rows);

        if ($this->option('dry-run')) {
            $this->warn('Dry-run mode: skipping blueprint and seeder generation.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Proceed with porting and blueprint generation?', true)) {
            $this->info('Porting aborted by user.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->comment("⚙️  Synthesizing domain blueprint & {$mode} seeders...");

        try {
            $result = $portService->execute($source, $domain, $mode, $withFiles);
        } catch (\Throwable $e) {
            $this->error("Porting execution failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("✓ Domain blueprint synthesized: {$result['domain_blueprint']}");
        $this->info("✓ Vertical Slices mapped: " . implode(', ', $result['slices_generated']));

        if (! empty($result['seeders_generated'])) {
            $this->info("✓ Master seeders generated: " . count($result['seeders_generated']) . " file(s)");
        }

        if ($withFiles) {
            $this->info("✓ File assets migrated: {$result['files_migrated']} file(s) ({$result['bytes_migrated']} bytes)");
        }

        $this->line('');
        $this->info('🎉 Porting analysis & blueprint generation completed successfully!');
        $this->info("You can now open http://localhost:7000/laraslice/wizard/blueprint to view and edit this suite visually.");

        return self::SUCCESS;
    }
}
