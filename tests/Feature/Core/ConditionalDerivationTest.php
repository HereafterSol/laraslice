<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LaraSlice\Core\Base\BaseFormBusinessObject;
use LaraSlice\Core\Base\Traits\AppliesFieldBehaviour;
use LaraSlice\Core\Discovery\SliceManager;
use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Tests\TestCase;

/**
 * Conditional derivations (Phase 8, DIFFERENCES D-078) +
 * the collection-mandatory rule (the Set-as-Lost gate).
 *
 * Desk derives Contract status / fulfilment_status and
 * Email Campaign status inside validate() on EVERY save,
 * and refuses a Lost save carrying no lost reasons
 * (declare_enquiry_lost: "Please select at least one Lost
 * Reason"). The port's field-behaviour vocabulary had no
 * conditional, date-aware derivation to express those
 * rules; the seat is:
 *
 *  - computed.cases — an ordered case list whose `when`
 *    clauses read sibling values, collection aggregates
 *    ('<repeater>.count', '<repeater>.<sub>.count'),
 *    field-to-field operands ('<op>_from') and the
 *    @today / @now clock tokens;
 *  - computed.date_add — base date + an aggregate over a
 *    LINKED record's child rows (Email Campaign end_date
 *    = start + max(send_after_days of its Campaign's
 *    schedule); a campaign with no schedule rows refuses
 *    the save, as Desk's set_date() does);
 *  - mandatory_depends_on treats an empty collection as
 *    missing and honours a declared mandatory_message,
 *    while a collection the save never provided is
 *    judged by its stored set (no false refusals on
 *    partial writes).
 *
 * These tests drive the shared trait directly through a
 * probe host mirroring the port's Contracts /
 * EmailCampaigns / Opportunities declarations clause for
 * clause.
 */
final class ConditionalDerivationTest extends TestCase
{
    private const CONTRACT_MANIFEST = [
        'name' => 'Contracts',
        'fields' => [
            'is_signed' => ['type' => 'boolean', 'label' => 'Signed'],
            'start_date' => ['type' => 'date', 'label' => 'Start Date'],
            'end_date' => ['type' => 'date', 'label' => 'End Date'],
            'status' => [
                'type' => 'string', 'label' => 'Status',
                'computed' => [
                    'cases' => [
                        ['when' => ['field' => 'is_signed', 'empty' => true], 'value' => 'unsigned'],
                        ['when' => ['field' => 'start_date', 'gt' => '@today'], 'value' => 'inactive'],
                        ['when' => ['field' => 'end_date', 'lt' => '@today'], 'value' => 'inactive'],
                    ],
                    'default' => 'active',
                ],
            ],
            'requires_fulfilment' => ['type' => 'boolean', 'label' => 'Requires Fulfilment'],
            'fulfilment_deadline' => ['type' => 'date', 'label' => 'Fulfilment Deadline'],
            'fulfilment_terms' => [
                'type' => 'repeater', 'label' => 'Fulfilment Terms',
                'table' => 'contract_fulfilment_checklists', 'foreign_key' => 'contract_id',
                'repeater_fields' => [
                    ['handle' => 'fulfilled', 'label' => 'Fulfilled', 'type' => 'boolean'],
                    ['handle' => 'requirement', 'label' => 'Requirement', 'type' => 'string'],
                ],
            ],
            'fulfilment_status' => [
                'type' => 'string', 'label' => 'Fulfilment Status',
                'computed' => [
                    'cases' => [
                        ['when' => ['field' => 'requires_fulfilment', 'empty' => true], 'value' => 'n_a'],
                        ['when' => [
                            ['field' => 'fulfilment_terms.fulfilled.count', 'gt' => 0],
                            ['field' => 'fulfilment_terms.fulfilled.count', 'is_from' => 'fulfilment_terms.count'],
                        ], 'value' => 'fulfilled'],
                        ['when' => [
                            ['field' => 'fulfilment_deadline', 'not_empty' => true],
                            ['field' => 'fulfilment_deadline', 'lt' => '@today'],
                        ], 'value' => 'lapsed'],
                        ['when' => ['field' => 'fulfilment_terms.fulfilled.count', 'is' => 0], 'value' => 'unfulfilled'],
                    ],
                    'default' => 'partially_fulfilled',
                ],
            ],
        ],
    ];

