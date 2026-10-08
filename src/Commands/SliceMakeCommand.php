<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use LaraSlice\Generator\FlutterSliceGenerator;
use LaraSlice\Generator\SliceGenerator;
use Throwable;

class SliceMakeCommand extends Command
{
    protected $signature = 'slice:make {name* : Name(s) of the vertical slice(s) (e.g. Product or Companies Contacts Deals)}
                            {--domain= : Domain group for the slice(s) (e.g. CRM, Billing, ECommerce)}
                            {--workflow : Include state machine workflow support}
                            {--field=* : Add a field as name:type (repeatable, e.g. --field=sku:string)}
                            {--flutter : Also generate corresponding Flutter client slice}';

    protected $description = 'Scaffold complete, end-to-end vertical slice module(s) for Laravel and Flutter (LaraSlice style)';

    public function handle(): int
    {
        $names = (array) $this->argument('name');
        $domain = $this->option('domain') ? trim((string) $this->option('domain')) : null;
        $includeWorkflow = (bool) $this->option('workflow');
        $includeFlutter = (bool) $this->option('flutter');
        $fields = [];

        foreach ((array) $this->option('field') as $definition) {
            [$fieldName, $fieldType] = array_pad(explode(':', $definition, 2), 2, 'string');
            $fields[] = ['name' => $fieldName, 'type' => $fieldType];
        }

        $count = count($names);
        if ($domain) {
            $this->info("⚡ Scaffolding {$count} Slice(s) in Domain [{$domain}]: ".implode(', ', $names));
        }

        $generator = new SliceGenerator;
        $flutterGen = $includeFlutter ? new FlutterSliceGenerator : null;
        $failed = 0;
        $createdDirs = [];

        foreach ($names as $name) {
            $prefix = $count > 1 ? "[{$name}] " : '';
            $this->line("⚡ {$prefix}Generating vertical slice...");

            try {
                $sliceDir = $generator->generate($name, $fields, $includeWorkflow, [
                    'domain' => $domain,
                ]);
                $createdDirs[] = $sliceDir;
            } catch (InvalidArgumentException $exception) {
                $this->components->error("{$prefix}".$exception->getMessage());
                $failed++;

                continue;
            } catch (Throwable $exception) {
                $this->components->error("{$prefix}Slice generation failed: ".$exception->getMessage());
                $failed++;

                continue;
            }

            $this->line("<fg=green>✓</> {$prefix}Created Slice Manifest: <comment>{$sliceDir}/slice.json</comment>");
            $this->line("<fg=green>✓</> {$prefix}Created Contracts (DTOs): Form, Listing, Filter");
            $this->line("<fg=green>✓</> {$prefix}Created Model & Migration");
            $this->line("<fg=green>✓</> {$prefix}Created Data Service with CRUD hooks");
            $this->line("<fg=green>✓</> {$prefix}Created Web Controller & REST ApiController");
            $this->line("<fg=green>✓</> {$prefix}Created Routes (web.php & api.php)");
            $this->line("<fg=green>✓</> {$prefix}Created BlatUI Blade Views (index & form)");

            if ($flutterGen) {
                try {
                    $flutterDir = $flutterGen->generate($name);
                    $this->line("<fg=cyan>✓</> {$prefix}Generated Flutter Client Slice: <comment>{$flutterDir}</comment>");
                } catch (Throwable $exception) {
                    $this->components->warn("{$prefix}Flutter generation failed: ".$exception->getMessage());
                }
            }

            $this->line("<fg=green>✓</> {$prefix}Slice [{$name}] ready.");
            if ($count > 1) {
                $this->newLine();
            }
        }

        if ($failed > 0 && $failed === $count) {
            return Command::FAILURE;
        }

        $this->newLine();
        $domainMsg = $domain ? " in domain [{$domain}]" : '';
        $this->info('🎉 Successfully scaffolded '.($count - $failed)." slice(s){$domainMsg}! Run 'php artisan migrate' to apply database tables.");

        return Command::SUCCESS;
    }
}
