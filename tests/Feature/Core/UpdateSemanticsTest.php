<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Core\Base\BaseFilter;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Base\BaseSliceApiController;
use LaraSlice\Core\Base\BaseSliceService;
use LaraSlice\Core\Base\BaseSliceWebController;
use LaraSlice\Core\Base\BindingIssuesException;
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Core\Metadata\MetadataProjector;
use LaraSlice\Tests\TestCase;

/**
 * Explicit update semantics + strict binding over the REAL
 * write surfaces (Proposal #6):
 *
 *  - the generated API controller (BaseSliceApiController)
 *    and the generated web controller (BaseSliceWebController)
 *    reject type-invalid input with the per-issue envelope
 *    instead of saving a silently-dropped value;
 *  - a Heart declaring `update: replace` makes the service
 *    reject a partial update body, naming each missing
 *    field, while a complete body writes every field;
 *  - the default (undeclared) mode is patch — the behaviour
 *    updates have always had — and only provided fields
 *    change;
 *  - the projector serves the same resolution the write
 *    path enforces (one resolver, UpdateSemantics).
 *
 * Pre-Phase-D these fail by absence (no bindingIssues(), no
 * replace check, no `update` key in describe) — the API
 * happily saved `quantity: "abc"` as an untouched default.
 *
 * The probe controllers override authorizeSlice() to an
 * authentication presence check: authorization is not the
 * subject here (the fleet's real permission path is
 * exercised by the live proof); binding is.
 */
final class UpdateSemanticsTest extends TestCase
{
    private const REPLACE_MANIFEST = [
        'update' => 'replace',
        'fields' => [
            'name' => ['type' => 'string'],
            'quantity' => ['type' => 'integer'],
            'price' => ['type' => 'decimal'],
            'active' => ['type' => 'boolean'],
            'status' => ['type' => 'string', 'default' => 'draft'],
            'score' => ['type' => 'integer'],
        ],
    ];

    private const PATCH_MANIFEST = [
        'fields' => [
            'name' => ['type' => 'string'],
            'quantity' => ['type' => 'integer'],
            'price' => ['type' => 'decimal'],
            'active' => ['type' => 'boolean'],
            'status' => ['type' => 'string', 'default' => 'draft'],
            'score' => ['type' => 'integer'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('strict_probes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->boolean('active')->default(false);
            $table->string('status')->nullable();
            $table->integer('score')->nullable();
        });

        Route::post('/strict-probe-api', [StrictProbeApiController::class, 'save']);
        Route::post('/strict-probe-web', [StrictProbeWebController::class, 'store']);
        Route::put('/strict-probe-web/{id}', [StrictProbeWebController::class, 'update']);
    }

    private function useManifest(array $manifest): void
    {
        $this->app->instance(StrictProbeService::class, new StrictProbeService($manifest));
    }

    private function seedRow(): int
    {
        return (int) \DB::table('strict_probes')->insertGetId([
            'name' => 'Original',
            'quantity' => 5,
            'price' => 10.00,
            'active' => 1,
            'status' => 'live',
            'score' => 3,
        ]);
    }

    // ---- strict binding over HTTP -------------------------------------------

    public function test_api_rejects_type_invalid_input_with_per_issue_errors(): void
    {
        $this->useManifest(self::PATCH_MANIFEST);

        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/strict-probe-api', [
            'name' => 'Widget',
            'quantity' => 'abc',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $issues = $response->json('issues');
        $this->assertCount(1, $issues);
        $this->assertSame('quantity', $issues[0]['field']);
        $this->assertStringContainsString("'abc'", $issues[0]['problem']);
        $this->assertStringContainsString('integer', $issues[0]['expected']);
        // Nothing was saved: the rejected write leaves no row.
        $this->assertSame(0, \DB::table('strict_probes')->count());
    }

    public function test_api_accepts_legitimate_representations(): void
    {
        $this->useManifest(self::PATCH_MANIFEST);

        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/strict-probe-api', [
            'name' => 'Widget',
            'quantity' => '42',   // integer string — binds
            'price' => '12.50',   // numeric string — binds
            'active' => 'yes',    // HTML boolean word — binds
        ]);

