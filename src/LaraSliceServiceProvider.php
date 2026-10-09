<?php

namespace LaraSlice;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\ComponentAttributeBag;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Commands\AuditPruneCommand;
use LaraSlice\Commands\BlueprintApplyCommand;
use LaraSlice\Commands\BlueprintPlanCommand;
use LaraSlice\Commands\BlueprintValidateCommand;
use LaraSlice\Commands\LaraSliceMcpCommand;
use LaraSlice\Commands\SkillPublishCommand;
use LaraSlice\Commands\SliceAiCommand;
use LaraSlice\Commands\SliceCacheCommand;
use LaraSlice\Commands\SliceClearCommand;
use LaraSlice\Commands\SliceDestroyCommand;
use LaraSlice\Commands\SliceExportFlutterCommand;
use LaraSlice\Commands\SliceFieldCommand;
use LaraSlice\Commands\SliceInstallCommand;
use LaraSlice\Commands\SliceListCommand;
use LaraSlice\Commands\SliceMakeCommand;
use LaraSlice\Commands\SlicePublishCommand;
use LaraSlice\Commands\SliceRepairCommand;
use LaraSlice\Commands\SliceSeedCommand;
use LaraSlice\Commands\SliceSyncCommand;
use LaraSlice\Commands\SliceToggleCommand;
use LaraSlice\Commands\SliceUiPruneCommand;
use LaraSlice\Commands\SliceWipeCommand;
use LaraSlice\Commands\SliceWizardCommand;
use LaraSlice\Core\Ai\AiChatController;
use LaraSlice\Core\Ai\AiEngine;
use LaraSlice\Core\Ai\McpServer;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Security\Access;
use LaraSlice\Support\SchemaCache;
use LaraSlice\Wizard\Middleware\AuthorizeStudio;
use LaraSlice\Wizard\Middleware\GuardStudioWrites;
use LaraSlice\Wizard\WizardController;
use TailwindMerge\TailwindMerge;

class LaraSliceServiceProvider extends ServiceProvider
{
    /** Package version reported to MCP clients and the AI copilot. Bump with each release. */
    public const VERSION = '1.5.1';

