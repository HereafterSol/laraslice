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
use LaraSlice\Core\Contracts\IBusinessObject;
use LaraSlice\Tests\TestCase;

/**
 * API field-behaviour parity (W5, DIFFERENCES D-012).
 *
 * Seat 1 enforced the Heart's field behaviour
 * (mandatory_depends_on / fetch_from / read_only_depends_on
 * / computed / autoname) on WEB writes only, through
 * BaseSliceWebController. The API base controller saved
 * whatever it was given. The behaviour code now lives in
 * the shared trait LaraSlice\Core\Base\Traits\
 * AppliesFieldBehaviour, used by both controllers; these
 * tests pin the API surface to the web surface's outcomes:
 *
 *  - a mandatory_depends_on violation via API rejects with
 *    the API's 422 validation shape and persists nothing;
 *  - fetch_from fills an empty API value from the linked
 *    record and never overwrites a provided one;
 *  - autoname mints the naming series on API create, and
 *    a user-supplied name always wins;
 *  - read_only_depends_on restores the stored value on
 *    API update (the web server-side rule, mirrored);
 *  - the same mandatory violation through the WEB store
 *    still rejects — the trait extraction changed no web
 *    outcome.
 *
 * The probe controllers override authorizeSlice() to an
 * authentication presence check (mirroring
 * UpdateSemanticsTest): authorization is not the subject
 * here; field behaviour is. Both probes read their
 * manifest from the bound probe service, so web and API
 * are judged against one declaration.
 */
final class ApiFieldBehaviourParityTest extends TestCase
{
    private const MANIFEST = [
        'name' => 'BehaviourProbes',
        'autoname' => ['field' => 'name', 'pattern' => 'BHV-####'],
        'fields' => [
            'name' => ['type' => 'string', 'label' => 'Name'],
            'status' => ['type' => 'string', 'label' => 'Status'],
            'lost_reason' => [
                'type' => 'string', 'label' => 'Lost Reason',
                'mandatory_depends_on' => [['field' => 'status', 'op' => 'is', 'value' => 'lost']],
            ],
            'owner' => ['type' => 'integer', 'label' => 'Owner', 'relation' => 'user'],
            'owner_email' => [
                'type' => 'string', 'label' => 'Owner Email',
                'fetch_from' => 'owner.email',
            ],
            'locked_note' => [
                'type' => 'string', 'label' => 'Locked Note', 'default' => 'declared-default',
                'read_only_depends_on' => [['field' => 'status', 'op' => 'is', 'value' => 'locked']],
            ],
            'lost_detail' => [
                'type' => 'string', 'label' => 'Lost Detail',
                'clear_when' => [['field' => 'status', 'op' => 'is_not', 'value' => 'lost']],
            ],
            'lost_on' => [
                'type' => 'string', 'label' => 'Lost On',
                'clear_when' => [['field' => 'status', 'op' => 'is_not', 'value' => 'lost']],
                'stamp_when' => ['when' => [['field' => 'status', 'op' => 'is', 'value' => 'lost']], 'value' => '@today'],
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('behaviour_probes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->string('lost_reason')->nullable();
            $table->integer('owner')->nullable();
            $table->string('owner_email')->nullable();
            $table->string('locked_note')->nullable();
            $table->string('lost_detail')->nullable();
            $table->string('lost_on')->nullable();
        });
        if (! Schema::hasTable('laraslice_counters')) {
            Schema::create('laraslice_counters', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->unsignedBigInteger('value')->default(0);
                $table->timestamps();
            });
        }

        $this->app->instance(BehaviourProbeService::class, new BehaviourProbeService(self::MANIFEST));

        Route::post('/behaviour-probe-api', [BehaviourProbeApiController::class, 'save']);
        Route::post('/behaviour-probe-web', [BehaviourProbeWebController::class, 'store']);
        Route::put('/behaviour-probe-web/{id}', [BehaviourProbeWebController::class, 'update']);
    }

    public function test_api_mandatory_violation_rejects_422_and_persists_nothing(): void
    {
        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'status' => 'lost',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonStructure(['success', 'message', 'errors' => ['lost_reason']]);
        $this->assertSame(0, \DB::table('behaviour_probes')->count());
    }