        $response->assertStatus(201);
        $row = \DB::table('strict_probes')->first();
        $this->assertSame(42, (int) $row->quantity);
        $this->assertSame(12.5, (float) $row->price);
        $this->assertSame(1, (int) $row->active);
    }

    public function test_web_json_rejects_type_invalid_input_with_the_same_envelope(): void
    {
        $this->useManifest(self::PATCH_MANIFEST);

        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/strict-probe-web', [
            'name' => 'Widget',
            'quantity' => 'not-a-number',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('issues.0.field', 'quantity');
        $this->assertSame(0, \DB::table('strict_probes')->count());
    }

    public function test_web_form_post_rejects_with_field_keyed_validation_errors(): void
    {
        $this->useManifest(self::PATCH_MANIFEST);

        $response = $this->actingAs($this->makeSuperAdmin())->post('/strict-probe-web', [
            'name' => 'Widget',
            'quantity' => 'not-a-number',
        ]);

        $response->assertSessionHasErrors(['quantity']);
        $this->assertSame(0, \DB::table('strict_probes')->count());
    }

    // ---- update semantics at the service ------------------------------------

    public function test_patch_update_changes_only_provided_fields(): void
    {
        $id = $this->seedRow();
        $service = new StrictProbeService(self::PATCH_MANIFEST);

        $form = StrictProbeForm::fromArray(['id' => $id, 'name' => 'Renamed']);
        $service->save($form);

        $row = \DB::table('strict_probes')->find($id);
        $this->assertSame('Renamed', $row->name);
        $this->assertSame(5, (int) $row->quantity);   // untouched
        $this->assertSame(3, (int) $row->score);      // untouched
    }

    public function test_replace_update_rejects_a_partial_body_naming_each_missing_field(): void
    {
        $id = $this->seedRow();
        $service = new StrictProbeService(self::REPLACE_MANIFEST);

        $form = StrictProbeForm::fromArray(['id' => $id, 'name' => 'Renamed', 'quantity' => 9]);

        try {
            $service->save($form);
            $this->fail('A partial body under replace semantics must be rejected.');
        } catch (BindingIssuesException $e) {
            $fields = array_column($e->issues(), 'field');
            sort($fields);
            // status is NOT named: its Heart declaration
            // carries a default — the declaration supplies
            // its semantics. price/active/score are named.
            $this->assertSame(['active', 'price', 'score'], $fields);
            $this->assertStringContainsString('replace', $e->issues()[0]['expected']);
        }

        // The record is untouched.
        $this->assertSame('Original', \DB::table('strict_probes')->find($id)->name);
    }

    public function test_replace_update_with_a_complete_body_writes_every_field(): void
    {
        $id = $this->seedRow();
        $service = new StrictProbeService(self::REPLACE_MANIFEST);

        $form = StrictProbeForm::fromArray([
            'id' => $id,
            'name' => 'Replaced',
            'quantity' => 9,
            'price' => null,      // explicit clear of a nullable field counts as provided
            'active' => false,
            'score' => 0,
        ]);
        $service->save($form);

        $row = \DB::table('strict_probes')->find($id);
        $this->assertSame('Replaced', $row->name);
        $this->assertSame(9, (int) $row->quantity);
        $this->assertNull($row->price);
        $this->assertSame(0, (int) $row->active);
        $this->assertSame(0, (int) $row->score);
    }

    public function test_replace_a_null_default_does_not_carve_out_a_field(): void
    {
        // Generated Hearts emit `default: null` on every
        // field as shape filler. A null default supplies no
        // value (applyDeclaredDefaults treats it the same
        // way), so the field still counts as missing.
        $manifest = self::REPLACE_MANIFEST;
        $manifest['fields']['score'] = ['type' => 'integer', 'default' => null];
        $id = $this->seedRow();
        $service = new StrictProbeService($manifest);

        $form = StrictProbeForm::fromArray(['id' => $id, 'name' => 'Renamed', 'quantity' => 9, 'price' => 1.50, 'active' => true]);

        try {
            $service->save($form);
            $this->fail('A field with only a null default must still count as missing under replace.');
        } catch (BindingIssuesException $e) {
            $this->assertSame(['score'], array_column($e->issues(), 'field'));
        }
    }

    public function test_replace_reads_field_declarations_from_the_models_shape_too(): void
    {
        // Hearts declare fields in two shapes; the generated
        // shape is the root model's field list. Replace must
        // judge completeness against THAT declaration too —
        // merged exactly as the projector merges the shapes.
        $manifest = [
            'update' => 'replace',
            'models' => [[
                'handle' => 'strict_probe',
                'table' => 'strict_probes',
                'root' => true,
                'fields' => [
                    ['handle' => 'name', 'type' => 'string'],
                    ['handle' => 'quantity', 'type' => 'integer'],
                    ['handle' => 'price', 'type' => 'decimal'],
                    ['handle' => 'active', 'type' => 'boolean'],
                    ['handle' => 'status', 'type' => 'string', 'default' => 'draft'],
                    ['handle' => 'score', 'type' => 'integer'],
                ],
            ]],
        ];
        $id = $this->seedRow();
        $service = new StrictProbeService($manifest);

        $form = StrictProbeForm::fromArray(['id' => $id, 'name' => 'Renamed', 'quantity' => 9, 'price' => 1.50, 'active' => true]);

        try {
            $service->save($form);
            $this->fail('A partial body under replace semantics (models shape) must be rejected.');
        } catch (BindingIssuesException $e) {
            $this->assertSame(['score'], array_column($e->issues(), 'field'));
        }
    }

    public function test_replace_over_http_rejects_partial_and_accepts_complete(): void
    {
        $this->useManifest(self::REPLACE_MANIFEST);
        $id = $this->seedRow();
        $admin = $this->makeSuperAdmin();

        $partial = $this->actingAs($admin)->putJson("/strict-probe-web/{$id}", ['name' => 'Nope']);
        $partial->assertStatus(422);
        $fields = array_column($partial->json('issues'), 'field');
        $this->assertContains('quantity', $fields);
        $this->assertSame('Original', \DB::table('strict_probes')->find($id)->name);

        $complete = $this->actingAs($admin)->putJson("/strict-probe-web/{$id}", [
            'name' => 'Done',
            'quantity' => 2,
            'price' => 4.50,
            'active' => true,
            'score' => 8,
        ]);
        $complete->assertStatus(200);
        $row = \DB::table('strict_probes')->find($id);
        $this->assertSame('Done', $row->name);
        $this->assertSame(8, (int) $row->score);
    }

    // ---- the projected contract agrees with the write path -------------------

    public function test_projector_serves_the_declared_update_semantics(): void
    {
        $root = sys_get_temp_dir().'/laraslice-update-semantics-'.uniqid();
        mkdir($root.'/ReplaceSlice', 0777, true);
        mkdir($root.'/PlainSlice', 0777, true);
        file_put_contents($root.'/ReplaceSlice/slice.yaml', <<<'YAML'
            name: ReplaceSlice
            title: Replace Slice
            active: true
            update: replace
            models:
              - handle: replaceable
                table: replaceables
                root: true
                fields:
                  - handle: name
                    label: Name
                    type: string
                    required: true
            YAML);
        file_put_contents($root.'/PlainSlice/slice.yaml', <<<'YAML'
            name: PlainSlice
            title: Plain Slice
            active: true
            models:
              - handle: plain
                table: plains
                root: true
                fields:
                  - handle: name
                    label: Name
                    type: string
                    required: true
            YAML);

        try {
            $manager = new class($this->app, [
                new SliceManifest($root.'/ReplaceSlice/slice.yaml'),
                new SliceManifest($root.'/PlainSlice/slice.yaml'),
            ]) extends SliceManager
            {
                public function __construct($app, private array $fixtures)
                {
                    parent::__construct($app);
                }

                public function discover(bool $force = false): void {}

                public function discoveredSlices(): array
                {
                    return $this->fixtures;
                }
            };
            $projector = new MetadataProjector($manager);

            $this->assertSame(['mode' => 'replace', 'declared' => true], $projector->describe('ReplaceSlice')['update']);
            $this->assertSame(['mode' => 'patch', 'declared' => false], $projector->describe('PlainSlice')['update']);
            // The schema document carries the describe core verbatim.
            $this->assertSame(['mode' => 'replace', 'declared' => true], $projector->schema('ReplaceSlice')['update']);
        } finally {
            $this->app['files']->deleteDirectory($root);
        }
    }
}

