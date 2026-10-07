<?php

namespace LaraSlice\Blueprint;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaraSlice\Core\Ai\JevDecisionService;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

final class BlueprintStudioController extends Controller
{
    public function __construct()
    {
        $this->middleware(\LaraSlice\Wizard\Middleware\AuthorizeStudio::class);
    }

    /**
     * Display the Blueprint Studio interface.
     */
    public function show(Request $request): mixed
    {
        $requestedSlice = $request->query('slice');
        // The value is used to build file paths and glob patterns, so allow plain slice names only
        if (! is_string($requestedSlice) || ! preg_match('/^[A-Za-z][A-Za-z0-9 _-]{0,80}$/', $requestedSlice)) {
            $requestedSlice = null;
        }
        $requestedTemplate = $request->query('template', 'service-desk');

        $source = '';
        $slicesPath = config('laraslice.slices_path', app_path('Slices'));

        // 1. If an existing slice is requested, try loading its slice.yaml
        if ($requestedSlice) {
            $sliceFile = $slicesPath . '/' . $requestedSlice . '/slice.yaml';
            if (! File::exists($sliceFile)) {
                $candidates = glob($slicesPath . '/*/' . $requestedSlice . '/slice.yaml') ?: [];
                if (! empty($candidates)) {
                    $sliceFile = $candidates[0];
                }
            }
            if (! File::exists($sliceFile) && File::isDirectory($slicesPath)) {
                $sliceDirName = basename($requestedSlice);
                foreach (File::allFiles($slicesPath) as $file) {
                    if ($file->getFilename() === 'slice.yaml' && strcasecmp(basename(dirname($file->getPathname())), $sliceDirName) === 0) {
                        $sliceFile = $file->getPathname();
                        break;
                    }
                }
            }

            if (File::exists($sliceFile)) {
                $source = File::get($sliceFile);
            } else {
                $jsonFile = $slicesPath . '/' . $requestedSlice . '/slice.json';
                if (! File::exists($jsonFile)) {
                    $candidates = glob($slicesPath . '/*/' . $requestedSlice . '/slice.json') ?: [];
                    if (! empty($candidates)) {
                        $jsonFile = $candidates[0];
                    }
                }
                if (! File::exists($jsonFile) && File::isDirectory($slicesPath)) {
                    $sliceDirName = basename($requestedSlice);
                    foreach (File::allFiles($slicesPath) as $file) {
                        if ($file->getFilename() === 'slice.json' && strcasecmp(basename(dirname($file->getPathname())), $sliceDirName) === 0) {
                            $jsonFile = $file->getPathname();
                            break;
                        }
                    }
                }
                if (File::exists($jsonFile)) {
                    $source = $this->manifestToYamlFile((string) $jsonFile, $requestedSlice);
                }
            }
        }

        // 2. Fallback to templates if no slice loaded
        if (empty($source)) {
            $source = $this->getTemplateContent($requestedTemplate);
        }

        // 3. Pre-parse YAML for high-fidelity visual designer rendering
        $blueprintData = null;
        if (! empty($source)) {
            try {
                $blueprintData = Yaml::parse($source);
            } catch (Throwable) {
                $blueprintData = null;
            }
        }

        // 4. Collect installed slices that have a slice.yaml or slice.json
        $installedSlices = $this->getInstalledSlices($slicesPath);

        // 5. Collect database tables for quick introspection
        $dbTables = $this->getDatabaseTables();

        return view('laraslice::blueprint-studio', [
            'source'          => $source,
            'format'          => 'yaml',
            'blueprintData'   => $blueprintData,
            'installedSlices' => $installedSlices,
            'dbTables'        => $dbTables,
            'currentSlice'    => $requestedSlice,
            'currentTemplate' => $requestedTemplate,
            'allSlicesJson'   => null,
            'activeSliceIdx'  => 0,
        ]);
    }