    public function register(): void
    {
        // 1. Merge configuration
        $this->mergeConfigFrom(__DIR__.'/../config/laraslice.php', 'laraslice');

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
        // The binding is per application; macros are static and outlive it (tests, Octane), so bind every boot.
        $this->app->singletonIf(TailwindMerge::class, fn () => TailwindMerge::instance());

        if (! ComponentAttributeBag::hasMacro('twMerge')) {
            ComponentAttributeBag::macro('twMerge', function (...$args) {
                $this->attributes['class'] = app(TailwindMerge::class)->merge($args, $this->attributes['class'] ?? '');

                return $this;
            });
        }

        // 0. Register Blueprint userstamps & auditStamps macros for enterprise auditability
        Blueprint::macro('userstamps', function () {
            $this->unsignedBigInteger('created_by')->nullable()->index();
            $this->unsignedBigInteger('updated_by')->nullable()->index();
        });

        Blueprint::macro('dropUserstamps', function () {
            $this->dropColumn(['created_by', 'updated_by']);
        });

        Blueprint::macro('softUserstamps', function () {
            $this->unsignedBigInteger('deleted_by')->nullable()->index();
        });

        Blueprint::macro('dropSoftUserstamps', function () {
            $this->dropColumn(['deleted_by']);
        });

        Blueprint::macro('auditStamps', function () {
            $this->timestamps();
            $this->unsignedBigInteger('created_by')->nullable()->index();
            $this->unsignedBigInteger('updated_by')->nullable()->index();
            $this->softDeletes();
            $this->unsignedBigInteger('deleted_by')->nullable()->index();
        });

        // 1. Register Artisan CLI Commands
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laraslice.php' => config_path('laraslice.php'),
            ], 'laraslice-config');

            $this->publishes([
                __DIR__.'/../resources/stubs/starter/views/welcome.blade.php' => resource_path('views/welcome.blade.php'),
            ], 'laraslice-starter');

            $this->commands([
                SliceInstallCommand::class,
                SliceMakeCommand::class,
                SliceWizardCommand::class,
                SliceFieldCommand::class,
                SliceUiPruneCommand::class,
                SliceListCommand::class,
                SliceSeedCommand::class,
                SliceToggleCommand::class,
                SliceWipeCommand::class,
                SliceDestroyCommand::class,
                SliceExportFlutterCommand::class,
                SliceAiCommand::class,
                BlueprintValidateCommand::class,
                BlueprintPlanCommand::class,
                BlueprintApplyCommand::class,
                SliceSyncCommand::class,
                SliceCacheCommand::class,
                SliceRepairCommand::class,
                SliceClearCommand::class,
                SlicePublishCommand::class,
                AuditPruneCommand::class,
                LaraSliceMcpCommand::class,
                SkillPublishCommand::class,
            ]);

            if (config('laraslice.audit.auto_prune')) {
                $this->callAfterResolving(Schedule::class, function ($schedule) {
                    $schedule->command('laraslice:audit:prune', ['--force' => true])->daily()->withoutOverlapping();
                });
            }
        }

        // 2. Load Core Migrations & Views
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/Wizard/views', 'laraslice');
        // The starter UI components, so package pages share the copies published to host apps
        $this->loadViewsFrom(dirname(__DIR__).'/resources/stubs/starter/views/components/ui', 'laraslice-ui');

        $this->registerRateLimiters();

        // Schema checks are cached per process; forget them whenever the schema changes
        Event::listen(MigrationsEnded::class, fn () => SchemaCache::flush());

        // 3. Register Wizard Routes. Every route needs studio.access; writes need the
        // matching studio.* permission so read access never unlocks code generation.
        if (config('laraslice.wizard.enabled', false)) {
            $wizardMiddleware = config('laraslice.wizard.middleware', ['web', 'auth']);
            $studio = AuthorizeStudio::class;
            $guard = GuardStudioWrites::class;
            Route::prefix('laraslice/wizard')->middleware($wizardMiddleware)->group(function () use ($studio, $guard) {
                Route::middleware("{$studio}:studio.access")->group(function () {
                    Route::get('/', [WizardController::class, 'show'])->name('laraslice.wizard');
                    Route::get('/studio', [WizardController::class, 'studio'])->name('laraslice.wizard.studio');
                    Route::get('/blueprint', [BlueprintStudioController::class, 'show'])->name('laraslice.wizard.blueprint');
                    Route::get('/schema-studio', [WizardController::class, 'schemaStudio'])->name('laraslice.wizard.schema_studio');
                    Route::get('/slices', [WizardController::class, 'listSlices'])->name('laraslice.wizard.slices');
                    Route::get('/audit-logs', [WizardController::class, 'getAuditLogs'])->name('laraslice.wizard.audit_logs');
                    Route::post('/copilot/chat', [WizardController::class, 'copilotChat'])->name('laraslice.wizard.copilot_chat');
                });

                // Toggle / seed / wipe / destroy check their own fine-grained permissions in the controller
                Route::middleware(["{$studio}:studio.access", $guard])->group(function () {
                    Route::post('/toggle-slice', [WizardController::class, 'toggleSlice'])->name('laraslice.wizard.toggle_slice');
                    Route::post('/toggle-domain', [WizardController::class, 'toggleDomain'])->name('laraslice.wizard.toggle_domain');
                    Route::post('/seed-slice', [WizardController::class, 'seedSlice'])->name('laraslice.wizard.seed_slice');
                    Route::post('/seed-domain', [WizardController::class, 'seedDomain'])->name('laraslice.wizard.seed_domain');
                    Route::post('/wipe-slice', [WizardController::class, 'wipeSlice'])->name('laraslice.wizard.wipe_slice');
                    Route::post('/wipe-domain', [WizardController::class, 'wipeDomain'])->name('laraslice.wizard.wipe_domain');
                    Route::post('/destroy-slice', [WizardController::class, 'destroySlice'])->name('laraslice.wizard.destroy_slice');
                    Route::post('/destroy-domain', [WizardController::class, 'destroyDomain'])->name('laraslice.wizard.destroy_domain');
                });

                Route::middleware(["{$studio}:studio.access", "{$studio}:studio.create", $guard])->group(function () {
                    Route::post('/generate', [WizardController::class, 'generate'])->name('laraslice.wizard.generate');
                    Route::post('/add-field', [WizardController::class, 'addField'])->name('laraslice.wizard.add_field');
                    Route::post('/add-fields-batch', [WizardController::class, 'addFieldsBatch'])->name('laraslice.wizard.add_fields_batch');
                    Route::post('/add-child-table', [WizardController::class, 'addChildTable'])->name('laraslice.wizard.add_child_table');
                    Route::post('/update-navigation', [WizardController::class, 'updateNavigation'])->name('laraslice.wizard.update_nav');
                    Route::post('/rollback-version', [WizardController::class, 'rollbackVersion'])->name('laraslice.wizard.rollback_version');
                    Route::post('/sync-fields', [WizardController::class, 'syncFields'])->name('laraslice.wizard.sync_fields');
                    Route::post('/save-relationships', [WizardController::class, 'saveRelationships'])->name('laraslice.wizard.save_relationships');
                    Route::post('/batch-generate', [WizardController::class, 'batchGenerate'])->name('laraslice.wizard.batch_generate');
                    Route::post('/domain-suite', [WizardController::class, 'generateDomainSuite'])->name('laraslice.wizard.domain_suite');
                });

                Route::middleware(["{$studio}:studio.access", "{$studio}:studio.blueprint", $guard])->group(function () {
                    Route::post('/blueprint/plan', [BlueprintStudioController::class, 'plan'])->name('laraslice.wizard.blueprint.plan');
                    Route::post('/blueprint/apply', [BlueprintStudioController::class, 'apply'])->name('laraslice.wizard.blueprint.apply');
                    Route::post('/blueprint/introspect', [BlueprintStudioController::class, 'introspect'])->name('laraslice.wizard.blueprint.introspect');
                });

                Route::middleware(["{$studio}:studio.access", "{$studio}:studio.migrate", $guard])->group(function () {
                    Route::post('/migrate', [WizardController::class, 'runMigration'])->name('laraslice.wizard.migrate');
                    Route::post('/blueprint/migrate', [BlueprintStudioController::class, 'runMigrations'])->name('laraslice.wizard.blueprint.migrate');
                });

                Route::middleware(["{$studio}:studio.access", "{$studio}:studio.wipe", $guard])->group(function () {
                    Route::post('/audit-logs/prune', [WizardController::class, 'pruneAuditLogs'])->name('laraslice.wizard.audit_logs.prune');
                });
            });
        }

        // Global LaraSlice AI Copilot Routes (always authenticated and permission-checked).
        // The AI settings page itself lives in the Settings slice (settings.ai / settings.ai.save).
        Route::middleware(['web', 'auth', 'throttle:laraslice-ai'])->prefix('laraslice/ai')->group(function () {
            Route::middleware('can:ai.copilot.use')->group(function () {
                Route::post('/chat', [AiChatController::class, 'chat'])->name('laraslice.ai.chat');
                Route::get('/providers', [AiChatController::class, 'providers'])->name('laraslice.ai.providers');
                Route::get('/page-overview', [AiChatController::class, 'pageOverview'])->name('laraslice.ai.page_overview');
                Route::get('/context', [AiChatController::class, 'context'])->name('laraslice.ai.context');
                Route::post('/record-create', [AiChatController::class, 'createRecord'])->name('laraslice.ai.record_create');
            });

            Route::middleware('can:ai.settings.edit')->group(function () {
                Route::post('/config', [AiChatController::class, 'updateConfig'])->name('laraslice.ai.config.update');
                Route::post('/test', [AiChatController::class, 'test'])->name('laraslice.ai.test');
            });
        });

        // 4. Register MCP (Model Context Protocol) endpoint for AI agents. Disabled by default;
        // accepts JSON-RPC over POST only and authenticates with a Sanctum bearer token.
        if (config('laraslice.ai.mcp_server.enabled', false)) {
            Route::middleware(config('laraslice.ai.mcp_server.middleware', ['api', 'auth:sanctum']))
                ->middleware(AuthorizeStudio::class.':studio.access')
                ->post(config('laraslice.ai.mcp_server.route', '/.well-known/mcp'), function (Request $request) {
                    return app(McpServer::class)->handle($request);
                })->name('laraslice.mcp');
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
        Gate::before(function ($user, string $ability) {
            if (Access::isSuperAdmin($user)) {
                return true;
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
                    if ($user && Access::allows($user, 'studio.access') && Route::has('laraslice.wizard')) {
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

    /**
     * Named rate limiters used by the authentication, MFA and AI routes.
     */
    protected function registerRateLimiters(): void
    {
        RateLimiter::for('laraslice-login', function (Request $request) {
            $identity = strtolower((string) ($request->input('email') ?? $request->input('identifier') ?? ''));

            return [
                Limit::perMinute(5)->by('login|'.$identity.'|'.$request->ip()),
                Limit::perMinute(20)->by('login-ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('laraslice-register', fn (Request $request) => Limit::perMinute(3)->by('register|'.$request->ip()));

        RateLimiter::for('laraslice-mfa', function (Request $request) {
            $subject = ($request->hasSession() ? $request->session()->get('mfa_pending_user_id') : null) ?? $request->user()?->getAuthIdentifier() ?? 'guest';

            return [
                Limit::perMinute(5)->by('mfa|'.$subject),
                Limit::perMinute(20)->by('mfa-ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('laraslice-ai', fn (Request $request) => Limit::perMinute(30)->by('ai|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