    private const OPPORTUNITY_MANIFEST = [
        'name' => 'Opportunities',
        'fields' => [
            'status' => ['type' => 'string', 'label' => 'Status'],
            'lost_reasons' => [
                'type' => 'multiselect', 'label' => 'Lost Reasons',
                'table' => 'opportunity_lost_reason_details', 'foreign_key' => 'parent_id',
                'target_field' => 'lost_reason', 'target' => 'OpportunityLostReasons',
                'mandatory_depends_on' => ['field' => 'status', 'is' => 'lost'],
                'mandatory_message' => 'Please select at least one Lost Reason',
            ],
        ],
    ];

    private const CAMPAIGN_MANIFEST = [
        'name' => 'EmailCampaigns',
        'fields' => [
            'campaign_name' => ['type' => 'integer', 'label' => 'Campaign', 'relation' => 'campaign'],
            'start_date' => ['type' => 'date', 'label' => 'Start Date'],
            'end_date' => [
                'type' => 'date', 'label' => 'End Date',
                'computed' => ['date_add' => [
                    'base' => 'start_date',
                    'days_from' => ['link' => 'campaign_name', 'repeater' => 'campaign_schedules', 'field' => 'send_after_days', 'agg' => 'max'],
                ]],
            ],
            'status' => [
                'type' => 'string', 'label' => 'Status',
                'computed' => [
                    'cases' => [
                        ['when' => ['field' => 'status', 'is' => 'unsubscribed'], 'value_from' => 'status'],
                        ['when' => ['field' => 'start_date', 'gt' => '@today'], 'value' => 'scheduled'],
                        ['when' => ['field' => 'end_date', 'gte' => '@today'], 'value' => 'in_progress'],
                    ],
                    'default' => 'completed',
                ],
            ],
        ],
    ];

    private function host(array $manifest): object
    {
        return new class($manifest)
        {
            use AppliesFieldBehaviour;

            public function __construct(private readonly array $manifest) {}

            protected function ownManifest(): array
            {
                return $this->manifest;
            }

            public function apply(object $form, ?object $existing = null): void
            {
                $this->applyFieldBehaviour(new Request, $form, $existing, $existing === null);
            }
        };
    }

    private function contractForm(array $values): DerivationProbeForm
    {
        $form = new DerivationProbeForm;
        foreach ($values as $key => $value) {
            $form->{$key} = $value;
        }

        return $form;
    }

    public function test_contract_status_derives_like_desk(): void
    {
        $host = $this->host(self::CONTRACT_MANIFEST);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // Unsigned wins over any dates.
        $form = $this->contractForm(['is_signed' => false, 'start_date' => $yesterday, 'end_date' => $tomorrow]);
        $host->apply($form);
        $this->assertSame('unsigned', $form->status);

        // Signed, today inside the period → Active.
        $form = $this->contractForm(['is_signed' => true, 'start_date' => $yesterday, 'end_date' => $tomorrow]);
        $host->apply($form);
        $this->assertSame('active', $form->status);

        // Signed but the period has not begun → Inactive.
        $form = $this->contractForm(['is_signed' => true, 'start_date' => $tomorrow, 'end_date' => null]);
        $host->apply($form);
        $this->assertSame('inactive', $form->status);

        // Signed but the period has ended → Inactive.
        $form = $this->contractForm(['is_signed' => true, 'start_date' => null, 'end_date' => $yesterday]);
        $host->apply($form);
        $this->assertSame('inactive', $form->status);

        // Signed with open-ended dates → Active (Desk: a
        // blank end date means the contract is open-ended;
        // a blank start never inactivates either).
        $form = $this->contractForm(['is_signed' => true, 'start_date' => null, 'end_date' => null]);
        $host->apply($form);
        $this->assertSame('active', $form->status);
    }