    /**
     * Introspect an existing database table and return columns as schema fields.
     */
    public function introspect(Request $request): JsonResponse
    {
        $table = (string) $request->input('table');

        if (! $table || ! Schema::hasTable($table)) {
            return response()->json(['error' => "Table '{$table}' does not exist."], 404);
        }

        try {
            $columns = Schema::getColumns($table);
            $fields = [];

            foreach ($columns as $column) {
                $name = $column['name'] ?? '';
                if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                    continue;
                }

                $typeName = strtolower($column['type_name'] ?? 'string');
                $type = match (true) {
                    str_contains($typeName, 'int') && ($column['type'] === 'tinyint(1)' || $typeName === 'bool' || $typeName === 'boolean') => 'boolean',
                    str_contains($typeName, 'int') => 'integer',
                    str_contains($typeName, 'text') => 'text',
                    str_contains($typeName, 'decimal') || str_contains($typeName, 'float') || str_contains($typeName, 'double') => 'decimal',
                    str_contains($typeName, 'date') && ! str_contains($typeName, 'time') => 'date',
                    str_contains($typeName, 'time') || str_contains($typeName, 'timestamp') => 'datetime',
                    str_contains($typeName, 'json') => 'json',
                    default => 'string',
                };

                $fields[] = [
                    'handle'   => $name,
                    'label'    => Str::headline($name),
                    'type'     => $type,
                    'required' => ! ($column['nullable'] ?? false) && ($column['default'] === null),
                    'default'  => $column['default'] ?? null,
                    'width'    => in_array($type, ['text', 'json'], true) ? 100 : 50,
                ];
            }

            return response()->json([
                'table'  => $table,
                'model'  => Str::singular(Str::studly($table)),
                'fields' => $fields,
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Plan blueprint generation and assess migration risk.
     */
    public function plan(
        Request $request,
        BlueprintLoader $loader,
        BlueprintValidator $validator,
        BlueprintPlanner $planner
    ): mixed {
        $input = $request->validate([
            'source'           => ['required', 'string', 'max:1048576'],
            'format'           => ['required', 'in:yaml,yml,json'],
            'slices_json'      => ['nullable', 'string'],
            'active_slice_idx' => ['nullable', 'integer'],
        ]);

        $slicesPath = config('laraslice.slices_path', app_path('Slices'));
        $installedSlices = $this->getInstalledSlices($slicesPath);
        $dbTables = $this->getDatabaseTables();
        $allSlicesJson = $request->input('slices_json');
        $activeSliceIdx = (int) $request->input('active_slice_idx', 0);

        try {
            $blueprint = $validator->validate($loader->parse($input['source'], $input['format']));
            $plan = $planner->plan($blueprint, $slicesPath);
            $supported = true;
            $supportMessage = null;

            try {
                (new BlueprintApplier())->assertSupported($blueprint);
            } catch (Throwable $exception) {
                $supported = false;
                $supportMessage = $exception->getMessage();
            }

            $existingTables = [];
            foreach ($plan['database_operations'] as $operation) {
                if ($operation['kind'] === 'create_table' && Schema::hasTable($operation['table'])) {
                    $existingTables[] = $operation['table'];
                }
            }

            // Evaluate Migration Safety & Archetype via Jev Decision Service
            $jev = new JevDecisionService();
            $archetypeDecision = $jev->classifyArchetype($blueprint['description'] ?? $blueprint['name']);
            $riskDecision = $jev->evaluateMigrationRisk(
                $this->getDatabaseTables(),
                $plan['database_operations']
            );

            return view('laraslice::blueprint-studio', [
                'source'            => $input['source'],
                'format'            => $input['format'],
                'blueprint'         => $blueprint,
                'plan'              => $plan,
                'supported'         => $supported,
                'supportMessage'    => $supportMessage,
                'existingTables'    => $existingTables,
                'installedSlices'   => $installedSlices,
                'dbTables'          => $dbTables,
                'archetypeDecision' => $archetypeDecision,
                'riskDecision'      => $riskDecision,
                'allSlicesJson'     => $allSlicesJson,
                'activeSliceIdx'    => $activeSliceIdx,
            ]);
        } catch (Throwable $exception) {
            return view('laraslice::blueprint-studio', [
                'source'          => $input['source'],
                'format'          => $input['format'],
                'error'           => $exception->getMessage(),
                'installedSlices' => $installedSlices,
                'dbTables'        => $dbTables,
                'allSlicesJson'   => $allSlicesJson,
                'activeSliceIdx'  => $activeSliceIdx,
            ]);
        }
    }

    /**
     * Apply the reviewed blueprint and write slice files and slice.yaml.
     */
    public function apply(
        Request $request,
        BlueprintLoader $loader,
        BlueprintValidator $validator,
        BlueprintPlanner $planner
    ): mixed {
        $input = $request->validate([
            'source'           => ['required', 'string', 'max:1048576'],
            'format'           => ['required', 'in:yaml,yml,json'],
            'plan_hash'        => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'confirm_apply'    => ['accepted'],
            'auto_migrate'     => ['nullable'],
            'slices_json'      => ['nullable', 'string'],
            'active_slice_idx' => ['nullable', 'integer'],
        ]);

        $slicesPath = config('laraslice.slices_path', app_path('Slices'));
        $dbTables = $this->getDatabaseTables();
        $installedSlices = $this->getInstalledSlices($slicesPath);
        $allSlicesJson = $request->input('slices_json');
        $activeSliceIdx = (int) $request->input('active_slice_idx', 0);

        try {
            $blueprint = $validator->validate($loader->parse($input['source'], $input['format']));
            $plan = $planner->plan($blueprint, $slicesPath);

            if (! hash_equals($plan['plan_hash'], $input['plan_hash'])) {
                throw new RuntimeException('This review is out of date. Generate a new plan before applying.');
            }

            $target = (new BlueprintApplier(static fn (string $table): bool => Schema::hasTable($table)))
                ->apply($blueprint, $plan, $slicesPath, config('laraslice.slices_namespace', 'App\\Slices'));

            // Refresh installed slices list so the newly created slice appears immediately in the UI dropdown
            $installedSlices = $this->getInstalledSlices($slicesPath);

            // Auto-migration is opt-in and needs the separate studio.migrate permission
            $migrated = false;
            $migrationMessage = null;
            $mayMigrate = \LaraSlice\Core\Security\Access::allows($request->user(), ['studio.migrate', 'studio.*']);
            if ($request->boolean('auto_migrate', false) && ! $mayMigrate) {
                $migrationMessage = 'Migrations were not run: running migrations requires the studio.migrate permission.';
            } elseif ($request->boolean('auto_migrate', false)) {
                try {
                    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
                    $migrationOutput = trim(\Illuminate\Support\Facades\Artisan::output());
                    $migrated = true;
                    $migrationMessage = ! empty($migrationOutput) ? $migrationOutput : 'Database migrations applied.';
                } catch (Throwable $e) {
                    $migrationMessage = 'Migration warning: ' . $e->getMessage();
                }
            }

            // Calculate domain-scoped URL for the newly generated slice
            $domain = $blueprint['domain'] ?? null;
            $domainSlug = $domain ? Str::slug($domain) : null;
            $sliceSnake = Str::snake($blueprint['name']);
            $sliceRoutePath = ($domainSlug ? "{$domainSlug}/{$sliceSnake}" : $sliceSnake);
            $sliceUrl = url('/' . $sliceRoutePath);

            $successMsg = "Successfully generated vertical slice at {$target} with persisted slice.yaml!";
            if ($migrated) {
                $successMsg .= " Database migrations were automatically executed.";
            } else {
                $successMsg .= " Click '⚡ Run Migrations' or run 'php artisan migrate' to apply database tables.";
            }

            return view('laraslice::blueprint-studio', [
                'source'           => $input['source'],
                'format'           => $input['format'],
                'success'          => $successMsg,
                'sliceUrl'         => $sliceUrl,
                'migrated'         => $migrated,
                'migrationMessage' => $migrationMessage,
                'installedSlices'  => $installedSlices,
                'dbTables'         => $this->getDatabaseTables(),
                'currentSlice'     => basename($target),
                'allSlicesJson'    => $allSlicesJson,
                'activeSliceIdx'   => $activeSliceIdx,
            ]);
        } catch (Throwable $exception) {
            return view('laraslice::blueprint-studio', [
                'source'          => $input['source'],
                'format'          => $input['format'],
                'error'           => $exception->getMessage(),
                'installedSlices' => $installedSlices,
                'dbTables'        => $dbTables,
                'allSlicesJson'   => $allSlicesJson,
                'activeSliceIdx'  => $activeSliceIdx,
            ]);
        }
    }

    /**
     * Run pending database migrations on demand from Blueprint Studio.
     */
    public function runMigrations(Request $request): mixed
    {
        try {
            \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
            $output = trim(\Illuminate\Support\Facades\Artisan::output());
            $message = ! empty($output) ? $output : 'Database migrations executed successfully.';

            if ($request->wantsJson()) {
                return response()->json(['success' => true, 'message' => $message]);
            }

            return redirect()->route('laraslice.wizard.blueprint')
                ->with('success', "⚡ Migrations executed successfully: {$message}");
        } catch (Throwable $e) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
            }

            return redirect()->route('laraslice.wizard.blueprint')
                ->with('error', 'Migration failed: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve list of installed slices that have a slice.yaml.
     *
     * @return array<int, array{name: string, has_yaml: bool, path: string}>
     */
    private function getInstalledSlices(string $slicesPath): array
    {
        $installedSlices = [];
        if (File::isDirectory($slicesPath)) {
            foreach (File::directories($slicesPath) as $dir) {
                $sliceName = basename($dir);
                $yamlPath = $dir . '/slice.yaml';
                if (File::exists($yamlPath) || File::exists($dir . '/slice.json')) {
                    $installedSlices[] = [
                        'name'     => $sliceName,
                        'has_yaml' => File::exists($yamlPath),
                        'path'     => $yamlPath,
                    ];
                } else {
                    $domainName = basename($dir);
                    foreach (File::directories($dir) as $subDir) {
                        $subSliceName = basename($subDir);
                        $subYamlPath = $subDir . '/slice.yaml';
                        if (File::exists($subYamlPath) || File::exists($subDir . '/slice.json')) {
                            $installedSlices[] = [
                                'name'     => $subSliceName,
                                'domain'   => $domainName,
                                'has_yaml' => File::exists($subYamlPath),
                                'path'     => $subYamlPath,
                            ];
                        }
                    }
                }
            }
        }

        return $installedSlices;
    }

    /**
     * Retrieve list of table names from current database connection.
     *
     * @return array<string>
     */
    private function getDatabaseTables(): array
    {
        try {
            $currentDb = config('database.connections.' . config('database.default') . '.database');
            $tables = Schema::getTables();
            $result = [];
            foreach ($tables as $t) {
                $schema = is_array($t) ? ($t['schema'] ?? '') : ($t->schema ?? '');
                $name = is_array($t) ? ($t['name'] ?? '') : ($t->name ?? '');
                if ($name === '') {
                    continue;
                }
                if ($schema === '' || $schema === $currentDb) {
                    $result[] = $name;
                }
            }
            return array_values(array_unique($result));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Retrieve prebuilt blueprint templates.
     */
    private function getTemplateContent(string $template): string
    {
        if ($template === 'hr-module' || $template === 'departments') {
            return <<<'YAML'
schema_version: 1
name: Departments
handle: departments
domain: Human Resources
author: LaraSlice Team
version: 1.0.0
description: Manage company departments and staff employees.
models:
  - handle: department
    table: departments
    root: true
    timestamps: true
    soft_deletes: true
    fields:
      - handle: name
        label: Department Name
        type: string
        required: true
        length: 150
      - handle: code
        label: Department Code
        type: string
        required: true
        length: 20
      - handle: budget
        label: Annual Budget
        type: decimal
        required: false
        nullable: true
        length: 12,2
      - handle: location
        label: Location
        type: string
        required: false
        nullable: true
        length: 100
      - handle: is_active
        label: Active
        type: boolean
        default: true
    relations:
      - name: employees
        type: hasMany
        model: employee
        foreign_key: department_id

  - handle: employee
    table: employees
    timestamps: true
    soft_deletes: false
    fields:
      - handle: department_id
        label: Department
        type: foreign_id
        required: true
      - handle: first_name
        label: First Name
        type: string
        required: true
        length: 100
      - handle: last_name
        label: Last Name
        type: string
        required: true
        length: 100
      - handle: email
        label: Email Address
        type: email
        required: true
        length: 255
      - handle: phone
        label: Phone Number
        type: string
        required: false
        nullable: true
        length: 50
      - handle: job_title
        label: Job Title
        type: string
        required: true
        length: 150
      - handle: hire_date
        label: Hire Date
        type: date
        required: true
      - handle: salary
        label: Base Salary
        type: decimal
        required: false
        nullable: true
        length: 10,2
      - handle: status
        label: Employment Status
        type: enum
        required: true
        default: active
        options:
          active: Active
          on_leave: On Leave
          terminated: Terminated
    relations:
      - name: department
        type: belongsTo
        model: department
        foreign_key: department_id
YAML;
        }

        if ($template === 'shop-categories' || $template === 'categories') {
            return <<<'YAML'
schema_version: 1
name: Shop Categories
handle: shop_categories
domain: E-Commerce
author: LaraSlice Team
version: 1.0.0
description: E-Commerce product catalog categories and taxonomy.
models:
  - handle: shop_category
    table: shop_categories
    root: true
    timestamps: true
    soft_deletes: true
    fields:
      - handle: name
        label: Category Name
        type: string
        required: true
        length: 150
      - handle: slug
        label: URL Slug
        type: string
        required: true
        length: 150
      - handle: summary
        label: Summary Overview
        type: text
        required: false
        nullable: true
      - handle: is_active
        label: Active Status
        type: boolean
        default: true
YAML;
        }

        if ($template === 'shop' || $template === 'shop-products') {
            return <<<'YAML'
schema_version: 1
name: Shop Products
handle: shop_products
domain: E-Commerce
author: LaraSlice Team
version: 1.0.0
description: E-Commerce product catalog with category linking, SKU variants and pricing.
models:
  - handle: shop_product
    table: shop_products
    root: true
    timestamps: true
    soft_deletes: true
    fields:
      - handle: shop_category_id
        label: Category
        type: foreign_id
        required: true
      - handle: name
        label: Product Name
        type: string
        required: true
        length: 255
      - handle: slug
        label: URL Slug
        type: string
        required: true
        length: 255
      - handle: sku
        label: SKU Code
        type: string
        required: true
        length: 100
      - handle: price
        label: Base Price
        type: decimal
        required: true
        length: 10,2
      - handle: sale_price
        label: Sale Price
        type: decimal
        required: false
        nullable: true
        length: 10,2
      - handle: stock_quantity
        label: Stock Qty
        type: integer
        required: false
        nullable: true
        default: 0
      - handle: is_featured
        label: Featured
        type: boolean
        default: false
      - handle: is_active
        label: Active
        type: boolean
        default: true
    relations:
      - name: category
        type: belongsTo
        model: shop_category
        foreign_key: shop_category_id
      - name: variants
        type: hasMany
        model: shop_variant
        foreign_key: shop_product_id

  - handle: shop_variant
    table: shop_variants
    timestamps: true
    soft_deletes: false
    fields:
      - handle: shop_product_id
        label: Product
        type: foreign_id
        required: true
      - handle: sku
        label: Variant SKU
        type: string
        required: true
        length: 100
      - handle: title
        label: Variant Title
        type: string
        required: true
        length: 150
      - handle: price
        label: Price
        type: decimal
        required: true
        length: 10,2
      - handle: stock_quantity
        label: Stock
        type: integer
        required: true
        default: 0
    relations:
      - name: product
        type: belongsTo
        model: shop_product
        foreign_key: shop_product_id
YAML;
        }

        if ($template === 'shop-orders') {
            return <<<'YAML'
schema_version: 1
name: Shop Orders
handle: shop_orders
domain: E-Commerce
author: LaraSlice Team
version: 1.0.0
description: Order checkout and management with real-world product line items.
models:
  - handle: shop_order
    table: shop_orders
    root: true
    timestamps: true
    soft_deletes: true
    fields:
      - handle: order_number
        label: Order Number
        type: string
        required: true
        length: 50
      - handle: customer_name
        label: Customer Name
        type: string
        required: true
        length: 255
      - handle: customer_email
        label: Customer Email
        type: email
        required: true
        length: 255
      - handle: total_amount
        label: Total Amount
        type: decimal
        required: true
        length: 12,2
      - handle: order_status
        label: Order Status
        type: enum
        required: true
        default: pending
        options:
          pending: Pending
          processing: Processing
          completed: Completed
          cancelled: Cancelled
      - handle: payment_status
        label: Payment Status
        type: enum
        required: true
        default: unpaid
        options:
          unpaid: Unpaid
          paid: Paid
          refunded: Refunded
          failed: Failed
    relations:
      - name: items
        type: hasMany
        model: shop_order_item
        foreign_key: shop_order_id

  - handle: shop_order_item
    table: shop_order_items
    timestamps: true
    soft_deletes: false
    fields:
      - handle: shop_order_id
        label: Order
        type: foreign_id
        required: true
      - handle: shop_product_id
        label: Product
        type: foreign_id
        required: true
      - handle: product_name
        label: Product Name
        type: string
        required: true
        length: 255
      - handle: quantity
        label: Quantity
        type: integer
        required: true
        default: 1
      - handle: unit_price
        label: Unit Price
        type: decimal
        required: true
        length: 10,2
      - handle: total_price
        label: Line Total
        type: decimal
        required: true
        length: 10,2
    relations:
      - name: order
        type: belongsTo
        model: shop_order
        foreign_key: shop_order_id
      - name: product
        type: belongsTo
        model: shop_product
        foreign_key: shop_product_id
YAML;
        }

        if ($template === 'crm' || $template === 'companies') {
            return <<<'YAML'
schema_version: 1
name: Companies
handle: companies
domain: CRM
author: LaraSlice Team
version: 1.0.0
description: Track client companies and business contacts.
models:
  - handle: company
    table: companies
    root: true
    timestamps: true
    soft_deletes: true
    fields:
      - handle: name
        label: Company Name
        type: string
        required: true
        length: 200
      - handle: industry
        label: Industry
        type: string
        required: false
        nullable: true
        length: 100
      - handle: website
        label: Website
        type: string
        required: false
        nullable: true
        length: 255
      - handle: annual_revenue
        label: Revenue
        type: decimal
        required: false
        nullable: true
        length: 12,2
      - handle: is_active
        label: Active
        type: boolean
        default: true
    relations:
      - name: contacts
        type: hasMany
        model: contact
        foreign_key: company_id

  - handle: contact
    table: contacts
    timestamps: true
    soft_deletes: false
    fields:
      - handle: company_id
        label: Company
        type: foreign_id
        required: true
      - handle: full_name
        label: Full Name
        type: string
        required: true
        length: 150
      - handle: email
        label: Email
        type: email
        required: true
        length: 255
      - handle: phone
        label: Phone Number
        type: string
        required: false
        nullable: true
        length: 50
    relations:
      - name: company
        type: belongsTo
        model: company
        foreign_key: company_id
YAML;
        }

        if ($template === 'ecommerce_suite') {
            return $this->getTemplateContent('shop');
        }

        if ($template === 'crm_suite') {
            return $this->getTemplateContent('crm');
        }

        if ($template === 'billing_suite' || $template === 'invoices') {
            return <<<'YAML'
schema_version: 1
name: Invoices
handle: invoices
domain: Billing
author: LaraSlice Team
version: 1.0.0
description: Enterprise client invoicing, balances, payment dates, and delivery tracking.
models:
  - handle: invoice
    table: invoices
    root: true
    timestamps: true
    soft_deletes: true
    fields:
      - handle: invoice_number
        label: Invoice Number
        type: string
        required: true
        length: 50
      - handle: amount
        label: Total Amount ($)
        type: decimal
        required: true
        length: 14,2
        default: 0.00
      - handle: due_date
        label: Due Date
        type: date
        required: true
      - handle: status
        label: Invoice Status
        type: enum
        required: true
        default: draft
        options:
          draft: Draft
          sent: Sent
          paid: Paid
          overdue: Overdue
          void: Void
YAML;
        }

        if ($template === 'blog' || $template === 'articles') {
            return <<<'YAML'
schema_version: 1
name: Articles
handle: articles
domain: Content
author: LaraSlice Team
version: 1.0.0
description: Editorial articles, publishing lifecycle, and discussion comments.
models:
  - handle: article
    table: articles
    root: true
    timestamps: true
    soft_deletes: true
    fields:
      - handle: title
        label: Article Title
        type: string
        required: true
        length: 255
      - handle: slug
        label: URL Slug
        type: string
        required: true
        length: 255
      - handle: excerpt
        label: Excerpt
        type: text
        required: false
        nullable: true
      - handle: status
        label: Publish State
        type: enum
        required: true
        default: draft
        options:
          draft: Draft
          in_review: In Review
          published: Published
          archived: Archived
YAML;
        }

        // Default: service-desk example
        $examplePath = dirname(__DIR__, 2) . '/blueprints/examples/service-desk.slice.yaml';
        return is_file($examplePath) ? (string) file_get_contents($examplePath) : '';
    }

    /**
     * Convert an existing slice.json manifest into a valid slice.yaml representation.
     */
    private function manifestToYamlFile(string $jsonFile, string $sliceName): string
    {
        try {
            $manifest = json_decode((string) File::get($jsonFile), true) ?: [];
            $rootHandle = Str::singular(Str::snake($sliceName));
            $rootTable = $manifest['tables'][0] ?? Str::plural($rootHandle);
            $fields = [];
            foreach ($manifest['fields'] ?? [] as $k => $f) {
                $fHandle = is_string($k) ? $k : ($f['name'] ?? 'field');
                $fields[] = [
                    'handle'   => $fHandle,
                    'label'    => $f['label'] ?? Str::headline($fHandle),
                    'type'     => $f['type'] ?? 'string',
                    'required' => ! ($f['nullable'] ?? true),
                ];
            }

            // Map relations defined on manifest
            $rootRelations = [];
            foreach ($manifest['relations'] ?? [] as $rel) {
                $targetModel = Str::singular(Str::snake($rel['model'] ?? $rel['table'] ?? ''));
                $relName = $rel['method'] ?? $rel['name'] ?? Str::camel($rel['table'] ?? '');
                $rootRelations[] = [
                    'name'        => $relName,
                    'type'        => $rel['type'] ?? 'hasMany',
                    'model'       => $targetModel,
                    'foreign_key' => $rel['foreign_key'] ?? ($rootHandle . '_id'),
                ];
            }

            // Auto-detect belongsTo relationships from foreign_id fields
            foreach ($fields as $f) {
                if ($f['type'] === 'foreign_id' || str_ends_with($f['handle'], '_id')) {
                    $relModel = Str::singular(Str::snake(preg_replace('/_id$/', '', $f['handle'])));
                    $relName = Str::camel(preg_replace('/_id$/', '', $f['handle']));
                    $alreadyAdded = false;
                    foreach ($rootRelations as $rr) {
                        if (($rr['foreign_key'] ?? '') === $f['handle']) {
                            $alreadyAdded = true;
                            break;
                        }
                    }
                    if (! $alreadyAdded) {
                        $rootRelations[] = [
                            'name'        => $relName,
                            'type'        => 'belongsTo',
                            'model'       => $relModel,
                            'foreign_key' => $f['handle'],
                        ];
                    }
                }
            }

            $models = [
                [
                    'handle'     => $rootHandle,
                    'table'      => $rootTable,
                    'root'       => true,
                    'timestamps' => true,
                    'fields'     => $fields,
                    'relations'  => $rootRelations,
                ],
            ];

            // Introspect additional tables / child entities
            $tables = $manifest['tables'] ?? [];
            for ($i = 1; $i < count($tables); $i++) {
                $childTable = $tables[$i];
                $childHandle = Str::singular(Str::snake($childTable));
                $childFields = [];
                $childRelations = [];

                if (\Illuminate\Support\Facades\Schema::hasTable($childTable)) {
                    try {
                        $columns = \Illuminate\Support\Facades\Schema::getColumns($childTable);
                        foreach ($columns as $col) {
                            $cName = $col['name'] ?? '';
                            if (in_array($cName, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                                continue;
                            }
                            $cTypeName = strtolower($col['type_name'] ?? '');
                            $cType = match (true) {
                                str_contains($cTypeName, 'int') && ($col['type'] === 'tinyint(1)' || str_contains($cTypeName, 'bool')) => 'boolean',
                                str_contains($cTypeName, 'int') && str_ends_with($cName, '_id') => 'foreign_id',
                                str_contains($cTypeName, 'int') => 'integer',
                                str_contains($cTypeName, 'dec') || str_contains($cTypeName, 'float') || str_contains($cTypeName, 'double') => 'decimal',
                                str_contains($cTypeName, 'text') => 'text',
                                str_contains($cTypeName, 'date') || str_contains($cTypeName, 'time') => 'datetime',
                                default => 'string',
                            };

                            $childFields[] = [
                                'handle'   => $cName,
                                'label'    => Str::headline($cName),
                                'type'     => $cType,
                                'required' => ! ($col['nullable'] ?? true),
                            ];

                            if (str_ends_with($cName, '_id')) {
                                $targetModel = Str::singular(Str::snake(preg_replace('/_id$/', '', $cName)));
                                $childRelations[] = [
                                    'name'        => Str::camel(preg_replace('/_id$/', '', $cName)),
                                    'type'        => 'belongsTo',
                                    'model'       => $targetModel,
                                    'foreign_key' => $cName,
                                ];
                            }
                        }
                    } catch (\Throwable) {}
                }

                if (empty($childFields)) {
                    $childFields[] = [
                        'handle'   => $rootHandle . '_id',
                        'label'    => Str::headline($rootHandle),
                        'type'     => 'foreign_id',
                        'required' => true,
                    ];
                    $childRelations[] = [
                        'name'        => Str::camel($rootHandle),
                        'type'        => 'belongsTo',
                        'model'       => $rootHandle,
                        'foreign_key' => $rootHandle . '_id',
                    ];
                }

                $models[] = [
                    'handle'     => $childHandle,
                    'table'      => $childTable,
                    'root'       => false,
                    'timestamps' => true,
                    'fields'     => $childFields,
                    'relations'  => $childRelations,
                ];
            }

            $perms = $manifest['permissions'] ?? [];
            if (empty($perms)) {
                $perms = [
                    "{$rootHandle}.view",
                    "{$rootHandle}.create",
                    "{$rootHandle}.edit",
                    "{$rootHandle}.delete",
                ];
            }

            $bp = [
                'schema_version' => 1,
                'name'           => $manifest['name'] ?? Str::headline($sliceName),
                'handle'         => Str::snake($sliceName),
                'domain'         => $manifest['domain'] ?? null,
                'author'         => $manifest['author'] ?? 'LaraSlice Team',
                'version'        => $manifest['version'] ?? '1.0.0',
                'description'    => $manifest['description'] ?? '',
                'permissions'    => $perms,
                'models'         => $models,
            ];

            return Yaml::dump(array_filter($bp), 10, 2);
        } catch (\Throwable) {
            return '';
        }
    }
}
