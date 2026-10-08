<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Slices Directory
    |--------------------------------------------------------------------------
    |
    | Path where modular vertical slices reside in your application.
    | Default is `app/Slices`. Each slice is an independent module.
    |
    */
    'slices_path' => app_path('Slices'),

    /*
    |--------------------------------------------------------------------------
    | Audit & Compliance Trail
    |--------------------------------------------------------------------------
    */
    'audit' => [
        // false stops writing audit records
        'enabled' => (bool) env('LARASLICE_AUDIT_ENABLED', true),
        'retention_days' => (int) env('LARASLICE_AUDIT_RETENTION_DAYS', 90),
        // true schedules `laraslice:audit:prune --force` daily (requires the Laravel scheduler)
        'auto_prune' => (bool) env('LARASLICE_AUDIT_AUTO_PRUNE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slices Namespace
    |--------------------------------------------------------------------------
    */
    'slices_namespace' => 'App\\Slices',

    /*
    |--------------------------------------------------------------------------
    | Flutter Project Export Path
    |--------------------------------------------------------------------------
    |
    | Directory where matching Flutter slices, models, and HTTP clients
    | will be scaffolded when running `php artisan slice:export-flutter`.
    |
    */
    'flutter_path' => base_path('flutter_app'),

    /*
    |--------------------------------------------------------------------------
    | Auto Discovery
    |--------------------------------------------------------------------------
    |
    | Automatically register routes, migrations, views, and commands
    | from all slices containing a `slice.json` manifest.
    |
    */
    'auto_discovery' => true,

    /*
    | Middleware applied to routes in newly generated slices. Admin CRUD
    | endpoints require authentication by default; public access is explicit.
    */
    'generated_routes' => [
        'web_middleware' => ['web', 'auth'],
        'api_middleware' => ['api', 'auth:sanctum'],
    ],

    /*
    | Listing pages use the BlatUI data-table, which searches, sorts and pages
    | in the browser. Index pages load up to this many rows; a notice appears
    | when a table holds more.
    */
    'data_table' => [
        'max_rows' => (int) env('LARASLICE_DATA_TABLE_MAX_ROWS', 1000),
    ],

    'forms' => [
        // Maximum rows loaded into a relationship <select>; the selected value is always included
        'relationship_options_limit' => (int) env('LARASLICE_RELATIONSHIP_OPTIONS_LIMIT', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Self-service sign-up through POST /api/auth/register is off unless
    | LARASLICE_API_REGISTRATION=true.
    |
    */
    'auth' => [
        'api_registration' => (bool) env('LARASLICE_API_REGISTRATION', false),
        'passkey_user_verification' => env('LARASLICE_PASSKEY_USER_VERIFICATION', 'preferred'),
        'passkey_require_user_verification' => (bool) env('LARASLICE_PASSKEY_REQUIRE_UV', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Wizard & Slice Studio
    |--------------------------------------------------------------------------
    |
    | The studio writes PHP files and runs migrations, so it is disabled unless
    | LARASLICE_WIZARD_ENABLED=true. Even when enabled, file writes, migrations
    | and destructive actions are refused in production unless
    | LARASLICE_WIZARD_ALLOW_IN_PRODUCTION=true.
    |
    */
    'wizard' => [
        'enabled' => (bool) env('LARASLICE_WIZARD_ENABLED', false),
        'allow_in_production' => (bool) env('LARASLICE_WIZARD_ALLOW_IN_PRODUCTION', false),
        'middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Engine & MCP (Model Context Protocol) Integration
    |--------------------------------------------------------------------------
    |
    | Native AI support for Laravel 13+:
    | - AI-assisted slice generation (`php artisan slice:ai "prompt"`)
    | - Model Context Protocol (MCP) server for IDE agents (Cursor, Antigravity, Claude)
    | - Automated DTO & test generation
    | - Semantic Vector Search & Embeddings
    |
    */
    'ai' => [
        // Outbound provider requests: seconds before giving up, and retries on connection errors, 429 and 5xx
        'http' => [
            'timeout' => (int) env('LARASLICE_AI_TIMEOUT', 60),
            'retries' => (int) env('LARASLICE_AI_RETRIES', 2),
        ],
        // Provider credentials are read from the environment here only; keys saved
        // from the AI settings page are stored encrypted in the settings table.
        'providers' => [
            'opencode' => [
                'key' => env('OPENCODE_API_KEY'),
                'model' => env('OPENCODE_MODEL'),
            ],
            'openai' => [
                'key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL'),
            ],
            'anthropic' => [
                'key' => env('ANTHROPIC_API_KEY'),
                'model' => env('ANTHROPIC_MODEL'),
            ],
            'gemini' => [
                'key' => env('GEMINI_API_KEY'),
                'model' => env('GEMINI_MODEL'),
            ],
            'openrouter' => [
                'key' => env('OPENROUTER_API_KEY'),
                'model' => env('OPENROUTER_MODEL'),
            ],
            'ollama' => [
                'endpoint' => env('OLLAMA_ENDPOINT'),
                'model' => env('OLLAMA_MODEL'),
            ],
        ],

        // Tables the copilot may never read rows from or insert into.
        'protected_tables' => [
            'users', 'user_*', 'roles', 'permissions', 'role_user', 'permission_role',
            'settings', 'password_reset_tokens', 'personal_access_tokens', 'sessions',
            'migrations', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks',
            'laraslice_audit_logs',
        ],

        // HTTP endpoint for IDE agents. Off by default; authenticate with a Sanctum
        // bearer token. The `php artisan laraslice:mcp` stdio server is unaffected.
        'mcp_server' => [
            'enabled' => (bool) env('LARASLICE_MCP_ENABLED', false),
            'route' => '/.well-known/mcp',
            'middleware' => ['api', 'auth:sanctum'],
        ],
    ],
];
