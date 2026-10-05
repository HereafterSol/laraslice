<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\File;
use LaraSlice\Slices\Users\Models\User;
use LaraSlice\Slices\Roles\Models\Role;

class SliceInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'slice:install 
                            {--force : Overwrite existing welcome page and files}
                            {--skip-npm : Skip automatic npm install if node_modules is missing}
                            {--email=admin@laraslice.com : Default admin email}
                            {--password=password : Default admin password}';

    /**
     * The console command description.
     */
    protected $description = 'Install and initialize LaraSlice: publish landing page, run migrations, and seed default Super Admin';

    public function handle(): int
    {
        $this->components->info('⚡ Installing LaraSlice Enterprise Framework...');

        // 1. Publish Configuration
        $this->components->task('Publishing LaraSlice configuration', function () {
            Artisan::call('vendor:publish', ['--tag' => 'laraslice-config', '--force' => true]);
            return true;
        });

        // 2. Publish Welcome Landing Page & BlatUI Starter Views
        $this->components->task('Publishing LaraSlice Starter Layout & BlatUI Components', function () {
            $starterViewsDir = __DIR__ . '/../../resources/stubs/starter/views';

            if (File::isDirectory($starterViewsDir)) {
                // Copy welcome page
                $welcomeSource = $starterViewsDir . '/welcome.blade.php';
                $welcomeDest = resource_path('views/welcome.blade.php');
                if (File::exists($welcomeSource)) {
                    File::ensureDirectoryExists(dirname($welcomeDest));
                    File::copy($welcomeSource, $welcomeDest);
                }

                // Copy dashboard-01 layouts
                $layoutsSource = $starterViewsDir . '/layouts';
                $layoutsDest = resource_path('views/layouts');
                if (File::isDirectory($layoutsSource)) {
                    File::ensureDirectoryExists($layoutsDest);
                    File::copyDirectory($layoutsSource, $layoutsDest);
                }

                // Copy BlatUI UI components
                $componentsSource = $starterViewsDir . '/components';
                $componentsDest = resource_path('views/components');
                if (File::isDirectory($componentsSource)) {
                    File::ensureDirectoryExists($componentsDest);
                    File::copyDirectory($componentsSource, $componentsDest);
                }
            }

            // Copy CSS assets (blatui.css, etc.)
            $cssSource = __DIR__ . '/../../resources/stubs/starter/css';
            $cssDest = resource_path('css');
            if (File::isDirectory($cssSource)) {
                File::ensureDirectoryExists($cssDest);
                File::copyDirectory($cssSource, $cssDest);
            }

            // Copy JS assets (blatui.js, blatui-core.js, etc.)
            $jsSource = __DIR__ . '/../../resources/stubs/starter/js';
            $jsDest = resource_path('js');
            if (File::isDirectory($jsSource)) {
                File::ensureDirectoryExists($jsDest);
                File::copyDirectory($jsSource, $jsDest);
            }
            return true;
        });

        // 3. Run Database Migrations
        $this->components->task('Running database migrations', function () {
            Artisan::call('migrate', ['--force' => true]);
            return true;
        });

        // 4. Seed Super Admin User, Role & Permissions
        $email = $this->option('email');
        $password = $this->option('password');

        $this->components->task('Seeding Super Admin user [' . $email . '] & RBAC permissions', function () use ($email, $password) {
            // Find or create role
            $role = null;
            if (class_exists(Role::class)) {
                $role = Role::firstOrCreate(
                    ['slug' => 'super-admin'],
                    [
                        'name' => 'Super Administrator',
                        'description' => 'Full unrestricted system-wide access to all slices and settings'
                    ]
                );
            } elseif (\Illuminate\Support\Facades\Schema::hasTable('roles')) {
                \Illuminate\Support\Facades\DB::table('roles')->updateOrInsert(
                    ['slug' => 'super-admin'],
                    [
                        'name' => 'Super Administrator',
                        'description' => 'Full unrestricted system-wide access to all slices and settings',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
                $role = \Illuminate\Support\Facades\DB::table('roles')->where('slug', 'super-admin')->first();
            }

            // Create admin user using standard User model or Slice User model
            $userModelClass = config('auth.providers.users.model', User::class);
            if (!class_exists($userModelClass)) {
                $userModelClass = User::class;
            }

            $user = $userModelClass::updateOrCreate(
                ['email' => $email],
                [
                    'name' => 'Administrator',
                    'password' => Hash::make($password),
                    'status' => 'active',
                ]
            );

            // Ensure super-admin role is attached to user in role_user table
            if (isset($role) && isset($user->id) && \Illuminate\Support\Facades\Schema::hasTable('role_user')) {
                \Illuminate\Support\Facades\DB::table('role_user')->updateOrInsert(
                    ['role_id' => $role->id, 'user_id' => $user->id],
                    ['role_id' => $role->id, 'user_id' => $user->id]
                );
            }

            // Sync all permissions from active slices and assign to super-admin
            try {
                $sliceManager = app(\LaraSlice\Core\Discovery\SliceManager::class);
                $sliceManager->syncPermissions();
            } catch (\Throwable $e) {}

            // Inject HasSlicePermissions trait into app/Models/User.php if missing
            $userModelFile = app_path('Models/User.php');
            if (File::exists($userModelFile)) {
                $userModelContent = File::get($userModelFile);
                if (!str_contains($userModelContent, 'HasSlicePermissions')) {
                    // Append to the first trait `use` in the class body, whatever traits it lists
                    // (e.g. `use HasApiTokens, HasFactory, Notifiable;`), else add a new one.
                    $classTraitPattern = '/(class\s+User\b[^{]*\{.*?^\s*use\s+)([^;]+);/ms';
                    if (preg_match($classTraitPattern, $userModelContent)) {
                        $userModelContent = preg_replace($classTraitPattern, '$1$2, HasSlicePermissions;', $userModelContent, 1);
                    } else {
                        $userModelContent = preg_replace(
                            '/(class\s+User\b[^{]*\{)/',
                            "$1\n    use HasSlicePermissions;\n",
                            $userModelContent,
                            1
                        );
                    }

                    $userModelContent = preg_replace(
                        '/(namespace\s+App\\\\Models;)/',
                        "$1\n\nuse LaraSlice\\Core\\Security\\Traits\\HasSlicePermissions;",
                        $userModelContent,
                        1
                    );
                    File::put($userModelFile, $userModelContent);
                }
            }

            return true;
        });

        // 5. Ensure frontend dependencies & run npm install
        if (!$this->option('skip-npm') && File::exists(base_path('package.json'))) {
            $needed = [
                '@alpinejs/anchor' => '^3.14.8',
                '@alpinejs/collapse' => '^3.14.8',
                '@alpinejs/focus' => '^3.14.8',
                '@floating-ui/dom' => '^1.6.12',
                '@tailwindcss/vite' => '^4.0.0',
                'alpinejs' => '^3.14.8',
                'tailwindcss' => '^4.0.0',
            ];

            $this->components->task('Configuring frontend packages in package.json', function () use ($needed) {
                $pkgPath = base_path('package.json');
                $pkg = json_decode(File::get($pkgPath), true);
                if (is_array($pkg)) {
                    $changed = false;
                    foreach ($needed as $dep => $ver) {
                        if (!isset($pkg['devDependencies'][$dep]) && !isset($pkg['dependencies'][$dep])) {
                            $pkg['devDependencies'][$dep] = $ver;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        File::put($pkgPath, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    }
                }
                return true;
            });

            // blatui.css uses Tailwind v4 (`@import 'tailwindcss'`), which needs the Vite plugin
            $viteConfigPath = collect(['vite.config.js', 'vite.config.mjs', 'vite.config.ts'])
                ->map(fn ($file) => base_path($file))
                ->first(fn ($path) => File::exists($path));

            if ($viteConfigPath && !str_contains(File::get($viteConfigPath), '@tailwindcss/vite')) {
                $this->components->task('Registering Tailwind CSS v4 plugin in ' . basename($viteConfigPath), function () use ($viteConfigPath) {
                    $viteConfig = File::get($viteConfigPath);
                    if (!preg_match('/plugins\s*:\s*\[/', $viteConfig)) {
                        $this->components->warn("Add `tailwindcss()` from '@tailwindcss/vite' to the Vite plugins manually.");
                        return false;
                    }

                    $viteConfig = preg_replace('/plugins\s*:\s*\[/', "$0\n        tailwindcss(),", $viteConfig, 1);
                    $viteConfig = preg_replace('/^(import\s.+;[ \t]*\R)(?!\s*import\s)/m', "$1import tailwindcss from '@tailwindcss/vite';\n", $viteConfig, 1);
                    File::put($viteConfigPath, $viteConfig);

                    return true;
                });
            }

            $missingNodeModules = collect(array_keys($needed))
                ->contains(fn ($dep) => !File::exists(base_path('node_modules/' . $dep)));

            if ($missingNodeModules) {
                $this->components->task('Installing Node dependencies (npm install)', function () {
                    try {
                        $process = \Symfony\Component\Process\Process::fromShellCommandline('npm install', base_path());
                        $process->setTimeout(600);
                        $process->run();
                        return $process->isSuccessful();
                    } catch (\Throwable $e) {
                        $this->components->warn('npm install skipped: ' . $e->getMessage());
                        return false;
                    }
                });
            }
        }

        $this->newLine();
        $this->components->info('🎉 LaraSlice installed and configured successfully!');
        $this->table(
            ['Resource', 'URL / Detail'],
            [
                ['Landing Page', url('/')],
                ['Login Portal', url('/login')],
                ['Blueprint Studio', url('/laraslice/wizard')],
                ['Default Email', $email],
                ['Default Password', $password],
            ]
        );

        $this->line('<fg=gray>Run <fg=yellow>php artisan serve</> and visit <fg=cyan>' . url('/login') . '</> to begin!</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