final class StrictProbeForm extends BaseFormBusinessObject
{
    public ?string $name = null;

    public ?int $quantity = null;

    public ?float $price = null;

    public bool $active = false;

    public ?string $status = null;

    public ?int $score = null;
}

final class StrictProbeModel extends Model
{
    protected $table = 'strict_probes';

    protected $guarded = [];

    public $timestamps = false;
}

final class StrictProbeService extends BaseSliceService
{
    /** @param array<string, mixed> $manifest */
    public function __construct(private readonly array $manifest = []) {}

    protected function getModelClass(): string
    {
        return StrictProbeModel::class;
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        $form = new StrictProbeForm;
        $form->name = $model->getAttribute('name');
        $form->quantity = $model->getAttribute('quantity') !== null ? (int) $model->getAttribute('quantity') : null;
        $form->price = $model->getAttribute('price') !== null ? (float) $model->getAttribute('price') : null;
        $form->active = (bool) $model->getAttribute('active');
        $form->status = $model->getAttribute('status');
        $form->score = $model->getAttribute('score') !== null ? (int) $model->getAttribute('score') : null;
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

final class StrictProbeApiController extends BaseSliceApiController
{
    protected function authorizeSlice(string $action): void
    {
        if (! auth()->user()) {
            abort(401, 'Unauthenticated.');
        }
    }

    protected function getService(): \LaraSlice\Core\Contracts\IFormDataService&\LaraSlice\Core\Contracts\IListingDataService
    {
        return app(StrictProbeService::class);
    }

    protected function getFormClass(): string
    {
        return StrictProbeForm::class;
    }

    protected function getFilterClass(): string
    {
        return BaseFilter::class;
    }
}

final class StrictProbeWebController extends BaseSliceWebController
{
    protected function authorizeSlice(string $action): void
    {
        if (! auth()->user()) {
            abort(401, 'Unauthenticated.');
        }
    }

    protected function getService(): \LaraSlice\Core\Contracts\IFormDataService&\LaraSlice\Core\Contracts\IListingDataService
    {
        return app(StrictProbeService::class);
    }

    protected function getFormClass(): string
    {
        return StrictProbeForm::class;
    }

    protected function getFilterClass(): string
    {
        return BaseFilter::class;
    }

    protected function getViewPrefix(): string
    {
        return 'strict_probe::';
    }

    protected function getRoutePrefix(): string
    {
        return 'strict_probe.';
    }
}
