<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;

class SliceSeederService
{
    protected SliceManager $manager;

    public function __construct(?SliceManager $manager = null)
    {
        $this->manager = $manager ?: app(SliceManager::class);
    }

    /**
     * Seed a single slice with realistic mock data.
     */
    public function seedSlice(string $sliceName, int $count = 10): array
    {
        $this->manager->discover();
        $slice = $this->manager->getSlice($sliceName);
        if (! $slice) {
            foreach ($this->manager->getAllSlices() as $s) {
                if (strtolower($s->name) === strtolower($sliceName) || strtolower($s->title) === strtolower($sliceName)) {
                    $slice = $s;
                    break;
                }
            }
        }

        if (! $slice) {
            throw new \InvalidArgumentException("Slice [{$sliceName}] not found.");
        }

        // 1. Check for dedicated Seeder class in slice directory
        $customSeederClasses = [
            $slice->namespace.'\\Database\\Seeders\\'.Str::studly(Str::singular($slice->name)).'Seeder',
            $slice->namespace.'\\Database\\Seeders\\'.Str::studly($slice->name).'Seeder',
            $slice->namespace.'\\Seeders\\'.Str::studly($slice->name).'Seeder',
        ];

        foreach ($customSeederClasses as $seederCls) {
            if (class_exists($seederCls)) {
                $instance = new $seederCls;
                if (method_exists($instance, 'run')) {
                    $instance->run();

                    return [
                        'success' => true,
                        'slice' => $slice->name,
                        'message' => "Executed custom seeder [{$seederCls}].",
                        'tables' => $slice->tables,
                        'count' => $count,
                    ];
                }
            }
        }

        // 2. Dynamic schema-driven seeding
        $tables = $this->resolveSliceTablesInOrder($slice);
        $seededInfo = [];

        $this->disableForeignKeyConstraints();
        try {
            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $createdCount = $this->seedTable($table, $slice, $count);
                $seededInfo[$table] = $createdCount;
            }
        } finally {
            $this->enableForeignKeyConstraints();
        }