    public function test_contract_fulfilment_status_derives_like_desk(): void
    {
        $host = $this->host(self::CONTRACT_MANIFEST);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $row = static fn (int $fulfilled) => ['fulfilled' => $fulfilled, 'requirement' => 'Do it'];

        // No fulfilment required → N/A regardless of rows.
        $form = $this->contractForm(['requires_fulfilment' => false, 'fulfilment_terms' => [$row(0)]]);
        $host->apply($form);
        $this->assertSame('n_a', $form->fulfilment_status);

        // Required, nothing ticked → Unfulfilled.
        $form = $this->contractForm(['requires_fulfilment' => true, 'fulfilment_terms' => [$row(0), $row(0)]]);
        $host->apply($form);
        $this->assertSame('unfulfilled', $form->fulfilment_status);

        // Required, zero rows → Unfulfilled (Desk: a falsy
        // progress is Unfulfilled before the == len check).
        $form = $this->contractForm(['requires_fulfilment' => true, 'fulfilment_terms' => []]);
        $host->apply($form);
        $this->assertSame('unfulfilled', $form->fulfilment_status);

        // Some ticked → Partially Fulfilled.
        $form = $this->contractForm(['requires_fulfilment' => true, 'fulfilment_terms' => [$row(1), $row(0)]]);
        $host->apply($form);
        $this->assertSame('partially_fulfilled', $form->fulfilment_status);

        // All ticked → Fulfilled.
        $form = $this->contractForm(['requires_fulfilment' => true, 'fulfilment_terms' => [$row(1), $row(1)]]);
        $host->apply($form);
        $this->assertSame('fulfilled', $form->fulfilment_status);

        // Past the deadline unfulfilled → Lapsed (partial
        // and untouched alike); fulfilled is exempt.
        $form = $this->contractForm(['requires_fulfilment' => true, 'fulfilment_deadline' => $yesterday, 'fulfilment_terms' => [$row(1), $row(0)]]);
        $host->apply($form);
        $this->assertSame('lapsed', $form->fulfilment_status);

        $form = $this->contractForm(['requires_fulfilment' => true, 'fulfilment_deadline' => $yesterday, 'fulfilment_terms' => [$row(1), $row(1)]]);
        $host->apply($form);
        $this->assertSame('fulfilled', $form->fulfilment_status);

        // A future deadline never lapses.
        $form = $this->contractForm(['requires_fulfilment' => true, 'fulfilment_deadline' => $tomorrow, 'fulfilment_terms' => [$row(0)]]);
        $host->apply($form);
        $this->assertSame('unfulfilled', $form->fulfilment_status);
    }

    public function test_cases_have_no_opinion_when_the_collection_is_not_in_the_save(): void
    {
        $host = $this->host(self::CONTRACT_MANIFEST);

        // A partial write that never mentions the terms:
        // the stored fulfilment status stands (the
        // aggregate cases cannot be judged, and the
        // default must not fire on a guess).
        $form = $this->contractForm(['requires_fulfilment' => true, 'fulfilment_status' => 'partially_fulfilled']);
        $host->apply($form);
        $this->assertSame('partially_fulfilled', $form->fulfilment_status);
    }

    public function test_email_campaign_status_derives_from_dates(): void
    {
        $host = $this->host(self::CAMPAIGN_MANIFEST);
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        $form = $this->campaignForm(['status' => 'unsubscribed', 'start_date' => $yesterday, 'end_date' => $yesterday]);
        $host->apply($form);
        $this->assertSame('unsubscribed', $form->status);

        $form = $this->campaignForm(['status' => null, 'start_date' => $tomorrow, 'end_date' => $tomorrow]);
        $host->apply($form);
        $this->assertSame('scheduled', $form->status);

        $form = $this->campaignForm(['status' => null, 'start_date' => $yesterday, 'end_date' => $today]);
        $host->apply($form);
        $this->assertSame('in_progress', $form->status);

        $form = $this->campaignForm(['status' => null, 'start_date' => $yesterday, 'end_date' => $yesterday]);
        $host->apply($form);
        $this->assertSame('completed', $form->status);
    }

    public function test_date_add_derives_end_date_from_the_linked_campaign_schedule(): void
    {
        $this->bindCampaignFixture();
        Schema::create('campaign_email_schedules', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('campaign_id');
            $table->integer('send_after_days')->nullable();
        });
        \DB::table('campaign_email_schedules')->insert([
            ['campaign_id' => 7, 'send_after_days' => 0],
            ['campaign_id' => 7, 'send_after_days' => 3],
            ['campaign_id' => 7, 'send_after_days' => 7],
            ['campaign_id' => 9, 'send_after_days' => 2],
        ]);

        $host = $this->host(self::CAMPAIGN_MANIFEST);
        $form = $this->campaignForm(['campaign_name' => 7, 'start_date' => '2026-11-14', 'status' => null]);
        $host->apply($form);
        // Start + the LONGEST schedule offset.
        $this->assertSame('2026-11-21', $form->end_date);
        // And the derived end date feeds the status case in
        // the same save (two-pass settling).
        $this->assertSame('in_progress', $form->status);

