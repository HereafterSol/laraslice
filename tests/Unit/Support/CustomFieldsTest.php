<?php

namespace LaraSlice\Tests\Unit\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Support\CustomFields;
use LaraSlice\Support\DirectoryUi;
use LaraSlice\Support\Numbering;
use LaraSlice\Support\SettingsUi;
use LaraSlice\Tests\TestCase;

/**
 * The bound-model fixture is named Project on purpose: groups
 * bind by model BASENAME (the carried declaration holds the
 * source's class names), so the fixture's basename must be
 * the group's — 'Project'.
 */
class Project extends Model
{
    protected $table = 'cf_projects';

    protected $guarded = [];

    public $timestamps = false;
}

class CfEmployeeFixture extends Model
{
    protected $table = 'cf_employees';

    protected $guarded = [];

    public $timestamps = false;

    public function passportDetails()
    {
        return $this->hasMany(CfPassportFixture::class, 'user_id');
    }

    public function immigration()
    {
        return $this->hasMany(CfVisaFixture::class, 'user_id');
    }
}

class CfPassportFixture extends Model
{
    protected $table = 'cf_passports';

    protected $guarded = [];

    public $timestamps = false;
}

class CfVisaFixture extends Model
{
    protected $table = 'cf_visas';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * Custom fields runtime (closure completion, 8 Nov 2026): the
 * carried custom_field_groups / custom_fields /
 * custom_fields_data tables drive slice forms and show pages
 * the way the source's CustomFieldsTrait does — groups bind
 * to a model by name, a select stores its option INDEX, a
 * checkbox stores its choices joined with ', ', required
 * fields validate, and slugs derive from labels unique
 * within their group. Also covers the C1 numbering reader
 * and the DirectoryUi stack tab the passport composition
 * rides on. Without the tables, everything is inert.
 */
class CustomFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SettingsUi::forgetBoundSettings();
    }

    protected function tearDown(): void
    {
        SettingsUi::forgetBoundSettings();
        parent::tearDown();
    }

    protected function createCustomFieldTables(): void
    {
        Schema::create('custom_field_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('name');
            $table->string('model');
        });
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('custom_field_group_id');
            $table->string('label', 100);
            $table->string('name', 100);
            $table->string('type', 10);
            $table->string('required', 10)->default('no');
            $table->text('values')->nullable();
            $table->boolean('export')->default(false);
            $table->string('visible', 10)->default('false');
        });
        Schema::create('custom_fields_data', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('custom_field_id');
            $table->unsignedBigInteger('model_id');
            $table->string('model');
            $table->text('value')->nullable();
        });
    }

    protected function seedGroupAndFields(): void
    {
        DB::table('custom_field_groups')->insert([
            'id' => 3, 'company_id' => 1, 'name' => 'Project',
            'model' => 'App\\Models\\Project',
        ]);
        DB::table('custom_fields')->insert([
            [
                'id' => 11, 'company_id' => 1, 'custom_field_group_id' => 3,
                'label' => 'Risk Level', 'name' => 'risk-level', 'type' => 'select',
                'required' => 'yes', 'values' => json_encode(['Low', 'High']),
                'export' => 0, 'visible' => 'true',
            ],
            [
                'id' => 12, 'company_id' => 1, 'custom_field_group_id' => 3,
                'label' => 'Tags', 'name' => 'tags', 'type' => 'checkbox',
                'required' => 'no', 'values' => json_encode(['Alpha', 'Beta']),
                'export' => 0, 'visible' => 'false',
            ],
            [
                'id' => 13, 'company_id' => 1, 'custom_field_group_id' => 3,
                'label' => 'Notes', 'name' => 'notes', 'type' => 'text',
                'required' => 'no', 'values' => null,
                'export' => 0, 'visible' => 'false',
            ],
        ]);
    }

    public function test_runtime_is_inert_without_the_tables(): void
    {
        $this->assertSame([], CustomFields::fieldsForModel(Project::class));
        $this->assertSame([], CustomFields::valuesFor(Project::class, 1));
        $this->assertSame([], CustomFields::rules([]));
    }

    public function test_group_binds_by_model_basename_and_decodes_options(): void
    {
        $this->createCustomFieldTables();
        $this->seedGroupAndFields();

        $fields = CustomFields::fieldsForModel(Project::class);

        $this->assertCount(3, $fields);
        $this->assertSame('risk-level', $fields[0]['name']);
        $this->assertSame(['Low', 'High'], $fields[0]['options_list']);
        $this->assertSame('risk-level_11', CustomFields::inputKey($fields[0]));
    }

    public function test_rules_follow_the_source_required_semantics(): void
    {
        $this->createCustomFieldTables();
        $this->seedGroupAndFields();
        $fields = CustomFields::fieldsForModel(Project::class);

        $rules = CustomFields::rules($fields);

        // The source trait adds a rule only for required fields.
        $this->assertSame('required', $rules['custom_fields_data.risk-level_11']);
        $this->assertArrayNotHasKey('custom_fields_data.tags_12', $rules);
        $this->assertArrayNotHasKey('custom_fields_data.notes_13', $rules);
        $attributes = CustomFields::ruleAttributes($fields);
        $this->assertSame('Risk Level', $attributes['custom_fields_data.risk-level_11']);
    }

    public function test_persist_round_trip_with_select_index_and_checkbox_join(): void
    {
        $this->createCustomFieldTables();
        $this->seedGroupAndFields();

        CustomFields::persist(Project::class, 42, [
            'risk-level_11' => '1',
            'tags_12' => ['Alpha', 'Beta'],
            'notes_13' => 'hello',
        ]);

        $values = CustomFields::valuesFor(Project::class, 42);
        $this->assertSame('1', $values['field_11']);
        $this->assertSame('Alpha, Beta', $values['field_12']);
        $this->assertSame('hello', $values['field_13']);
        // The stored model identity is the app's own model class.
        $this->assertSame(
            Project::class,
            DB::table('custom_fields_data')->where('custom_field_id', 11)->value('model')
        );

        // An edit upserts the same rows — no duplicates.
        CustomFields::persist(Project::class, 42, ['risk-level_11' => '0']);
        $this->assertSame(3, DB::table('custom_fields_data')->count());
        $this->assertSame('0', CustomFields::valuesFor(Project::class, 42)['field_11']);
    }

    public function test_display_value_resolves_select_index_and_empty(): void
    {
        $this->createCustomFieldTables();
        $this->seedGroupAndFields();
        $fields = CustomFields::fieldsForModel(Project::class);

        $this->assertSame('High', CustomFields::displayValue($fields[0], '1'));
        $this->assertSame('--', CustomFields::displayValue($fields[0], null));
        $this->assertSame('Alpha, Beta', CustomFields::displayValue($fields[1], 'Alpha, Beta'));
    }

    public function test_unique_slug_suffixes_within_the_group(): void
    {
        $this->createCustomFieldTables();
        $this->seedGroupAndFields();

        $this->assertSame('risk-level-1', CustomFields::uniqueSlug('Risk Level', 3));
        $this->assertSame('risk-level', CustomFields::uniqueSlug('Risk Level', 3, 11));
        $this->assertSame('new-thing', CustomFields::uniqueSlug('New Thing', 3));
    }

    public function test_numbering_formats_from_the_settings_row(): void
    {
        Schema::create('invoice_settings', function (Blueprint $table) {
            $table->id();
            $table->string('contract_prefix')->nullable();
            $table->string('contract_number_separator')->nullable();
            $table->integer('contract_digit')->nullable();
            $table->string('invoice_prefix')->nullable();
            $table->string('invoice_number_separator')->nullable();
            $table->integer('invoice_digit')->nullable();
        });

        // No row yet: the entered number passes through.
        $this->assertSame('7', Numbering::format('contract', '7'));

        DB::table('invoice_settings')->insert([
            'id' => 1, 'contract_prefix' => 'CONT', 'contract_number_separator' => '#', 'contract_digit' => 3,
            'invoice_prefix' => 'INV', 'invoice_number_separator' => '/', 'invoice_digit' => 4,
        ]);
        SettingsUi::forgetBoundSettings();

        $this->assertSame('CONT#007', Numbering::format('contract', '7'));
        $this->assertSame('INV/0042', Numbering::format('invoice', '42'));
        $this->assertSame('007', Numbering::originalPart('contract', 'CONT#007'));
        $this->assertSame('', Numbering::format('contract', ''));
    }

    public function test_stack_tab_enriches_its_blocks(): void
    {
        Schema::create('cf_employees', function (Blueprint $table) {
            $table->id();
        });
        Schema::create('cf_passports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('cf_visas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        $employee = CfEmployeeFixture::create([]);
        CfPassportFixture::create(['user_id' => $employee->id]);
        CfVisaFixture::create(['user_id' => $employee->id]);

        $manifest = [
            'models' => [[
                'name' => 'CfEmployeeFixture',
                'relations' => [
                    ['name' => 'passportDetails', 'type' => 'hasMany'],
                    ['name' => 'immigration', 'type' => 'hasMany'],
                ],
            ]],
            'ui' => ['show' => ['tabs' => [[
                'handle' => 'immigration',
                'label' => 'Immigration',
                'type' => 'relation',
                'render' => 'stack',
                'blocks' => [
                    ['relation' => 'passportDetails', 'label' => 'Passport Details', 'layout' => 'card'],
                    ['relation' => 'immigration', 'label' => 'Visa Details'],
                ],
            ]]]],
        ];

        $tabs = DirectoryUi::relatedTabsFromManifest($manifest, $employee);
        $stack = collect($tabs)->firstWhere('handle', 'immigration');

        $this->assertNotNull($stack);
        $this->assertSame('stack', $stack['render']);
        $this->assertCount(2, $stack['blocks']);
        $this->assertSame('card', $stack['blocks'][0]['layout']);
        $this->assertSame('passportDetails', $stack['blocks'][0]['relation']);
        $this->assertSame('immigration', $stack['blocks'][1]['relation']);
    }
}