        return [
            'success' => true,
            'slice' => $slice->name,
            'message' => "Successfully seeded demo data for [{$slice->name}].",
            'tables' => $seededInfo,
            'count' => array_sum($seededInfo),
        ];
    }

    /**
     * Seed an entire domain in relational parent-first order.
     */
    public function seedDomain(string $domainName, int $count = 10): array
    {
        $this->manager->discover();
        $slices = [];
        foreach ($this->manager->getAllSlices() as $s) {
            $sliceDomain = $s->domain ?? $s->navigation['group'] ?? $s->raw['domain'] ?? null;
            if (strtolower(trim((string) $sliceDomain)) === strtolower(trim($domainName))) {
                $slices[] = $s;
            }
        }

        if (empty($slices)) {
            throw new \InvalidArgumentException("No slices found in domain [{$domainName}].");
        }

        // Sort slices: parent entities first (e.g. Companies before Contacts, Categories before Products)
        usort($slices, function (SliceManifest $a, SliceManifest $b) {
            $aName = strtolower($a->name);
            $bName = strtolower($b->name);
            if (str_contains($aName, 'compan') || str_contains($aName, 'category')) {
                return -1;
            }
            if (str_contains($bName, 'compan') || str_contains($bName, 'category')) {
                return 1;
            }
            if (str_contains($aName, 'contact') || str_contains($aName, 'product')) {
                return -1;
            }
            if (str_contains($bName, 'contact') || str_contains($bName, 'product')) {
                return 1;
            }

            return 0;
        });

        $results = [];
        $totalSeeded = 0;

        $this->disableForeignKeyConstraints();
        try {
            foreach ($slices as $slice) {
                try {
                    $res = $this->seedSlice($slice->name, $count);
                    $results[$slice->name] = $res['tables'] ?? [];
                    $totalSeeded += $res['count'] ?? 0;
                } catch (\Throwable $e) {
                    $results[$slice->name] = ['error' => $e->getMessage()];
                }
            }
        } finally {
            $this->enableForeignKeyConstraints();
        }

        return [
            'success' => true,
            'domain' => $domainName,
            'message' => "Domain [{$domainName}] successfully seeded ({$totalSeeded} records generated across ".count($slices).' slices).',
            'slices' => $results,
            'totalSeeded' => $totalSeeded,
        ];
    }

    /**
     * Wipe (truncate) records for a single slice.
     */
    public function wipeSlice(string $sliceName): array
    {
        $this->manager->discover();
        $slice = $this->manager->getSlice($sliceName);
        if (! $slice) {
            foreach ($this->manager->getAllSlices() as $s) {
                if (strtolower($s->name) === strtolower($sliceName)) {
                    $slice = $s;
                    break;
                }
            }
        }

        if (! $slice) {
            throw new \InvalidArgumentException("Slice [{$sliceName}] not found.");
        }

        if ($this->manager->isProtectedSlice($slice)) {
            throw new \RuntimeException("Slice [{$slice->name}] is a core LaraSlice slice; its data cannot be wiped.");
        }

        $protected = $this->manager->protectedTables();
        $tables = array_values(array_diff(array_reverse($this->resolveSliceTablesInOrder($slice)), $protected));
        $wipedTables = [];

        $this->disableForeignKeyConstraints();
        try {
            foreach ($tables as $tbl) {
                if (Schema::hasTable($tbl)) {
                    DB::table($tbl)->truncate();
                    $wipedTables[] = $tbl;
                }
            }
        } finally {
            $this->enableForeignKeyConstraints();
        }

        return [
            'success' => true,
            'slice' => $slice->name,
            'message' => "Data wiped for slice [{$slice->name}].",
            'tables' => $wipedTables,
        ];
    }

    /**
     * Wipe all records for an entire domain.
     */
    public function wipeDomain(string $domainName): array
    {
        $this->manager->discover();
        $slices = [];
        foreach ($this->manager->getAllSlices() as $s) {
            $sliceDomain = $s->domain ?? $s->navigation['group'] ?? $s->raw['domain'] ?? null;
            if (strtolower(trim((string) $sliceDomain)) === strtolower(trim($domainName))) {
                $slices[] = $s;
            }
        }

        $allWiped = [];
        $protected = $this->manager->protectedTables();
        $this->disableForeignKeyConstraints();
        try {
            foreach ($slices as $slice) {
                if ($this->manager->isProtectedSlice($slice)) {
                    continue;
                }
                $tables = array_values(array_diff(array_reverse($this->resolveSliceTablesInOrder($slice)), $protected));
                foreach ($tables as $tbl) {
                    if (Schema::hasTable($tbl) && ! in_array($tbl, $allWiped, true)) {
                        DB::table($tbl)->truncate();
                        $allWiped[] = $tbl;
                    }
                }
            }
        } finally {
            $this->enableForeignKeyConstraints();
        }

        return [
            'success' => true,
            'domain' => $domainName,
            'message' => "Wiped all data in domain [{$domainName}] (".count($allWiped).' tables cleared).',
            'tables' => $allWiped,
        ];
    }

    /**
     * Seed a single table with rich, realistic mock records.
     */
    protected function seedTable(string $table, SliceManifest $slice, int $count = 10): int
    {
        $columns = Schema::getColumnListing($table);
        $toCreate = max(1, $count);

        $columnTypes = [];
        foreach ($columns as $col) {
            try {
                $columnTypes[$col] = Schema::getColumnType($table, $col);
            } catch (\Throwable) {
                $columnTypes[$col] = 'string';
            }
        }

        $records = [];
        for ($i = 0; $i < $toCreate; $i++) {
            $row = [];
            foreach ($columns as $col) {
                if (in_array($col, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }

                $type = $columnTypes[$col] ?? 'string';
                $row[$col] = $this->generateFieldValue($col, $type, $table, $i);
            }

            if (in_array('created_at', $columns, true)) {
                $row['created_at'] = now()->subMinutes(rand(10, 10000));
            }
            if (in_array('updated_at', $columns, true)) {
                $row['updated_at'] = now();
            }

            $records[] = $row;
        }

        if (! empty($records)) {
            DB::table($table)->insert($records);
        }

        return count($records);
    }

    /**
     * Generate context-aware, realistic value for a column.
     */
    protected function generateFieldValue(string $column, string $type, string $table, int $index): mixed
    {
        $colLower = strtolower($column);

        // 0. User stamps reference users.id: use a real user (deleted_by stays empty on live records)
        if (in_array($colLower, ['created_by', 'updated_by', 'deleted_by'], true)) {
            return $colLower === 'deleted_by' ? null : $this->resolveStampUserId();
        }

        // 1. Foreign keys: pick from existing parent records
        if (str_ends_with($colLower, '_id')) {
            $parentBase = substr($colLower, 0, -3);
            $candidateTables = [
                Str::plural($parentBase),
                $parentBase,
            ];

            foreach ($candidateTables as $parentTbl) {
                if ($parentTbl !== $table && Schema::hasTable($parentTbl)) {
                    $ids = DB::table($parentTbl)->pluck('id')->toArray();
                    if (! empty($ids)) {
                        return $ids[array_rand($ids)];
                    }
                }
            }

            return 1;
        }

        // 2. Realistic domain values based on column name & context
        $companyNames = [
            'Acme Corporation', 'Globex International', 'Initech Software', 'Pied Piper Tech',
            'Hooli Enterprise', 'Cyberdyne Systems', 'Stark Industries', 'Wayne Enterprises',
            'Massive Dynamic', 'Umbrella Solutions', 'Apex Financial', 'Nova Logistics',
            'Soylent Labs', 'Aperture Science', 'Oscorp Global', 'Tyrell Robotics',
        ];

        $firstNames = ['Alexander', 'Sarah', 'Michael', 'Emily', 'David', 'Jessica', 'James', 'Olivia', 'Daniel', 'Sophia', 'Benjamin', 'Mia'];
        $lastNames = ['Miller', 'Connor', 'Scott', 'Taylor', 'Anderson', 'Thomas', 'Jackson', 'White', 'Harris', 'Martin', 'Clark', 'Lewis'];
        $industries = ['Technology', 'Financial Services', 'Healthcare', 'Manufacturing', 'Logistics', 'Retail & E-Commerce', 'Clean Energy', 'Telecommunications'];
        $jobTitles = ['Chief Executive Officer', 'VP of Engineering', 'Director of Sales', 'Procurement Head', 'Lead Architect', 'Product Operations Manager', 'Senior Account Executive', 'Operations Director'];

        if (in_array($colLower, ['name', 'company_name']) && (str_contains($table, 'compan') || str_contains($table, 'client') || str_contains($table, 'account') || str_contains($table, 'vendor'))) {
            return $companyNames[$index % count($companyNames)];
        }

        if ($colLower === 'name' && (str_contains($table, 'contact') || str_contains($table, 'user') || str_contains($table, 'member') || str_contains($table, 'employee'))) {
            $fn = $firstNames[$index % count($firstNames)];
            $ln = $lastNames[($index + 3) % count($lastNames)];

            return "{$fn} {$ln}";
        }

        if ($colLower === 'first_name') {
            return $firstNames[$index % count($firstNames)];
        }

        if ($colLower === 'last_name') {
            return $lastNames[($index + 2) % count($lastNames)];
        }

        if ($colLower === 'email') {
            $fn = strtolower($firstNames[$index % count($firstNames)]);
            $ln = strtolower($lastNames[($index + 3) % count($lastNames)]);

            return "{$fn}.{$ln}".($index > 0 ? $index : '').'@example.com';
        }

        if (str_contains($colLower, 'phone')) {
            $suffix = sprintf('%04d', rand(1000, 9999));

            return '+1 (555) '.rand(200, 899)."-{$suffix}";
        }

        if (in_array($colLower, ['website', 'url', 'site_url'])) {
            $slug = Str::slug($companyNames[$index % count($companyNames)]);

            return "https://{$slug}.com";
        }

        if ($colLower === 'industry') {
            return $industries[$index % count($industries)];
        }

        if ($colLower === 'job_title' || $colLower === 'position' || $colLower === 'title' && str_contains($table, 'contact')) {
            return $jobTitles[$index % count($jobTitles)];
        }

        if ($colLower === 'status') {
            if (str_contains($table, 'compan')) {
                $statChoices = ['prospect', 'customer', 'prospect', 'customer', 'churned'];

                return $statChoices[$index % count($statChoices)];
            }
            if (str_contains($table, 'order')) {
                $orderChoices = ['pending', 'processing', 'completed', 'delivered'];

                return $orderChoices[$index % count($orderChoices)];
            }
            $generalChoices = ['active', 'draft', 'active', 'published'];

            return $generalChoices[$index % count($generalChoices)];
        }

        if ($colLower === 'annual_revenue' || $colLower === 'revenue') {
            $amounts = [750000.00, 1250000.00, 3400000.00, 8500000.00, 500000.00, 12000000.00];

            return $amounts[$index % count($amounts)];
        }

        if (in_array($colLower, ['amount', 'price', 'total', 'subtotal', 'cost'])) {
            $prices = [29.99, 49.00, 99.50, 149.00, 299.99, 450.00, 1200.00];

            return $prices[$index % count($prices)];
        }

        if ($colLower === 'tier') {
            $tiers = ['enterprise', 'mid_market', 'smb', 'startup'];

            return $tiers[$index % count($tiers)];
        }

        if ($colLower === 'title') {
            if (str_contains($table, 'deal')) {
                $dealTitles = ['Enterprise Platform License', 'Cloud Migration Consulting', 'Annual Security SLA', 'Global Expansion Rollout'];

                return $dealTitles[$index % count($dealTitles)];
            }
            if (str_contains($table, 'compan')) {
                return $companyNames[$index % count($companyNames)];
            }

            return Str::headline(Str::singular($table)).' Record #'.($index + 1);
        }

        if ($colLower === 'description') {
            return "Sample automated demonstration record for {$table} within LaraSlice vertical slice architecture.";
        }

        // Whole-word match so names like `updated_by` or `candidate_name` are not treated as dates
        if (preg_match('/(^|_)date(_|$)/', $colLower)) {
            return now()->subDays(rand(1, 90))->toDateString();
        }

        if (str_starts_with($colLower, 'is_') || str_starts_with($colLower, 'has_') || $colLower === 'active') {
            return 1;
        }

        // Type-aware fallbacks
        if (in_array($type, ['integer', 'bigint', 'smallint', 'tinyint', 'int'], true) ||
            in_array($colLower, ['score', 'rating', 'points', 'qty', 'quantity', 'count', 'age', 'views', 'votes', 'order', 'position', 'priority'], true)) {
            return ($index + 1) * 10;
        }

        if (in_array($type, ['float', 'double', 'decimal'], true)) {
            return round((float) (($index + 1) * 19.99), 2);
        }

        if ($type === 'boolean') {
            return 1;
        }

        if ($type === 'date') {
            return now()->subDays(rand(1, 60))->toDateString();
        }

        if (in_array($type, ['datetime', 'timestamp'], true)) {
            return now()->subDays(rand(1, 60))->toDateTimeString();
        }

        // Generic text fallback
        return Str::headline($column).' '.($index + 1);
    }

    /**
     * User id for created_by / updated_by: the authenticated user, else the first existing user.
     */
    protected function resolveStampUserId(): ?int
    {
        if ($id = auth()->id()) {
            return (int) $id;
        }

        if (! Schema::hasTable('users')) {
            return null;
        }

        $id = DB::table('users')->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Resolve all tables for a slice in dependency order.
     */
    protected function resolveSliceTablesInOrder(SliceManifest $slice): array
    {
        $primary = Str::plural(Str::snake($slice->name));
        $tables = [$primary];

        $modelFiles = glob($slice->path.'/Models/*.php') ?: [];
        foreach ($modelFiles as $mf) {
            $content = file_get_contents($mf);
            if (preg_match('/protected\s+\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $content, $m)) {
                if (! in_array($m[1], $tables, true)) {
                    $tables[] = $m[1];
                }
            } else {
                $t = Str::plural(Str::snake(basename($mf, '.php')));
                if (! in_array($t, $tables, true)) {
                    $tables[] = $t;
                }
            }
        }

        if (! empty($slice->tables)) {
            foreach ($slice->tables as $t) {
                if (! in_array($t, $tables, true)) {
                    $tables[] = $t;
                }
            }
        }

        return $tables;
    }

    protected function disableForeignKeyConstraints(): void
    {
        // Works on MySQL, MariaDB, PostgreSQL, SQLite and SQL Server
        Schema::disableForeignKeyConstraints();
    }

    protected function enableForeignKeyConstraints(): void
    {
        Schema::enableForeignKeyConstraints();
    }
}
