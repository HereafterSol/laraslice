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
    | Rendering Kit
    |--------------------------------------------------------------------------
    |
    | The kit renders this application's surfaces: null is the
    | house kit (the standard Soul); 'replica' renders a ported
    | application in its source's own identity (100%-clone
    | ports). The Mind's `design.kit` overrides this per system;
    | `?kit=` overrides per request (developer escape hatch).
    */
    'kit' => env('LARASLICE_KIT'),

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
        // Local/dev only: set LARASLICE_MFA_ENABLED=false to skip MFA enroll/challenge after password.
        // Default true — never disable in production.
        'mfa_enabled' => (bool) env('LARASLICE_MFA_ENABLED', true),
        'api_registration' => (bool) env('LARASLICE_API_REGISTRATION', false),
        'passkey_user_verification' => env('LARASLICE_PASSKEY_USER_VERIFICATION', 'preferred'),
        'passkey_require_user_verification' => (bool) env('LARASLICE_PASSKEY_REQUIRE_UV', false),
        'passkey_enforce_signature_counter' => (bool) env('LARASLICE_PASSKEY_ENFORCE_SIGNATURE_COUNTER', false),
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

        // Two-phase confirmation for AI writes (Proposal #4):
        // an AI-initiated write never executes in one step. The
        // first call on a gated surface returns a proposal with
        // a signed confirmation token; only a repeat call
        // carrying that token — same surface, same arguments,
        // same user, before expiry, never used before —
        // executes. The TTL is server-side configuration, never
        // a model choice (300 s matches the ServiceStack MCP
        // reference design verified in the R&D hands-on).
        'confirmation' => [
            'ttl_seconds' => (int) env('LARASLICE_AI_CONFIRMATION_TTL', 300),
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

        // The discovery ladder's per-tier shape (Proposals #1–#3):
        // how much of the metadata projection a consumer at each
        // capability tier is handed per step. Harness fit is per
        // model (Harness Leaderboard evidence, compilation §3.17),
        // so ladder depth and payload sizes are tuned per tier —
        // never one shape assumed for all.
        //
        //   frontier   hosted frontier APIs (200K-class windows)
        //   free       free API tiers (OpenCode free, Gemini
        //              Flash-Lite — the in-app Copilot's configured
        //              providers today, so in-app consumers run
        //              at this tier)
        //   webgpu     browser-local models (WebLLM-class, ~8K)
        //   on_device  phone/on-device models (the 4K floor)
        //
        // index: rows = the tabular index payload; names = slice
        // names grouped by domain; domain_names = names for the
        // touched slices' domains only; summary = counts only.
        'ladder' => [
            'tier' => env('LARASLICE_AI_LADDER_TIER', 'free'),
            'tiers' => [
                'frontier' => ['context_tokens' => 200000, 'index' => 'rows', 'per_page' => 250, 'max_describes' => 4, 'describe_ui' => false],
                'free' => ['context_tokens' => 32000, 'index' => 'names', 'per_page' => 60, 'max_describes' => 2, 'describe_ui' => false],
                'webgpu' => ['context_tokens' => 8192, 'index' => 'domain_names', 'per_page' => 24, 'max_describes' => 1, 'describe_ui' => false],
                'on_device' => ['context_tokens' => 4096, 'index' => 'summary', 'per_page' => 12, 'max_describes' => 1, 'describe_ui' => false],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy & SaaS Isolation
    |--------------------------------------------------------------------------
    |
    | Pluggable multi-tenant scoping. When enabled, models using HasTenant
    | automatically filter queries by the tenant key (e.g. company_id or tenant_id).
    |
    */
    'tenancy' => [
        'enabled' => (bool) env('LARASLICE_TENANCY_ENABLED', false),
        'tenant_key' => env('LARASLICE_TENANT_KEY', 'company_id'),
    ],

    'porting' => [
        'allow_app_db_fallback' => (bool) env('LARASLICE_PORT_ALLOW_APP_DB_FALLBACK', false),
        // Comma-separated or array of table prefixes stripped from slice names (Dolibarr llx_, WP wp_)
        'strip_table_prefixes' => array_values(array_filter(array_map('trim', explode(',', (string) env('LARASLICE_PORT_STRIP_PREFIXES', 'llx_,wp_'))))),
        'source_connection' => env('LARASLICE_PORT_SOURCE_CONNECTION'),
        // Opt-in target table prefix for source-driven ports (slice:port --table-prefix=):
        // every ported table lands as {prefix}{table} so a port can coexist with
        // tables an earlier port or the host app already owns. Framework
        // auth/merge tables (users, roles, permissions, ...) are never prefixed.
        'table_prefix' => (string) env('LARASLICE_PORT_TABLE_PREFIX', ''),
    ],
];