    public function test_api_mandatory_satisfied_saves(): void
    {
        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'status' => 'lost',
            'lost_reason' => 'Price',
        ]);

        $response->assertStatus(201);
        $this->assertSame('Price', \DB::table('behaviour_probes')->first()->lost_reason);
    }

    public function test_api_mandatory_not_triggered_when_condition_does_not_hold(): void
    {
        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'status' => 'open',
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, \DB::table('behaviour_probes')->count());
    }

    public function test_api_fetch_from_fills_an_empty_value(): void
    {
        $owner = $this->makeUser(['email' => 'owner@example.test']);

        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'status' => 'open',
            'owner' => $owner->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame('owner@example.test', \DB::table('behaviour_probes')->first()->owner_email);
    }

    public function test_api_fetch_from_never_overwrites_a_provided_value(): void
    {
        $owner = $this->makeUser(['email' => 'owner@example.test']);

        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'status' => 'open',
            'owner' => $owner->id,
            'owner_email' => 'chosen@example.test',
        ]);

        $response->assertStatus(201);
        $this->assertSame('chosen@example.test', \DB::table('behaviour_probes')->first()->owner_email);
    }

    public function test_api_autoname_mints_the_series_on_create(): void
    {
        $admin = $this->makeSuperAdmin();

        $first = $this->actingAs($admin)->postJson('/behaviour-probe-api', ['status' => 'open']);
        $first->assertStatus(201);
        $second = $this->actingAs($admin)->postJson('/behaviour-probe-api', ['status' => 'open']);
        $second->assertStatus(201);

        $names = \DB::table('behaviour_probes')->orderBy('id')->pluck('name')->all();
        $this->assertSame(['BHV-0001', 'BHV-0002'], $names);
    }

    public function test_api_autoname_respects_a_supplied_name(): void
    {
        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'status' => 'open',
            'name' => 'Manual-Name',
        ]);

        $response->assertStatus(201);
        $this->assertSame('Manual-Name', \DB::table('behaviour_probes')->first()->name);
    }

    public function test_api_read_only_condition_restores_the_stored_value_on_update(): void
    {
        $id = (int) \DB::table('behaviour_probes')->insertGetId([
            'name' => 'BHV-0099', 'status' => 'open', 'locked_note' => 'Stored Note',
        ]);

        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'id' => $id,
            'status' => 'locked',
            'locked_note' => 'Tampered',
        ]);

        $response->assertStatus(200);
        $this->assertSame('Stored Note', \DB::table('behaviour_probes')->find($id)->locked_note);
    }

    public function test_web_mandatory_violation_still_rejects_after_trait_extraction(): void
    {
        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-web', [
            'status' => 'lost',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, \DB::table('behaviour_probes')->count());
    }

    public function test_api_stamp_when_stamps_on_state_entry(): void
    {
        // D-071: entering the gated state stamps the field with
        // the declared token (@today) in the same save.
        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'status' => 'lost',
            'lost_reason' => 'Price',
            'lost_detail' => 'Too expensive',
        ]);

        $response->assertStatus(201);
        $row = \DB::table('behaviour_probes')->first();
        $this->assertSame(date('Y-m-d'), $row->lost_on);
        $this->assertSame('Too expensive', $row->lost_detail);
    }

    public function test_api_clear_when_empties_gated_data_on_state_exit(): void
    {
        // D-072: leaving the gated state clears its data
        // (detail + stamp) in the same save — the source's
        // set-lost/reopen semantics.
        $id = (int) \DB::table('behaviour_probes')->insertGetId([
            'name' => 'BHV-0100', 'status' => 'lost', 'lost_reason' => 'Price',
            'lost_detail' => 'Too expensive', 'lost_on' => '2026-01-01',
        ]);

        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'id' => $id,
            'status' => 'open',
            'lost_detail' => 'Too expensive',
            'lost_on' => '2026-01-01',
        ]);

        $response->assertStatus(200);
        $row = \DB::table('behaviour_probes')->find($id);
        $this->assertNull($row->lost_detail);
        $this->assertNull($row->lost_on);
    }

    public function test_api_stamp_when_keeps_the_original_stamp_while_the_state_holds(): void
    {
        // Rising edge only: re-saving an already-lost record
        // must NOT move its stamp (the day it BECAME lost).
        $id = (int) \DB::table('behaviour_probes')->insertGetId([
            'name' => 'BHV-0101', 'status' => 'lost', 'lost_reason' => 'Price',
            'lost_detail' => 'Too expensive', 'lost_on' => '2026-01-01',
        ]);

        $response = $this->actingAs($this->makeSuperAdmin())->postJson('/behaviour-probe-api', [
            'id' => $id,
            'status' => 'lost',
            'lost_reason' => 'Price',
            'lost_detail' => 'Too expensive',
        ]);

        $response->assertStatus(200);
        $this->assertSame('2026-01-01', \DB::table('behaviour_probes')->find($id)->lost_on);
    }
}

