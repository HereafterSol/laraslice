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
 * A declared repeater whose table does not carry the parent
 * foreign key is a belongsTo lookup modelled as a child
 * collection (a porter modelling error seen in the wild on
 * ticket_channels / lead_sources / contract_types /
 * invoice_recurring_items). The save path must skip such a
 * handle instead of failing the whole save with an
 * unknown-column error — the read and delete paths already
 * tolerate it.
 */
class RepeaterSaveGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('probe_parents', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('probe_lookups', function (Blueprint $table) {
            $table->increments('id');
            $table->string('label')->nullable();
            // deliberately NO probe_parent_id column
        });
    }

    public function test_save_skips_repeater_whose_table_lacks_the_parent_fk(): void
    {
        $modelClass = get_class(new class extends Model
        {
            protected $table = 'probe_parents';

            protected $guarded = [];

            public $timestamps = false;
        });

        $form = new class extends BaseFormBusinessObject
        {
            public ?string $name = 'Parent One';

            public array $probe_lookups = [['label' => 'Email']];
        };

        $service = new class($modelClass) extends BaseSliceService
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
                    'probe_lookups' => [
                        'table' => 'probe_lookups',
                        'foreign_key' => 'probe_parent_id',
                        'fields' => ['label' => ['type' => 'string', 'required' => false, 'options' => []]],
                    ],
                ];
            }
        };

        $id = $service->save($form);

        $this->assertSame(1, (int) $id);
        $this->assertSame('Parent One', DB::table('probe_parents')->where('id', $id)->value('name'));
        $this->assertSame(0, DB::table('probe_lookups')->count());
    }
}
