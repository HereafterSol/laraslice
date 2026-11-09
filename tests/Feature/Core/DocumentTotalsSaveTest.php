<?php

declare(strict_types=1);

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
 * Document totals on save (Comparative Fix Programme Phase B):
 * line amounts, subtotal, header discount and per-row tax are
 * computed server-side by applyDocumentMath — and the computed
 * header totals must survive save()'s provided-fields filter
 * on updates, because the form renders no subtotal/total
 * input for the client to "provide".
 */
class DocumentTotalsSaveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('probe_docs', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('sub_total', 12, 2)->nullable();
            $table->decimal('discount', 12, 2)->nullable();
            $table->string('discount_type')->nullable();
            $table->string('calculate_tax')->nullable();
            $table->decimal('total', 12, 2)->nullable();
            $table->decimal('due_amount', 12, 2)->nullable();
        });
        Schema::create('probe_doc_items', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('probe_doc_id');
            $table->string('item_name')->nullable();
            $table->decimal('quantity', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('taxes')->nullable();
        });
        Schema::create('taxes', function (Blueprint $table) {
            $table->increments('id');
            $table->decimal('rate_percent', 8, 2);
        });
        DB::table('taxes')->insert(['id' => 1, 'rate_percent' => 10]);
    }

    public function test_create_computes_amounts_totals_and_due(): void
    {
        $form = DocumentTotalsProbeForm::fromArray([
            'probe_items' => [
                ['item_name' => 'sed', 'quantity' => 7, 'unit_price' => 1300],
                ['item_name' => 'similique', 'quantity' => 6, 'unit_price' => 1458],
            ],
        ]);
        $id = (new DocumentTotalsProbeService)->save($form);

        $doc = DB::table('probe_docs')->find($id);
        $this->assertSame(17848.0, (float) $doc->sub_total);
        $this->assertSame(17848.0, (float) $doc->total);
        $this->assertSame(17848.0, (float) $doc->due_amount);
        $amounts = DB::table('probe_doc_items')->where('probe_doc_id', $id)->orderBy('id')->pluck('amount')->all();
        $this->assertSame([9100.0, 8748.0], array_map('floatval', $amounts));
    }

    public function test_update_recomputes_totals_despite_provided_fields_filter(): void
    {
        $create = DocumentTotalsProbeForm::fromArray([
            'probe_items' => [['item_name' => 'sed', 'quantity' => 7, 'unit_price' => 1300]],
        ]);
        $id = (new DocumentTotalsProbeService)->save($create);

        // The web edit submits the items but NOT sub_total/total
        // (no such inputs exist on a document form).
        $update = DocumentTotalsProbeForm::fromArray([
            'id' => $id,
            'probe_items' => [['item_name' => 'sed', 'quantity' => 8, 'unit_price' => 1300]],
        ]);
        (new DocumentTotalsProbeService)->save($update);

        $doc = DB::table('probe_docs')->find($id);
        $this->assertSame(10400.0, (float) $doc->sub_total);
        $this->assertSame(10400.0, (float) $doc->total);
        $this->assertSame(9100.0, (float) $doc->due_amount, 'due is create-time only');
    }

    public function test_percent_discount_and_after_discount_tax(): void
    {
        $form = DocumentTotalsProbeForm::fromArray([
            'discount' => 10,
            'discount_type' => 'percent',
            'calculate_tax' => 'after_discount',
            'probe_items' => [
                ['item_name' => 'taxed', 'quantity' => 2, 'unit_price' => 100, 'taxes' => '[1]'],
            ],
        ]);
        $id = (new DocumentTotalsProbeService)->save($form);

        $doc = DB::table('probe_docs')->find($id);
        $this->assertSame(200.0, (float) $doc->sub_total);
        $this->assertSame(198.0, (float) $doc->total, '200 - 20 discount + 18 tax on the discounted 180');
    }
}

class DocumentTotalsProbeForm extends BaseFormBusinessObject
{
    public ?float $sub_total = null;

    public ?float $discount = null;

    public ?string $discount_type = null;

    public ?string $calculate_tax = null;

    public ?float $total = null;

    public ?float $due_amount = null;

    /** @var array<int, array<string, mixed>> */
    public array $probe_items = [];
}

class DocumentTotalsProbeModel extends Model
{
    protected $table = 'probe_docs';

    protected $guarded = [];

    public $timestamps = false;
}

class DocumentTotalsProbeService extends BaseSliceService
{
    protected function getModelClass(): string
    {
        return DocumentTotalsProbeModel::class;
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        return new DocumentTotalsProbeForm;
    }

    protected function mapToListing(Model $model): IBusinessObject
    {
        return new DocumentTotalsProbeForm;
    }

    public function repeaterDefinitions(): array
    {
        return [
            'probe_items' => [
                'table' => 'probe_doc_items',
                'foreign_key' => 'probe_doc_id',
                'model' => 'probe_doc_item',
                'fields' => [
                    'item_name' => ['label' => 'Item Name', 'type' => 'string', 'required' => true, 'options' => []],
                    'quantity' => ['label' => 'Quantity', 'type' => 'decimal', 'required' => false, 'options' => []],
                    'unit_price' => ['label' => 'Unit Price', 'type' => 'decimal', 'required' => false, 'options' => []],
                    'amount' => ['label' => 'Amount', 'type' => 'decimal', 'required' => false, 'options' => []],
                    'taxes' => ['label' => 'Taxes', 'type' => 'string', 'required' => false, 'options' => []],
                ],
                'document' => [
                    'quantity' => 'quantity',
                    'unit_price' => 'unit_price',
                    'amount' => 'amount',
                    'subtotal_field' => 'sub_total',
                    'total_field' => 'total',
                    'discount_field' => 'discount',
                    'discount_type_field' => 'discount_type',
                    'tax_field' => 'taxes',
                    'tax_table' => 'taxes',
                    'tax_rate' => 'rate_percent',
                    'tax_mode_field' => 'calculate_tax',
                    'due_field' => 'due_amount',
                ],
            ],
        ];
    }
}
