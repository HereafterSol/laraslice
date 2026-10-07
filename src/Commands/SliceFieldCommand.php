<?php

namespace LaraSlice\Commands;

use Illuminate\Console\Command;
use LaraSlice\Generator\SliceModifier;

class SliceFieldCommand extends Command
{
    protected $signature = 'slice:field 
                            {slice : The target slice name (e.g. Product, User, Invoice)}
                            {field? : Field column name or comma-separated list (e.g. price, "sku:string,stock:integer:default=0")}
                            {type? : Field data type (string, text, integer, decimal, boolean, date, datetime, json, uuid)}
                            {--nullable : Make the field nullable (default: true)}
                            {--batch : Interactively define multiple fields in a single consolidated migration}
                            {--migrate : Automatically run migrations after adding the field}';

    protected $description = 'Enhance an existing slice with new field(s) in a single consolidated migration (October CMS Builder style)';

    public function handle(): int
    {
        $slice = $this->argument('slice');
        $field = $this->argument('field');
        $type  = $this->argument('type');
        $batch = $this->option('batch');

        $fieldsToAdd = [];

        // 1. Batch interactive mode
        if ($batch) {
            $this->info("🛠️ Batch Schema Builder for Slice [{$slice}] (October CMS Builder Style)");
            $this->line("Add as many columns as you want. When finished, ONE consolidated migration will be generated.\n");

            do {
                $colName = $this->ask("Field column name (leave empty to finish)");
                if (empty($colName)) {
                    break;
                }

                $colType = $this->choice(
                    "Data type for '{$colName}'",
                    ['string', 'text', 'mediumText', 'longText', 'integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'boolean', 'decimal', 'float', 'double', 'date', 'datetime', 'timestamp', 'time', 'json', 'uuid'],
                    0
                );

                $nullable = $this->confirm("Make '{$colName}' nullable?", true);
                $default = $this->ask("Default value (press Enter for none)", null);

                $fieldsToAdd[] = [
                    'name'     => $colName,
                    'type'     => $colType,
                    'nullable' => $nullable,
                    'default'  => $default,
                ];

                $this->line("<fg=green>✓</> Added [{$colName}: {$colType}] to batch list. (" . count($fieldsToAdd) . " field(s) queued)\n");
            } while (true);

            if (empty($fieldsToAdd)) {
                $this->warn("No fields specified. Aborting.");
                return Command::SUCCESS;
            }
        }
        // 2. Comma-separated list passed in argument: "sku:string,stock:integer:default=0,notes:text"
        elseif (!empty($field) && str_contains($field, ',')) {
            $parts = explode(',', $field);
            foreach ($parts as $part) {
                $subParts = explode(':', trim($part));
                $colName = trim($subParts[0]);
                $colType = trim($subParts[1] ?? 'string');
                $nullable = true;
                $default = null;

                for ($i = 2; $i < count($subParts); $i++) {
                    $opt = trim($subParts[$i]);
                    if ($opt === 'not-null' || $opt === 'required') {
                        $nullable = false;
                    } elseif (str_starts_with($opt, 'default=')) {
                        $default = substr($opt, 8);
                    }
                }

                $fieldsToAdd[] = [
                    'name'     => $colName,
                    'type'     => $colType,
                    'nullable' => $nullable,
                    'default'  => $default,
                ];
            }
        }
        // 3. Single field mode
        else {
            if (empty($field)) {
                $field = $this->ask("What field name would you like to add to [{$slice}]? (e.g. price, sku, category)");
                while (empty($field)) {
                    $field = $this->ask("Field name is required");
                }
            }

            if (empty($type)) {
                $type = $this->choice(
                    "Data type for '{$field}'",
                    ['string', 'text', 'mediumText', 'longText', 'integer', 'bigInteger', 'smallInteger', 'tinyInteger', 'boolean', 'decimal', 'float', 'double', 'date', 'datetime', 'timestamp', 'time', 'json', 'uuid'],
                    0
                );
            }

            $fieldsToAdd[] = [
                'name'     => $field,
                'type'     => $type,
                'nullable' => (bool) ($this->option('nullable') ?? true),
            ];
        }

        $fieldCount = count($fieldsToAdd);
        $this->info("⚡ Applying batch schema evolution ({$fieldCount} column(s)) to Slice [{$slice}]...");

        try {
            $modifier = new SliceModifier();
            $result = $modifier->addFieldsBatch($slice, $fieldsToAdd, 'Developer via CLI');

            $this->line("<fg=green>✓</> Generated <comment>1 consolidated migration</comment>: <comment>" . basename($result['migration']) . "</comment>");
            $this->line("<fg=green>✓</> Updated Form and Listing DTOs for all {$fieldCount} field(s)");
            $this->line("<fg=green>✓</> Updated BlatUI Blade Form & Table views");
            $this->line("<fg=green>✓</> Incremented slice version to <comment>v{$result['version']}</comment> in slice.json");
            $this->line("<fg=green>✓</> Appended unified version history record: \"{$result['description']}\"");

            if ($this->option('migrate') || $this->confirm('Would you like to run the consolidated migration now?', false)) {
                $this->call('migrate');
                $this->info("✓ Database table updated successfully in a single query pass!");
            } else {
                $this->comment("Reminder: Run 'php artisan migrate' to apply changes to the database.");
            }

            $this->newLine();
            $this->info("🎉 [{$slice}] slice enhanced with {$fieldCount} field(s) in a single migration!");

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to add field(s): " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

