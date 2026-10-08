<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Generator\FlutterSliceGenerator;
use LaraSlice\Generator\SliceGenerator;

class SliceWizardCommand extends Command
{
    protected $signature = 'slice:wizard 
                            {--web : Automatically start dev server and open the web wizard in your default browser}';

    protected $description = 'Interactive terminal wizard to step-by-step configure and generate a vertical slice (or launch web wizard)';

    public function handle(): int
    {
        $this->info('=================================================');
        $this->info('       🚀 LaraSlice Interactive Wizard 🚀         ');
        $this->info('=================================================');

        // Option 1: Web Wizard launcher
        if ($this->option('web')) {
            return $this->launchWebWizard();
        }

        $mode = $this->choice(
            'How would you like to run the wizard?',
            ['Terminal (Interactive CLI Prompts)', 'Browser (Open visual Web Wizard UI)'],
            0
        );

        if ($mode === 'Browser (Open visual Web Wizard UI)') {
            return $this->launchWebWizard();
        }

        // Option 2: Step-by-step Interactive CLI Wizard
        $this->newLine();
        $this->comment('Step 1: Slice Identity');
        $name = $this->ask('Enter the Slice Name (e.g. Invoice, Project, Ticket, Customer)');
        while (empty($name)) {
            $name = $this->ask('Slice name is required. Please enter a name');
        }
        $name = ucfirst(trim($name));

        $this->newLine();
        $this->comment('Step 2: Define Entity Fields');
        $fields = [];
        $addMore = true;

        // Default title/name field
        $defaultField = $this->ask('First field name', 'title');
        $defaultType = $this->choice("Type for '{$defaultField}'", ['string', 'text', 'integer', 'decimal', 'boolean', 'date'], 0);
        $fields[] = ['name' => $defaultField, 'type' => $defaultType, 'required' => true];

        while ($this->confirm('Would you like to add another field?', false)) {
            $fieldName = $this->ask('Field name (snake_case, e.g. amount, due_date, status)');
            if (! empty($fieldName)) {
                $fieldType = $this->choice("Field type for '{$fieldName}'", ['string', 'text', 'integer', 'decimal', 'boolean', 'date'], 0);
                $isRequired = $this->confirm("Is '{$fieldName}' required?", true);
                $fields[] = [
                    'name' => trim($fieldName),
                    'type' => $fieldType,
                    'required' => $isRequired,
                ];
            }
        }

        $this->newLine();
        $this->comment('Step 3: Workflow State Machine');
        $includeWorkflow = $this->confirm('Enable state machine workflow (draft -> active -> archived)?', true);

        $this->newLine();
        $this->comment('Step 4: Cross-Platform Client');
        $includeFlutter = $this->confirm('Generate matching Flutter client slice (Model, Service, UI)?', true);

        // Summary
        $this->newLine();
        $this->info("Generating Slice [{$name}] with ".count($fields).' fields...');

        $generator = new SliceGenerator;
        $sliceDir = $generator->generate($name, $fields, $includeWorkflow);

        $this->line("<fg=green>✓</> Created Slice Manifest: <comment>{$sliceDir}/slice.json</comment>");
        $this->line('<fg=green>✓</> Generated Contracts & DTOs');
        $this->line('<fg=green>✓</> Generated Model & Database Migration');
        $this->line('<fg=green>✓</> Generated Slice Service (CRUD & Search)');
        $this->line('<fg=green>✓</> Generated Controllers (Web BlatUI & REST API)');
        $this->line('<fg=green>✓</> Generated BlatUI Blade Views');

        if ($includeFlutter) {
            $flutterGen = new FlutterSliceGenerator;
            $flutterDir = $flutterGen->generate($name);
            $this->line("<fg=cyan>✓</> Generated Flutter Client Slice: <comment>{$flutterDir}</comment>");
        }

        $this->newLine();
        $this->info("🎉 Slice [{$name}] created successfully!");
        $this->comment("Next: Run 'php artisan migrate' to apply the database schema.");

        return Command::SUCCESS;
    }

    protected function launchWebWizard(): int
    {
        $url = 'http://localhost:8000/laraslice/wizard';
        $this->info("🌐 Opening LaraSlice Visual Web Wizard at: {$url}");

        // Open in default OS browser
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen("start {$url}", 'r'));
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            pclose(popen("open {$url}", 'r'));
        } else {
            pclose(popen("xdg-open {$url}", 'r'));
        }

        $this->comment("Make sure your Laravel local server is running: 'php artisan serve'");

        return Command::SUCCESS;
    }
}
