<?php

namespace LaraSlice\Tests\Unit\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Support\DataTableRows;
use LaraSlice\Support\DirectoryUi;
use LaraSlice\Support\SettingsUi;
use LaraSlice\Tests\TestCase;

/**
 * Bound settings CONSUMPTION (closure programme Item 2):
 * the hub writes the ported settings tables; these are the
 * framework readers that make the values drive the app —
 * company identity on documents, the app name in titles,
 * the currency format in money rendering, theme colours in
 * the chrome tokens, and the storage row in the filesystem
 * config. Resolution mirrors the source: the hub-bound
 * currency_format_settings row first, then the company's
 * currency row (the store the source's currency_format()
 * reads); the source's theme header_color as the chrome
 * accent; the enabled file_storage_settings row as the
 * disk selector. Without the tables, everything falls back
 * to the pre-wiring behaviour byte-for-byte.
 */
class SettingsConsumptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SettingsUi::forgetBoundSettings();
        config()->set('app.name', 'Fallback Co');
    }

    protected function tearDown(): void
    {
        SettingsUi::forgetBoundSettings();
        parent::tearDown();
    }

    protected function createIdentityTables(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->nullable();
            $table->string('app_name')->nullable();
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->string('company_email')->nullable();
            $table->string('company_phone')->nullable();
            $table->text('address')->nullable();
            $table->string('website')->nullable();
        });
        Schema::create('global_settings', function (Blueprint $table) {
            $table->id();
            $table->string('global_app_name')->nullable();
        });
    }

    protected function createCurrencyTables(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('currency_symbol')->nullable();
            $table->string('currency_position')->nullable();
            $table->integer('no_of_decimal')->nullable();
            $table->string('thousand_separator')->nullable();
            $table->string('decimal_separator')->nullable();
        });
        Schema::create('currency_format_settings', function (Blueprint $table) {
            $table->id();
            $table->string('currency_position')->nullable();
            $table->integer('no_of_decimal')->nullable();
            $table->string('thousand_separator')->nullable();
            $table->string('decimal_separator')->nullable();
        });
    }

    public function test_names_fall_back_to_config_without_tables(): void
    {
        $this->assertSame('Fallback Co', SettingsUi::appName());
        $this->assertSame('Fallback Co', SettingsUi::companyName());
    }

    public function test_app_name_prefers_global_settings_then_company_app_name(): void
    {
        $this->createIdentityTables();
        DB::table('companies')->insert(['company_name' => 'Acme Ltd', 'app_name' => 'Acme App']);
        SettingsUi::forgetBoundSettings();
        $this->assertSame('Acme App', SettingsUi::appName());
        $this->assertSame('Acme Ltd', SettingsUi::companyName());

        DB::table('global_settings')->insert(['global_app_name' => 'Global Name']);
        SettingsUi::forgetBoundSettings();
        $this->assertSame('Global Name', SettingsUi::appName());
        $this->assertSame('Acme Ltd', SettingsUi::companyName());
    }

    public function test_money_defaults_match_the_pre_wiring_rendering(): void
    {
        // No bound tables at all: the declared defaults are
        // PHP's number_format defaults, symbol on the left.
        $this->assertSame('17,848.00', SettingsUi::formatAmount(17848));
        $this->assertSame('PKR17,848.00', SettingsUi::money(17848, 'PKR'));
        $this->assertSame('PKR17,848.00', DataTableRows::money(17848));
        $html = DataTableRows::moneyBreakdown(25252, null, 'PKR', 0);
        $this->assertStringContainsString('Total: <span class="font-bold">PKR25,252.00</span>', $html);
    }

    public function test_currency_format_comes_from_the_company_currency_row(): void
    {
        $this->createIdentityTables();
        $this->createCurrencyTables();
        DB::table('currencies')->insert([
            ['id' => 1, 'currency_symbol' => 'PKR', 'currency_position' => 'left', 'no_of_decimal' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.'],
            ['id' => 2, 'currency_symbol' => '£', 'currency_position' => 'right_with_space', 'no_of_decimal' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.'],
        ]);
        DB::table('companies')->insert(['company_name' => 'Acme Ltd', 'currency_id' => 2]);
        SettingsUi::forgetBoundSettings();

        $format = SettingsUi::currencyFormat();
        $this->assertSame('right_with_space', $format['position']);
        $this->assertSame('£', $format['symbol']);
        $this->assertSame('100.00 £', SettingsUi::money(100));
    }

    public function test_hub_format_row_wins_and_drives_positions_and_separators(): void
    {
        $this->createCurrencyTables();
        DB::table('currencies')->insert(['id' => 1, 'currency_symbol' => 'PKR', 'currency_position' => 'left', 'no_of_decimal' => 2, 'thousand_separator' => ',', 'decimal_separator' => '.']);
        DB::table('currency_format_settings')->insert(['currency_position' => 'right_with_space', 'no_of_decimal' => 2, 'thousand_separator' => '.', 'decimal_separator' => ',']);
        SettingsUi::forgetBoundSettings();

        $this->assertSame('17.848,00', SettingsUi::formatAmount(17848));
        $this->assertSame('17.848,00 PKR', SettingsUi::money(17848, 'PKR'));
        $this->assertSame('17.848,00 PKR', DataTableRows::money(17848, (object) ['currency' => 'PKR']));

        DB::table('currency_format_settings')->update(['currency_position' => 'left_with_space']);
        SettingsUi::forgetBoundSettings();
        $this->assertSame('PKR 17.848,00', SettingsUi::money(17848, 'PKR'));

        DB::table('currency_format_settings')->update(['currency_position' => 'right']);
        SettingsUi::forgetBoundSettings();
        $this->assertSame('17.848,00PKR', SettingsUi::money(17848, 'PKR'));
    }

    public function test_theme_css_variables_map_the_declared_fields_and_drop_unsafe_values(): void
    {
        Schema::create('theme_settings', function (Blueprint $table) {
            $table->id();
            $table->string('panel');
            $table->string('header_color')->nullable();
            $table->string('sidebar_color')->nullable();
            $table->string('sidebar_text_color')->nullable();
            $table->string('link_color')->nullable();
        });
        DB::table('theme_settings')->insert([
            ['panel' => 'admin', 'header_color' => '#FF0000', 'sidebar_color' => '#111111', 'sidebar_text_color' => '#EEEEEE', 'link_color' => '#00FF00'],
            ['panel' => 'employee', 'header_color' => '#0000FF', 'sidebar_color' => '#222222', 'sidebar_text_color' => '#DDDDDD', 'link_color' => '#00FF00'],
        ]);
        SettingsUi::forgetBoundSettings();

        $css = SettingsUi::themeCssVariables('admin');
        $this->assertStringContainsString('--primary: #FF0000;', $css);
        $this->assertStringContainsString('--sidebar: #111111;', $css);
        $this->assertStringContainsString('--sidebar-foreground: #EEEEEE;', $css);
        // link_color is stored by the source but rendered by no
        // source view — deliberately not mapped.
        $this->assertStringNotContainsString('#00FF00', $css);
        $this->assertStringContainsString('--primary: #0000FF;', SettingsUi::themeCssVariables('employee'));

        DB::table('theme_settings')->where('panel', 'admin')->update(['header_color' => 'red; } body { display: none']);
        SettingsUi::forgetBoundSettings();
        $this->assertStringNotContainsString('display: none', SettingsUi::themeCssVariables('admin'));
        $this->assertStringNotContainsString('--primary:', SettingsUi::themeCssVariables('admin'));
    }

    public function test_storage_row_selects_the_default_disk(): void
    {
        config()->set('filesystems.default', 'local');
        // No table: boot-time application is a no-op.
        SettingsUi::applyStorageSettings();
        $this->assertSame('local', config('filesystems.default'));

        Schema::create('file_storage_settings', function (Blueprint $table) {
            $table->id();
            $table->string('filesystem');
            $table->text('auth_keys')->nullable();
            $table->string('status');
        });
        DB::table('file_storage_settings')->insert(['filesystem' => 'local', 'auth_keys' => null, 'status' => 'enabled']);
        SettingsUi::forgetBoundSettings();
        SettingsUi::applyStorageSettings();
        $this->assertSame('local', config('filesystems.default'));

        DB::table('file_storage_settings')->update([
            'filesystem' => 'aws_s3',
            'auth_keys' => json_encode(['driver' => 's3', 'key' => 'K', 'secret' => 'S', 'region' => 'eu-west-1', 'bucket' => 'probe-bucket']),
        ]);
        SettingsUi::forgetBoundSettings();
        SettingsUi::applyStorageSettings();
        $this->assertSame('s3', config('filesystems.default'));
        $this->assertSame('probe-bucket', config('filesystems.disks.s3.bucket'));
        $this->assertSame('eu-west-1', config('filesystems.disks.s3.region'));

        config()->set('filesystems.default', 'local');
    }

    public function test_document_builder_carries_the_company_identity(): void
    {
        $this->createIdentityTables();
        DB::table('companies')->insert(['company_name' => 'Acme Ltd', 'company_email' => 'billing@acme.test']);
        SettingsUi::forgetBoundSettings();

        $manifest = [
            'name' => 'Invoice',
            'models' => [['fields' => [
                ['handle' => 'amount', 'type' => 'decimal', 'label' => 'Amount'],
            ]]],
            'ui' => ['show' => ['document' => ['title' => 'Tax Invoice', 'totals' => ['amount']]]],
        ];
        $record = new class(['amount' => 1000])
        {
            public function __construct(private array $attrs) {}

            public function getAttributes(): array
            {
                return $this->attrs;
            }

            public function __get(string $name): mixed
            {
                return null;
            }
        };

        $doc = DirectoryUi::documentFromManifest($manifest, $record);
        $this->assertNotNull($doc);
        $this->assertSame('Acme Ltd', $doc['company']['name']);
        $this->assertSame('billing@acme.test', $doc['company']['email']);
        // Document totals obey the same effective currency format.
        $this->assertSame('1,000.00', $doc['totals'][0]['value']);
    }
}
