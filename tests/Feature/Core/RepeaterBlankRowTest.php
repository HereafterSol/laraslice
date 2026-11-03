<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Tests\TestCase;

/**
 * The form always submits its blank template row for every
 * repeater. A row whose only non-empty value is a boolean
 * toggle (which always submits) is the repeater's empty state,
 * not data: saving must skip it instead of inserting an
 * all-null child — which fails outright when the child table
 * has a NOT NULL column the form does not carry (the ported
 * Projects edit died exactly this way on expenses_recurring).
 */
class RepeaterBlankRowTest extends TestCase
{
    private string $modelClass;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('probe_parents', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('probe_children', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('probe_parent_id');
            $table->string('item_name')->nullable();
            $table->boolean('unlimited')->default(false);
            // NOT one of the form fields (a defaulted column here
            // so the content-row test can insert; the blank-row
            // test pins the skip by child count, not by failure):
            $table->date('issue_date')->default('2026-01-01');
        });

        $this->modelClass = get_class(new class extends Model
        {
            protected $table = 'probe_parents';

            protected $guarded = [];

            public $timestamps = false;
        });
    }

    private function service(): BaseSliceService
    {
        return new class($this->modelClass) extends BaseSliceService
        {
            public function __construct(private string $probeModelClass) {}

            protected function getModelClass(): string
            {
                return $this->probeModelClass;
            }

            protected function mapToForm(Model $model): IBusinessObject
            {
                return new class extends BaseFormBusinessObject {};
            }

            protected function mapToListing(Model $model): IBusinessObject
            {
                return new class extends BaseFormBusinessObject {};
            }

            public function repeaterDefinitions(): array
            {
                return [
                    'probe_children' => [
                        'table' => 'probe_children',
                        'foreign_key' => 'probe_parent_id',
                        'tenant_key' => null,
                        'fields' => [
                            'item_name' => ['type' => 'string', 'required' => false, 'options' => []],
                            'unlimited' => ['type' => 'boolean', 'required' => false, 'options' => []],
                        ],
                    ],
                ];
            }
        };
    }

    public function test_blank_template_row_with_only_a_toggle_set_is_skipped(): void
    {
        $form = new class extends BaseFormBusinessObject
        {
            public ?string $name = 'Parent One';

            public array $probe_children = [['item_name' => '', 'unlimited' => '1']];
        };

        $id = $this->service()->save($form);

        $this->assertSame('Parent One', DB::table('probe_parents')->where('id', $id)->value('name'));
        $this->assertSame(0, DB::table('probe_children')->count());
    }

    public function test_a_row_with_real_content_still_saves(): void
    {
        $form = new class extends BaseFormBusinessObject
        {
            public ?string $name = 'Parent Two';

            public array $probe_children = [['item_name' => 'Hosting', 'unlimited' => '1']];
        };

        $this->service()->save($form);

        $this->assertSame(1, DB::table('probe_children')->count());
        $this->assertSame('Hosting', DB::table('probe_children')->value('item_name'));
    }
}
