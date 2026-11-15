<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Tests\TestCase;

/**
 * Table MultiSelect seat, runtime: the service diffs the
 * submitted target-id set against the child table inside
 * the save transaction — new selections insert child rows,
 * removed selections delete them, kept selections keep
 * their rows; an id that does not exist in the target
 * slice's table rejects the save; an emptied-but-provided
 * selection clears the rows; getItemById loads the stored
 * selection back into the form BO.
 */
class MultiselectSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A real target slice so SliceRecordOptions can
        // resolve its table through the manager.
        app(SliceGenerator::class)->generate('ProbeReason', [
            ['name' => 'label', 'type' => 'string', 'label' => 'Label'],
        ], false, ['table' => 'probe_reasons']);
        app(SliceManager::class)->discover(true);

        // The harness migrates the generated slice's own
        // table on discovery; create it only if absent.
        if (! Schema::hasTable('probe_reasons')) {
            Schema::create('probe_reasons', function (Blueprint $table) {
                $table->increments('id');
                $table->string('label')->nullable();
            });
        }
        DB::table('probe_reasons')->insert([
            ['id' => 1, 'label' => 'Price'],
            ['id' => 2, 'label' => 'Timing'],
            ['id' => 3, 'label' => 'Fit'],
        ]);
        Schema::create('probe_deals', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('probe_deal_reasons', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('deal_id');
            $table->unsignedInteger('reason');
            $table->string('parent_type')->nullable();
            $table->timestamps();
        });
    }

    private function service(): BaseSliceService
    {
        $modelClass = get_class(new class extends Model
        {
            protected $table = 'probe_deals';

            protected $guarded = [];

            public $timestamps = false;
        });

        return new class($modelClass) extends BaseSliceService
        {
            public function __construct(private string $probeModelClass) {}

            protected function getModelClass(): string
            {
                return $this->probeModelClass;
            }

            protected function mapToForm(Model $model): IBusinessObject
            {
                $form = new class extends BaseFormBusinessObject
                {
                    public ?string $name = null;

                    public array $picks = [];
                };
                $form->id = $model->getKey();
                $form->name = $model->getAttribute('name');

                return $form;
            }

            protected function mapToListing(Model $model): IBusinessObject
            {
                return new class extends BaseFormBusinessObject {};
            }

            public function multiselectDefinitions(): array
            {
                return [
                    'picks' => [
                        'table' => 'probe_deal_reasons',
                        'foreign_key' => 'deal_id',
                        'target_field' => 'reason',
                        'target' => 'ProbeReasons',
                        'parent_type' => 'Deal',
                    ],
                ];
            }
        };
    }

    private function form(?int $id, array $picks, bool $provided = true): BaseFormBusinessObject
    {
        // Built the way the web controller builds it:
        // fromArray provenance (providedFields = []) plus
        // the marker capture's markProvided for the
        // emptied-submits-nothing case.
        $form = (new class extends BaseFormBusinessObject
        {
            public ?string $name = 'Deal One';

            public array $picks = [];
        })::fromArray(['name' => 'Deal One', 'picks' => $picks]);
        $form->id = $id;
        $form->picks = $picks;
        if ($provided) {
            $form->markProvided('picks');
        }

        return $form;
    }

    private function storedReasons(int $dealId): array
    {
        return DB::table('probe_deal_reasons')->where('deal_id', $dealId)
            ->orderBy('reason')->pluck('reason')->map(fn ($v) => (int) $v)->all();
    }

    public function test_create_inserts_child_rows_with_parent_type(): void
    {
        $id = (int) $this->service()->save($this->form(null, [1, 2]));

        $this->assertSame([1, 2], $this->storedReasons($id));
        $this->assertSame('Deal', DB::table('probe_deal_reasons')->where('deal_id', $id)->first()->parent_type);
    }

    public function test_update_diffs_the_selection(): void
    {
        $service = $this->service();
        $id = (int) $service->save($this->form(null, [1, 2]));
        $keptRowId = (int) DB::table('probe_deal_reasons')
            ->where('deal_id', $id)->where('reason', 2)->value('id');

        $service->save($this->form($id, [2, 3]));

        $this->assertSame([2, 3], $this->storedReasons($id));
        $this->assertSame($keptRowId, (int) DB::table('probe_deal_reasons')
            ->where('deal_id', $id)->where('reason', 2)->value('id'));
    }

    public function test_emptied_but_provided_selection_clears_rows(): void
    {
        $service = $this->service();
        $id = (int) $service->save($this->form(null, [1, 2]));

        $service->save($this->form($id, []));

        $this->assertSame([], $this->storedReasons($id));
    }

    public function test_unknown_target_id_rejects_the_save(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->save($this->form(null, [1, 99]));
    }

    public function test_get_item_by_id_loads_the_selection(): void
    {
        $service = $this->service();
        $id = (int) $service->save($this->form(null, [3, 1]));

        $form = $service->getItemById($id);

        $picks = array_values(array_map('intval', $form->picks));
        sort($picks);
        $this->assertSame([1, 3], $picks);
    }

    public function test_picker_labels_come_from_the_target_slices_declared_identity_field(): void
    {
        // A target slice whose identity lives in a stem
        // column (probe_causes.probe_cause) — no name/title/
        // label/code column and no *_name column — must
        // still label its records in pickers instead of
        // falling back to "#id".
        app(SliceGenerator::class)->generate('ProbeCause', [
            ['name' => 'probe_cause', 'type' => 'string', 'label' => 'Cause'],
        ], false, ['table' => 'probe_causes']);
        app(SliceManager::class)->discover(true);
        if (! Schema::hasTable('probe_causes')) {
            Schema::create('probe_causes', function (Blueprint $table) {
                $table->increments('id');
                $table->string('probe_cause')->nullable();
            });
        }
        DB::table('probe_causes')->insert([
            ['id' => 1, 'probe_cause' => 'Stem Label One'],
            ['id' => 2, 'probe_cause' => 'Stem Label Two'],
        ]);

        $options = \LaraSlice\Support\SliceRecordOptions::forSlice('ProbeCauses');

        $this->assertSame('Stem Label One', $options[1] ?? null);
        $this->assertSame('Stem Label Two', $options[2] ?? null);
    }
}