        // A campaign with no schedule rows refuses the
        // save, with Desk's own message, keyed to the link.
        $form = $this->campaignForm(['campaign_name' => 8, 'start_date' => '2026-11-14', 'status' => null]);
        try {
            $host->apply($form);
            $this->fail('Expected the no-schedule refusal.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['Please set up the Campaign Schedule in the Campaign.'],
                $e->errors()['campaign_name'] ?? null
            );
        }
    }

    public function test_lost_reasons_are_mandatory_when_lost_with_desks_message(): void
    {
        $host = $this->host(self::OPPORTUNITY_MANIFEST);

        // Declare with zero reasons → refused, Desk's words.
        $form = DerivationProbeForm::fromArray(['status' => 'lost', 'lost_reasons' => []]);
        try {
            $host->apply($form);
            $this->fail('Expected the lost-reasons refusal.');
        } catch (ValidationException $e) {
            $this->assertSame(['Please select at least one Lost Reason'], $e->errors()['lost_reasons'] ?? null);
        }

        // With a reason picked, the same save passes.
        $form = DerivationProbeForm::fromArray(['status' => 'lost', 'lost_reasons' => [2]]);
        $host->apply($form);
        $this->addToAssertionCount(1);

        // Not lost → no requirement at all.
        $form = DerivationProbeForm::fromArray(['status' => 'open', 'lost_reasons' => []]);
        $host->apply($form);
        $this->addToAssertionCount(1);
    }

    public function test_unprovided_collection_is_judged_by_its_stored_set(): void
    {
        $host = $this->host(self::OPPORTUNITY_MANIFEST);

        // A partial write that never mentions the reasons
        // (a status-only API edit) is judged against the
        // stored set — the record HAS reasons, so no
        // refusal may fire.
        $existing = new DerivationProbeForm;
        $existing->status = 'lost';
        $existing->lost_reasons = [1, 2];
        $form = DerivationProbeForm::fromArray(['status' => 'lost']);
        $host->apply($form, $existing);
        $this->addToAssertionCount(1);

        // The same partial write against a record whose
        // stored set is genuinely empty IS refused.
        $existing = new DerivationProbeForm;
        $existing->status = 'lost';
        $existing->lost_reasons = [];
        $form = DerivationProbeForm::fromArray(['status' => 'lost']);
        try {
            $host->apply($form, $existing);
            $this->fail('Expected the lost-reasons refusal for an empty stored set.');
        } catch (ValidationException $e) {
            $this->assertSame(['Please select at least one Lost Reason'], $e->errors()['lost_reasons'] ?? null);
        }
    }

    public function test_derived_values_persist_on_update_through_the_api(): void
    {
        // The Phase 8 regression this pins: the service's
        // update writes only PROVIDED fields, so a derived
        // value for a field the save never submits (a
        // hidden status) was silently dropped on update
        // while landing fine on create. The behaviour pass
        // now marks every value it changes as provided.
        Schema::create('derivation_persist_probes', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('is_signed')->nullable();
            $table->string('start_date')->nullable();
            $table->string('end_date')->nullable();
            $table->string('status')->nullable();
        });
        $this->app->instance(DerivationPersistProbeService::class, new DerivationPersistProbeService([
            'name' => 'PersistProbes',
            'fields' => [
                'name' => ['type' => 'string', 'label' => 'Name'],
                'is_signed' => ['type' => 'boolean', 'label' => 'Signed'],
                'start_date' => ['type' => 'date', 'label' => 'Start Date'],
                'end_date' => ['type' => 'date', 'label' => 'End Date'],
                'status' => [
                    'type' => 'string', 'label' => 'Status',
                    'computed' => [
                        'cases' => [
                            ['when' => ['field' => 'is_signed', 'empty' => true], 'value' => 'unsigned'],
                            ['when' => ['field' => 'start_date', 'gt' => '@today'], 'value' => 'inactive'],
                            ['when' => ['field' => 'end_date', 'lt' => '@today'], 'value' => 'inactive'],
                        ],
                        'default' => 'active',
                    ],
                ],
            ],
        ]));
        \Illuminate\Support\Facades\Route::post('/derivation-persist-api', [DerivationPersistProbeApiController::class, 'save']);
        $admin = $this->makeSuperAdmin();

        $create = $this->actingAs($admin)->postJson('/derivation-persist-api', [
            'name' => 'P1', 'is_signed' => 0,
        ]);
        $create->assertStatus(201);
        $id = (int) \DB::table('derivation_persist_probes')->first()->id;
        $this->assertSame('unsigned', \DB::table('derivation_persist_probes')->find($id)->status);

        // The update never mentions status: the derivation
        // must still land in the row.
        $update = $this->actingAs($admin)->postJson('/derivation-persist-api', [
            'id' => $id, 'name' => 'P1', 'is_signed' => 1,
            'start_date' => date('Y-m-d', strtotime('-1 day')),
            'end_date' => date('Y-m-d', strtotime('+30 days')),
        ]);
        $update->assertStatus(200);
        $this->assertSame('active', \DB::table('derivation_persist_probes')->find($id)->status);
    }

    private function campaignForm(array $values): DerivationProbeForm
    {
        $form = new DerivationProbeForm;
        foreach ($values as $key => $value) {
            $form->{$key} = $value;
        }

        return $form;
    }

    /** Register a fixture 'Campaigns' slice for viaTargetSlice + date_add resolution. */
    private function bindCampaignFixture(): void
    {
        $dir = sys_get_temp_dir().'/laraslice-campaign-fixture';
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/slice.yaml', json_encode([
            'name' => 'Campaigns',
            'fields' => [
                'campaign_schedules' => [
                    'name' => 'campaign_schedules', 'type' => 'repeater',
                    'table' => 'campaign_email_schedules', 'foreign_key' => 'campaign_id',
                    'repeater_fields' => [['handle' => 'send_after_days', 'type' => 'integer']],
                ],
            ],
        ]));
        $manifest = new SliceManifest($dir.'/slice.yaml');
        $manager = new class($this->app, $manifest) extends SliceManager
        {
            public function __construct($app, private readonly SliceManifest $fixture)
            {
                parent::__construct($app);
            }

            public function getSlice(string $name): ?SliceManifest
            {
                return $name === 'Campaigns' ? $this->fixture : null;
            }
        };
        $this->app->instance(SliceManager::class, $manager);
    }
}

