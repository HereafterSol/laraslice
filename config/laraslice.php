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
        'enabled'        => env('LARASLICE_AUDIT_ENABLED', true),
        'retention_days' => (int) env('LARASLICE_AUDIT_RETENTION_DAYS', 90),
        'auto_prune'     => env('LARASLICE_AUDIT_AUTO_PRUNE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slices Namespace
    |--------------------------------------------------------------------------
    */
    'slices_namespace' => 'App\\Slices',

    /*
    |--------------------------------------------------------------------------
    | UI Framework Integration
    |--------------------------------------------------------------------------
    |
    | Supported: 'blatui' (Blade + Alpine.js + Tailwind CSS v4), 'bootstrap5', 'tailwind'
    | Default: 'blatui' (using 156+ shadcn-styled Blade components)
    |
    */
    'ui_framework' => env('LARASLICE_UI_FRAMEWORK', 'blatui'),

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
    |--------------------------------------------------------------------------
    | Web Wizard
    |--------------------------------------------------------------------------
    |
    | Enable the 4-step web-based project generator wizard (vanillaslice-style).
    |
    */
    'wizard' => [
        'enabled' => env('LARASLICE_WIZARD_ENABLED', true),
        'route'   => '/laraslice/wizard',
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
        'enabled' => env('LARASLICE_AI_ENABLED', true),
        'default_provider' => env('LARASLICE_AI_PROVIDER', 'openai'), // openai, gemini, anthropic, ollama
        'api_key' => env('LARASLICE_AI_KEY', ''),
        'model' => env('LARASLICE_AI_MODEL', 'gpt-4o'),
        'mcp_server' => [
            'enabled' => true,
            'route' => '/.well-known/mcp',
            'middleware' => ['web', 'auth'],
            'tools_enabled' => true, // Allows AI agents to query slice schemas and generate features
        ],
    ],
];
