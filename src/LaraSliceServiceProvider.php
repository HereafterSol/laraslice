<?php

namespace LaraSlice;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Ai\AiEngine;
use LaraSlice\Core\Ai\McpServer;
use LaraSlice\Commands\SliceMakeCommand;
use LaraSlice\Commands\SliceListCommand;
use LaraSlice\Commands\SliceExportFlutterCommand;
use LaraSlice\Commands\SliceAiCommand;
use LaraSlice\Commands\BlueprintValidateCommand;
use LaraSlice\Commands\BlueprintPlanCommand;
use LaraSlice\Commands\BlueprintApplyCommand;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Wizard\WizardController;

class LaraSliceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 1. Merge configuration
        $this->mergeConfigFrom(__DIR__ . '/../config/laraslice.php', 'laraslice');

        // 2. Register SliceManager Singleton
        $this->app->singleton(SliceManager::class, function ($app) {
            return new SliceManager($app);
        });

        // 3. Register AiEngine Singleton
        $this->app->singleton(AiEngine::class, function ($app) {
            return new AiEngine($app->make(SliceManager::class));
        });

        // 4. Register McpServer Singleton
        $this->app->singleton(McpServer::class, function ($app) {
            return new McpServer($app->make(AiEngine::class), $app->make(SliceManager::class));
        });
    }

    public function boot(): void
    {
        // Register the twMerge attribute macro used by the published BlatUI components,
        // unless the host app already provides one (e.g. via gehrisandro/tailwind-merge-laravel)
        if (!\Illuminate\View\ComponentAttributeBag::hasMacro('twMerge')) {
            $this->app->singletonIf(\TailwindMerge\TailwindMerge::class, fn () => \TailwindMerge\TailwindMerge::instance());

            \Illuminate\View\ComponentAttributeBag::macro('twMerge', function (...$args) {
                $this->attributes['class'] = app(\TailwindMerge\TailwindMerge::class)->merge($args, $this->attributes['class'] ?? '');

                return $this;
            });
        }

        // 0. Register Blueprint userstamps & auditStamps macros for enterprise auditability
        \Illuminate\Database\Schema\Blueprint::macro('userstamps', function () {
            $this->unsignedBigInteger('created_by')->nullable()->index();
            $this->unsignedBigInteger('updated_by')->nullable()->index();
        });

        \Illuminate\Database\Schema\Blueprint::macro('dropUserstamps', function () {
            $this->dropColumn(['created_by', 'updated_by']);
        });

        \Illuminate\Database\Schema\Blueprint::macro('softUserstamps', function () {
            $this->unsignedBigInteger('deleted_by')->nullable()->index();
        });

        \Illuminate\Database\Schema\Blueprint::macro('dropSoftUserstamps', function () {
            $this->dropColumn(['deleted_by']);
        });

        \Illuminate\Database\Schema\Blueprint::macro('auditStamps', function () {
            $this->timestamps();
            $this->unsignedBigInteger('created_by')->nullable()->index();
            $this->unsignedBigInteger('updated_by')->nullable()->index();
            $this->softDeletes();
            $this->unsignedBigInteger('deleted_by')->nullable()->index();
        });

        // 1. Register Artisan CLI Commands
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/laraslice.php' => config_path('laraslice.php'),
            ], 'laraslice-config');

            $this->publishes([
                __DIR__ . '/../resources/stubs/starter/welcome.blade.php' => resource_path('views/welcome.blade.php'),
            ], 'laraslice-starter');

            $this->commands([
                \LaraSlice\Commands\SliceInstallCommand::class,
                SliceMakeCommand::class,
                \LaraSlice\Commands\SliceWizardCommand::class,
                \LaraSlice\Commands\SliceFieldCommand::class,
                \LaraSlice\Commands\SliceUiPruneCommand::class,
                SliceListCommand::class,
                \LaraSlice\Commands\SliceSeedCommand::class,
                \LaraSlice\Commands\SliceToggleCommand::class,
                \LaraSlice\Commands\SliceWipeCommand::class,
                \LaraSlice\Commands\SliceDestroyCommand::class,
                SliceExportFlutterCommand::class,
                SliceAiCommand::class,
                BlueprintValidateCommand::class,
                BlueprintPlanCommand::class,
                BlueprintApplyCommand::class,
                \LaraSlice\Console\Commands\SliceSyncCommand::class,
                \LaraSlice\Commands\SliceCacheCommand::class,
                \LaraSlice\Commands\SliceClearCommand::class,
                \LaraSlice\Commands\SlicePublishCommand::class,
                \LaraSlice\Commands\AuditPruneCommand::class,
                \LaraSlice\Commands\LaraSliceMcpCommand::class,
                \LaraSlice\Commands\SkillPublishCommand::class,
            ]);
        }

        // 2. Load Core Migrations & Views
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/Wizard/views', 'laraslice');

        // 3. Register Wizard Routes
        if (config('laraslice.wizard.enabled', true)) {
            $wizardMiddleware = config('laraslice.wizard.middleware', ['web', 'auth']);
            if (! in_array(\LaraSlice\Wizard\Middleware\AuthorizeStudio::class, $wizardMiddleware, true)) {
                $wizardMiddleware[] = \LaraSlice\Wizard\Middleware\AuthorizeStudio::class;
            }
            Route::prefix('laraslice/wizard')->middleware($wizardMiddleware)->group(function () {
                Route::get('/', [WizardController::class, 'show'])->name('laraslice.wizard');
                Route::get('/studio', [WizardController::class, 'studio'])->name('laraslice.wizard.studio');
                Route::get('/blueprint', [BlueprintStudioController::class, 'show'])->name('laraslice.wizard.blueprint');
                Route::get('/schema-studio', [WizardController::class, 'schemaStudio'])->name('laraslice.wizard.schema_studio');
                Route::post('/blueprint/plan', [BlueprintStudioController::class, 'plan'])->name('laraslice.wizard.blueprint.plan');
                Route::post('/blueprint/apply', [BlueprintStudioController::class, 'apply'])->name('laraslice.wizard.blueprint.apply');
                Route::post('/blueprint/migrate', [BlueprintStudioController::class, 'runMigrations'])->name('laraslice.wizard.blueprint.migrate');
                Route::post('/blueprint/introspect', [BlueprintStudioController::class, 'introspect'])->name('laraslice.wizard.blueprint.introspect');
                Route::post('/generate', [WizardController::class, 'generate'])->name('laraslice.wizard.generate');
                Route::post('/migrate', [WizardController::class, 'runMigration'])->name('laraslice.wizard.migrate');
                Route::get('/slices', [WizardController::class, 'listSlices'])->name('laraslice.wizard.slices');
                Route::get('/audit-logs', [WizardController::class, 'getAuditLogs'])->name('laraslice.wizard.audit_logs');
                Route::post('/audit-logs/prune', [WizardController::class, 'pruneAuditLogs'])->name('laraslice.wizard.audit_logs.prune');
                Route::post('/add-field', [WizardController::class, 'addField'])->name('laraslice.wizard.add_field');
                Route::post('/add-fields-batch', [WizardController::class, 'addFieldsBatch'])->name('laraslice.wizard.add_fields_batch');
                Route::post('/add-child-table', [WizardController::class, 'addChildTable'])->name('laraslice.wizard.add_child_table');
                Route::post('/update-navigation', [WizardController::class, 'updateNavigation'])->name('laraslice.wizard.update_nav');
                Route::post('/rollback-version', [WizardController::class, 'rollbackVersion'])->name('laraslice.wizard.rollback_version');
                Route::post('/sync-fields', [WizardController::class, 'syncFields'])->name('laraslice.wizard.sync_fields');
                Route::post('/save-relationships', [WizardController::class, 'saveRelationships'])->name('laraslice.wizard.save_relationships');
                Route::post('/copilot/chat', [WizardController::class, 'copilotChat'])->name('laraslice.wizard.copilot_chat');
                Route::post('/batch-generate', [WizardController::class, 'batchGenerate'])->name('laraslice.wizard.batch_generate');
                Route::post('/domain-suite', [WizardController::class, 'generateDomainSuite'])->name('laraslice.wizard.domain_suite');
                Route::post('/toggle-slice', [WizardController::class, 'toggleSlice'])->name('laraslice.wizard.toggle_slice');
                Route::post('/toggle-domain', [WizardController::class, 'toggleDomain'])->name('laraslice.wizard.toggle_domain');
                Route::post('/seed-slice', [WizardController::class, 'seedSlice'])->name('laraslice.wizard.seed_slice');
                Route::post('/seed-domain', [WizardController::class, 'seedDomain'])->name('laraslice.wizard.seed_domain');
                Route::post('/wipe-slice', [WizardController::class, 'wipeSlice'])->name('laraslice.wizard.wipe_slice');
                Route::post('/wipe-domain', [WizardController::class, 'wipeDomain'])->name('laraslice.wizard.wipe_domain');
                Route::post('/destroy-slice', [WizardController::class, 'destroySlice'])->name('laraslice.wizard.destroy_slice');
                Route::post('/destroy-domain', [WizardController::class, 'destroyDomain'])->name('laraslice.wizard.destroy_domain');
            });
        }

        // Global LaraSlice AI Copilot Routes
        $aiMiddleware = ['web'];
        if (!app()->environment('local', 'testing') && config('laraslice.ai.require_auth', false)) {
            $aiMiddleware[] = 'auth';
        }
        Route::middleware($aiMiddleware)->group(function () {
            Route::post('/laraslice/ai/chat', [\LaraSlice\Core\Ai\AiChatController::class, 'chat'])->name('laraslice.ai.chat');
            Route::get('/laraslice/ai/providers', [\LaraSlice\Core\Ai\AiChatController::class, 'providers'])->name('laraslice.ai.providers');
                        Route::get('/laraslice/ai/page-overview', [\LaraSlice\Core\Ai\AiChatController::class, 'pageOverview'])->name('laraslice.ai.page_overview');
            Route::get('/laraslice/ai/context', [\LaraSlice\Core\Ai\AiChatController::class, 'context'])->name('laraslice.ai.context');
            Route::post('/laraslice/ai/config', [\LaraSlice\Core\Ai\AiChatController::class, 'updateConfig'])->name('laraslice.ai.config.update');
                        Route::post('/laraslice/ai/record-create', [\LaraSlice\Core\Ai\AiChatController::class, 'createRecord'])->name('laraslice.ai.record_create');
            Route::post('/laraslice/ai/test', [\LaraSlice\Core\Ai\AiChatController::class, 'test'])->name('laraslice.ai.test');
            Route::get('/admin/settings/ai', [\LaraSlice\Core\Ai\AiChatController::class, 'settings'])->name('settings.ai');
            Route::post('/admin/settings/ai', [\LaraSlice\Core\Ai\AiChatController::class, 'updateSettings'])->name('settings.ai.save');
        });

        // 4. Register MCP (Model Context Protocol) Route for AI Agents (Cursor / Antigravity / Claude)
        if (config('laraslice.ai.mcp_server.enabled', true)) {
            Route::middleware(config('laraslice.ai.mcp_server.middleware', ['auth']))
                ->any(config('laraslice.ai.mcp_server.route', '/.well-known/mcp'), function (\Illuminate\Http\Request $request) {
                return app(McpServer::class)->handle($request);
            });
        }

        // 5. Discover and Boot All Installed Slices
        if (config('laraslice.auto_discovery', true)) {
            $this->app->make(SliceManager::class)->discover();
        }

        // 6. Share dynamic navigation data with all views for sidebar rendering
        $this->app->booted(function () {
            $manager = $this->app->make(SliceManager::class);
            view()->composer('*', function ($view) use ($manager) {
                if (! $view->offsetExists('laraslice_nav')) {
                    $view->with('laraslice_nav', $manager->getNavigableSlices());
                }
            });
        });

        // 7. Register Native Slice RBAC Gate Bridge
        \Illuminate\Support\Facades\Gate::before(function ($user, string $ability) {
            if (method_exists($user, 'hasRole') && $user->hasRole('super-admin')) {
                return true;
            }
            if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                return true;
            }
            if (isset($user->email) && $user->email === config('laraslice.super_admin_email', 'admin@laraslice.com')) {
                return true;
            }
            if (isset($user->id)) {
                try {
                    if (\Illuminate\Support\Facades\Schema::hasTable('role_user') && \Illuminate\Support\Facades\Schema::hasTable('roles')) {
                        $isSuperAdmin = \Illuminate\Support\Facades\DB::table('role_user')
                            ->join('roles', 'role_user.role_id', '=', 'roles.id')
                            ->where('role_user.user_id', $user->id)
                            ->where(function ($q) {
                                $q->where('roles.slug', 'super-admin')
                                  ->orWhere('roles.id', 1);
                            })
                            ->exists();
                        if ($isSuperAdmin) {
                            return true;
                        }
                    }
                } catch (\Throwable $e) {}
            }
            if (method_exists($user, 'hasPermission')) {
                return $user->hasPermission($ability) ? true : null;
            }
            return null;
        });

        // 8. Register default dashboard route fallback if host application doesn't define one
        $this->app->booted(function () {
            $routes = Route::getRoutes();
            $hasDashboard = $routes->hasNamedRoute('dashboard');
            if (! $hasDashboard) {
                Route::middleware(['web', 'auth'])->get('/dashboard', function () {
                    $user = auth()->user();
                    if ($user && ($user->hasRole('super-admin') || (method_exists($user, 'hasPermission') && $user->hasPermission('studio.access')))) {
                        return redirect()->route('laraslice.wizard');
                    }
                    if (Route::has('account.settings')) {
                        return redirect()->route('account.settings');
                    }
                    return redirect('/');
                })->name('dashboard');
            }
        });
    }
}