final class DerivationProbeForm extends BaseFormBusinessObject
{
    public ?bool $is_signed = null;

    public ?string $start_date = null;

    public ?string $end_date = null;

    public ?string $status = null;

    public ?bool $requires_fulfilment = null;

    public ?string $fulfilment_deadline = null;

    public ?array $fulfilment_terms = null;

    public ?string $fulfilment_status = null;

    public ?array $lost_reasons = null;

    public ?int $campaign_name = null;
}

final class DerivationPersistProbeForm extends BaseFormBusinessObject
{
    public ?string $name = null;

    public ?bool $is_signed = null;

    public ?string $start_date = null;

    public ?string $end_date = null;

    public ?string $status = null;
}

final class DerivationPersistProbeModel extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'derivation_persist_probes';

    protected $guarded = [];

    public $timestamps = false;
}

final class DerivationPersistProbeService extends \LaraSlice\Core\Base\BaseSliceService
{
    /** @param array<string, mixed> $manifest */
    public function __construct(private readonly array $manifest = []) {}

    public function manifestArray(): array
    {
        return $this->manifest;
    }

    protected function getModelClass(): string
    {
        return DerivationPersistProbeModel::class;
    }

    protected function mapToForm(\Illuminate\Database\Eloquent\Model $model): \LaraSlice\Core\Contracts\IBusinessObject
    {
        $form = new DerivationPersistProbeForm;
        $form->id = $model->getKey();
        $form->name = $model->getAttribute('name');
        $form->is_signed = $model->getAttribute('is_signed') !== null ? (bool) $model->getAttribute('is_signed') : null;
        $form->start_date = $model->getAttribute('start_date');
        $form->end_date = $model->getAttribute('end_date');
        $form->status = $model->getAttribute('status');

        return $form;
    }

    protected function mapToListing(\Illuminate\Database\Eloquent\Model $model): \LaraSlice\Core\Contracts\IBusinessObject
    {
        return $this->mapToForm($model);
    }

    protected function ownManifest(): array
    {
        return $this->manifest;
    }
}

final class DerivationPersistProbeApiController extends \LaraSlice\Core\Base\BaseSliceApiController
{
    protected function authorizeSlice(string $action): void
    {
        if (! auth()->user()) {
            abort(401, 'Unauthenticated.');
        }
    }

    protected function ownManifest(): array
    {
        return app(DerivationPersistProbeService::class)->manifestArray();
    }

    protected function getService(): \LaraSlice\Core\Contracts\IFormDataService&\LaraSlice\Core\Contracts\IListingDataService
    {
        return app(DerivationPersistProbeService::class);
    }

    protected function getFormClass(): string
    {
        return DerivationPersistProbeForm::class;
    }

    protected function getFilterClass(): string
    {
        return \LaraSlice\Core\Base\BaseFilter::class;
    }
}
