<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Base\BaseFilter;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Tests\TestCase;

/**
 * Inline updates must resolve a UI handle to the column it
 * writes.
 *
 * Directory and board UIs speak in relation handles
 * (board_column); the record stores the foreign key
 * (board_column_id). Declarations a cold port generates put
 * the HANDLE in ui.directory.inline_edit — the write path
 * used it verbatim as the attribute name and crashed with
 * SQL 1054 (unknown column), as the independent v2.1.0
 * evaluation's cold port demonstrated (finding B3).
 * Converged declarations that name the full column were
 * never affected, which is why the fleet proofs missed it.
 *
 * Pins:
 *  - {field: board_column, value: 2} writes board_column_id;
 *  - garbage through the handle still meets the strict-
 *    binding gate (the form object declares the resolved
 *    attribute) and returns the per-issue 422;
 *  - the exact-column path (board_column_id) is unchanged.
 */
final class InlineHandleResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('inline_probes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('heading')->nullable();
            $table->integer('board_column_id')->nullable();
        });

        $this->app->instance(InlineProbeService::class, new InlineProbeService(InlineHandleResolutionTestManifest::MANIFEST));

        Route::post('/inline-probe/{id}/inline', [InlineProbeWebController::class, 'inlineUpdate']);
    }

    private function seedRow(): int
    {
        return (int) \DB::table('inline_probes')->insertGetId([
            'heading' => 'Probe task',
            'board_column_id' => 1,
        ]);
    }

    public function test_relation_handle_resolves_to_the_foreign_key_column(): void
    {
        $id = $this->seedRow();

        $response = $this->actingAs($this->makeSuperAdmin())
            ->postJson("/inline-probe/{$id}/inline", ['field' => 'board_column', 'value' => 2]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(2, (int) \DB::table('inline_probes')->where('id', $id)->value('board_column_id'));
    }

    public function test_garbage_through_the_handle_still_meets_the_binding_gate(): void
    {
        $id = $this->seedRow();

        $response = $this->actingAs($this->makeSuperAdmin())
            ->postJson("/inline-probe/{$id}/inline", ['field' => 'board_column', 'value' => 'abc']);

        $response->assertStatus(422)->assertJsonStructure(['success', 'issues']);
        $this->assertSame(1, (int) \DB::table('inline_probes')->where('id', $id)->value('board_column_id'));
    }

    public function test_exact_column_name_path_is_unchanged(): void
    {
        $id = $this->seedRow();

        $response = $this->actingAs($this->makeSuperAdmin())
            ->postJson("/inline-probe/{$id}/inline", ['field' => 'board_column_id', 'value' => 3]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(3, (int) \DB::table('inline_probes')->where('id', $id)->value('board_column_id'));
    }
}

final class InlineProbeForm extends BaseFormBusinessObject
{
    public ?string $heading = null;

    public ?int $board_column_id = null;
}

final class InlineProbeModel extends Model
{
    protected $table = 'inline_probes';

    protected $guarded = [];

    public $timestamps = false;
}

final class InlineProbeService extends BaseSliceService
{
    /** @param array<string, mixed> $manifest */
    public function __construct(private readonly array $manifest = []) {}

    protected function getModelClass(): string
    {
        return InlineProbeModel::class;
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        $form = new InlineProbeForm;
        $form->heading = $model->getAttribute('heading');
        $form->board_column_id = $model->getAttribute('board_column_id') !== null
            ? (int) $model->getAttribute('board_column_id') : null;
        $form->id = $model->getKey();

        return $form;
    }

    protected function mapToListing(Model $model): IBusinessObject
    {
        return $this->mapToForm($model);
    }

    protected function ownManifest(): array
    {
        return $this->manifest;
    }
}

final class InlineProbeWebController extends BaseSliceWebController
{
    protected function authorizeSlice(string $action): void
    {
        if (! auth()->user()) {
            abort(401, 'Unauthenticated.');
        }
    }

    protected function getService(): \LaraSlice\Core\Contracts\IFormDataService&\LaraSlice\Core\Contracts\IListingDataService
    {
        return app(InlineProbeService::class);
    }

    protected function getFormClass(): string
    {
        return InlineProbeForm::class;
    }

    protected function getFilterClass(): string
    {
        return BaseFilter::class;
    }

    protected function getViewPrefix(): string
    {
        return 'inline_probe::';
    }

    protected function getRoutePrefix(): string
    {
        return 'inline_probe.';
    }

    protected function ownManifest(): array
    {
        return InlineHandleResolutionTestManifest::MANIFEST;
    }
}

/** The one manifest both probe halves serve. */
final class InlineHandleResolutionTestManifest
{
    public const MANIFEST = [
        'fields' => [
            ['handle' => 'heading', 'type' => 'string'],
            ['handle' => 'board_column_id', 'type' => 'integer'],
        ],
        'models' => [[
            'fields' => [
                ['handle' => 'heading', 'type' => 'string'],
                ['handle' => 'board_column_id', 'type' => 'integer'],
            ],
            'relations' => [
                ['name' => 'board_column', 'type' => 'belongsTo', 'foreign_key' => 'board_column_id'],
            ],
        ]],
        'ui' => ['directory' => ['inline_edit' => ['board_column', 'board_column_id']]],
    ];
}