final class BehaviourProbeForm extends BaseFormBusinessObject
{
    public ?string $name = null;

    public ?string $status = null;

    public ?string $lost_reason = null;

    public ?int $owner = null;

    public ?string $owner_email = null;

    public ?string $locked_note = null;

    public ?string $lost_detail = null;

    public ?string $lost_on = null;
}

final class BehaviourProbeModel extends Model
{
    protected $table = 'behaviour_probes';

    protected $guarded = [];

    public $timestamps = false;
}

final class BehaviourProbeService extends BaseSliceService
{
    /** @param array<string, mixed> $manifest */
    public function __construct(private readonly array $manifest = []) {}

    /** The one declaration both probe controllers are judged against. */
    public function manifestArray(): array
    {
        return $this->manifest;
    }

    protected function getModelClass(): string
    {
        return BehaviourProbeModel::class;
    }

    protected function mapToForm(Model $model): IBusinessObject
    {
        $form = new BehaviourProbeForm;
        $form->id = $model->getKey();
        $form->name = $model->getAttribute('name');
        $form->status = $model->getAttribute('status');
        $form->lost_reason = $model->getAttribute('lost_reason');
        $form->owner = $model->getAttribute('owner') !== null ? (int) $model->getAttribute('owner') : null;
        $form->owner_email = $model->getAttribute('owner_email');
        $form->locked_note = $model->getAttribute('locked_note');
        $form->lost_detail = $model->getAttribute('lost_detail');
        $form->lost_on = $model->getAttribute('lost_on');

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

final class BehaviourProbeApiController extends BaseSliceApiController
{
    protected function authorizeSlice(string $action): void
    {
        if (! auth()->user()) {
            abort(401, 'Unauthenticated.');
        }
    }

    protected function ownManifest(): array
    {
        return app(BehaviourProbeService::class)->manifestArray();
    }

    protected function getService(): \LaraSlice\Core\Contracts\IFormDataService&\LaraSlice\Core\Contracts\IListingDataService
    {
        return app(BehaviourProbeService::class);
    }

    protected function getFormClass(): string
    {
        return BehaviourProbeForm::class;
    }

    protected function getFilterClass(): string
    {
        return BaseFilter::class;
    }
}

final class BehaviourProbeWebController extends BaseSliceWebController
{
    protected function authorizeSlice(string $action): void
    {
        if (! auth()->user()) {
            abort(401, 'Unauthenticated.');
        }
    }

    protected function ownManifest(): array
    {
        return app(BehaviourProbeService::class)->manifestArray();
    }

    protected function getService(): \LaraSlice\Core\Contracts\IFormDataService&\LaraSlice\Core\Contracts\IListingDataService
    {
        return app(BehaviourProbeService::class);
    }

    protected function getFormClass(): string
    {
        return BehaviourProbeForm::class;
    }

    protected function getFilterClass(): string
    {
        return BaseFilter::class;
    }

    protected function getViewPrefix(): string
    {
        return 'behaviour_probe::';
    }

    protected function getRoutePrefix(): string
    {
        return 'behaviour_probe.';
    }
}
