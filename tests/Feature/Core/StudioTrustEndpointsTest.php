<?php

namespace LaraSlice\Tests\Feature\Core;

use Illuminate\Http\Request;
use LaraSlice\Blueprint\BlueprintStudioController;
use LaraSlice\Tests\TestCase;
use LaraSlice\Wizard\WizardController;

/**
 * The Manage Activity tab's declaration-history endpoint and
 * the Design draft endpoints: history is filtered to the
 * slice's own files (newest first); drafts round-trip
 * through storage and reject empty payloads.
 */
class StudioTrustEndpointsTest extends TestCase
{
    public function test_declaration_history_filters_and_orders(): void
    {
        $dir = base_path('porting/history');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $index = [
            'version' => 1,
            'state' => [],
            'entries' => [
                ['id' => '001', 'recorded_at' => '2026-11-01T10:00:00+00:00', 'actor' => 'test', 'command' => 'slice:cache', 'changes' => [
                    ['file' => 'app/Slices/ERP/Finance/Invoices/slice.yaml', 'action' => 'changed'],
                    ['file' => 'app/Slices/ERP/Work/Tasks/slice.yaml', 'action' => 'changed'],
                ]],
                ['id' => '002', 'recorded_at' => '2026-11-02T10:00:00+00:00', 'actor' => 'test', 'command' => 'slice:sync', 'changes' => [
                    ['file' => 'app/Slices/ERP/Finance/Invoices/slice.yaml', 'action' => 'changed'],
                ]],
            ],
        ];
        file_put_contents($dir.'/index.json', json_encode($index));

        try {
            $controller = new WizardController;
            $response = $controller->declarationHistory(Request::create('/laraslice/wizard/declaration-history?name=Invoices'));
            $data = json_decode($response->getContent(), true)['data'];

            $this->assertCount(2, $data, 'both Invoices entries, Tasks-only files excluded');
            $this->assertSame('002', $data[0]['id'], 'newest first');
            $this->assertSame(['app/Slices/ERP/Finance/Invoices/slice.yaml'], $data[0]['files']);
            $this->assertCount(1, $data[1]['files'], 'the mixed entry keeps only the Invoices file');

            $empty = $controller->declarationHistory(Request::create('/laraslice/wizard/declaration-history?name=Nope'));
            $this->assertSame([], json_decode($empty->getContent(), true)['data']);
        } finally {
            unlink($dir.'/index.json');
        }
    }

    public function test_blueprint_presets_are_served_from_the_package_file(): void
    {
        $controller = new BlueprintStudioController;
        $response = $controller->blueprintPresets(Request::create('/laraslice/studio/blueprint/presets'));
        $data = json_decode($response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('legal_dispute_adjudication', $data);
        $this->assertCount(8, $data['legal_dispute_adjudication'], 'the example suite ships as data now, not as panel payload');
        $first = $data['legal_dispute_adjudication'][0];
        $this->assertArrayHasKey('blueprint', $first);
        $this->assertArrayHasKey('source', $first);
    }

    private function bindAiStub(?string $reply, ?string $key = 'test-key'): void
    {
        $stub = new class(app(\LaraSlice\Core\Discovery\SliceManager::class), $reply, $key) extends \LaraSlice\Core\Ai\AiEngine
        {
            public function __construct($manager, private ?string $replyText, private ?string $keyValue)
            {
                parent::__construct($manager);
            }

            public function getActiveProvider(): string
            {
                return 'opencode';
            }

            public function providerKey(string $provider): ?string
            {
                return $this->keyValue;
            }

            public function callCustomProvider(string $message, array $context, array $pageContext, string $provider, array $history = []): ?string
            {
                return $this->replyText;
            }
        };
        $this->app->instance(\LaraSlice\Core\Ai\AiEngine::class, $stub);
    }

    public function test_draft_from_brief_pipeline(): void
    {
        $yaml = <<<'YAML'
schema_version: 1
name: Appointments
handle: appointments
domain: Clinic
description: Book and manage clinic appointments.
models:
  - handle: appointment
    name: Appointment
    table: appointments
    root: true
    fields:
      - handle: title
        label: Title
        type: string
        required: true
        nullable: false
        width: 50
      - handle: starts_at
        label: Starts At
        type: datetime
        required: true
        nullable: false
        width: 50
YAML;
        $this->bindAiStub("Here is your blueprint:\n```yaml\n".$yaml."\n```");

        $controller = new BlueprintStudioController;
        $response = $controller->draftFromBrief(Request::create('/laraslice/studio/blueprint/draft-from-brief', 'POST', [
            'brief' => 'A clinic app: Appointments that patients book with doctors, with a title and a start time.',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['drafted'], 'fenced YAML from the provider must be extracted and validated: '.json_encode($data));
        $this->assertSame('Appointments', $data['name']);
        $this->assertStringContainsString('handle: appointments', $data['source']);
    }

    public function test_draft_from_brief_with_a_mind_seed_stashes_it(): void
    {
        $blueprint = <<<'YAML'
schema_version: 1
name: Appointments
handle: appointments
domain: Clinic
description: Book and manage clinic appointments.
models:
  - handle: appointment
    name: Appointment
    table: appointments
    root: true
    fields:
      - handle: title
        label: Title
        type: string
        required: true
        nullable: false
        width: 50
YAML;
        $seed = <<<'YAML'
purpose: A small clinic system so patients can book appointments and staff can manage the day.
actors:
  - name: Patient
    kind: external
    description: Books and attends appointments.
  - name: Staff
    kind: internal
    description: Manages the appointment book.
journeys:
  - name: Book an appointment
    actor: Patient
    steps:
      - Pick a doctor and a time
      - Confirm the booking
    outcome: The appointment is booked.
YAML;
        $this->bindAiStub("```yaml\n".$blueprint."\n```\n```yaml\n".$seed."\n```");

        $controller = new BlueprintStudioController;
        $response = $controller->draftFromBrief(Request::create('/laraslice/studio/blueprint/draft-from-brief', 'POST', [
            'brief' => 'A clinic app: patients book appointments, staff manage the day.',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['drafted']);
        $this->assertSame('appointments', $data['handle']);
        $this->assertSame(['Patient', 'Staff'], $data['mind']['actors']);
        $this->assertSame(['Book an appointment'], $data['mind']['journeys']);
        $this->assertStringContainsString('clinic system', $data['mind']['purpose']);

        $stash = storage_path('app/laraslice/drafts/appointments.mind.json');
        $this->assertFileExists($stash, 'the seed is stashed as working state for the apply step');
        @unlink($stash);
    }

    public function test_draft_from_brief_without_a_mind_seed_still_drafts(): void
    {
        $blueprint = <<<'YAML'
schema_version: 1
name: Appointments
handle: appointments
domain: Clinic
description: Book and manage clinic appointments.
models:
  - handle: appointment
    name: Appointment
    table: appointments
    root: true
    fields:
      - handle: title
        label: Title
        type: string
        required: true
        nullable: false
        width: 50
YAML;
        $this->bindAiStub("```yaml\n".$blueprint."\n```");

        $controller = new BlueprintStudioController;
        $response = $controller->draftFromBrief(Request::create('/laraslice/studio/blueprint/draft-from-brief', 'POST', [
            'brief' => 'A clinic app: patients book appointments, staff manage the day.',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertTrue($data['drafted']);
        $this->assertNull($data['mind'], 'a blueprint-only reply drafts normally, with no seed');
    }

    public function test_apply_mind_seed_writes_once_and_never_overwrites(): void
    {
        $this->assertNull(\LaraSlice\Support\SystemMind::path(), 'precondition: the test app has no Mind yet');

        $stash = storage_path('app/laraslice/drafts/appointments.mind.json');
        if (! is_dir(dirname($stash))) {
            mkdir(dirname($stash), 0775, true);
        }
        file_put_contents($stash, json_encode([
            'seed' => [
                'purpose' => 'A small clinic system for bookings.',
                'actors' => [['name' => 'Patient', 'kind' => 'external', 'description' => 'Books appointments.']],
                'journeys' => [['name' => 'Book an appointment', 'actor' => 'Patient', 'steps' => ['Pick a time'], 'outcome' => 'Booked.']],
            ],
            'system_name' => 'Appointments',
        ]));

        $controller = new BlueprintStudioController;
        try {
            $response = $controller->applyMindSeed(Request::create('/laraslice/studio/mind-seed/apply', 'POST', ['handle' => 'appointments']));
            $data = json_decode($response->getContent(), true);
            $this->assertTrue($data['applied'], 'the first apply writes the Mind: '.json_encode($data));

            $mind = \LaraSlice\Support\SystemMind::load();
            $this->assertSame('A small clinic system for bookings.', $mind['system']['purpose']);
            $this->assertSame('Patient', $mind['actors'][0]['name']);
            $this->assertSame('Book an appointment', $mind['journeys'][0]['name']);

            $second = $controller->applyMindSeed(Request::create('/laraslice/studio/mind-seed/apply', 'POST', ['handle' => 'appointments']));
            $this->assertSame(409, $second->getStatusCode(), 'a second apply is refused: an existing Mind is never overwritten');
            $this->assertFalse(json_decode($second->getContent(), true)['applied']);
        } finally {
            @unlink($stash);
            $path = \LaraSlice\Support\SystemMind::path();
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    public function test_propose_mind_journeys_filters_to_addable_proposals(): void
    {
        $this->assertNull(\LaraSlice\Support\SystemMind::path(), 'precondition: the test app has no Mind yet');
        \LaraSlice\Support\SystemMind::applyBriefSeed([
            'purpose' => 'A small clinic system for bookings.',
            'actors' => [
                ['name' => 'Patient', 'kind' => 'external', 'description' => 'Books appointments.'],
                ['name' => 'Staff', 'kind' => 'internal', 'description' => 'Runs the desk.'],
            ],
            'journeys' => [['name' => 'Book an appointment', 'actor' => 'Patient', 'steps' => ['Pick a time'], 'outcome' => 'Booked.']],
        ], 'Appointments');

        $reply = '```json'."\n".json_encode([
            ['name' => 'Pay a bill', 'actor' => 'Patient', 'steps' => ['Open the invoice', 'Pay online'], 'outcome' => 'The bill is paid.'],
            ['name' => 'Ghost journey', 'actor' => 'Nobody', 'steps' => ['Vanish'], 'outcome' => ''],
            ['name' => 'Book an appointment', 'actor' => 'Patient', 'steps' => ['Duplicate'], 'outcome' => ''],
        ])."\n".'```';
        $this->bindAiStub($reply);

        $controller = new BlueprintStudioController;
        try {
            $response = $controller->proposeMindJourneys(Request::create('/laraslice/studio/plan/propose-journeys', 'POST', [
                'instruction' => 'Patients should be able to pay a bill online after their visit.',
            ]));
            $data = json_decode($response->getContent(), true);

            $this->assertTrue($data['proposed'], 'one addable journey survives the filters: '.json_encode($data));
            $this->assertSame(['Pay a bill | Patient | Open the invoice > Pay online | The bill is paid.'], $data['lines']);
        } finally {
            $path = \LaraSlice\Support\SystemMind::path();
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    public function test_propose_mind_journeys_without_a_provider_key_is_honest(): void
    {
        $this->assertNull(\LaraSlice\Support\SystemMind::path(), 'precondition: the test app has no Mind yet');
        \LaraSlice\Support\SystemMind::applyBriefSeed([
            'purpose' => 'A small clinic system for bookings.',
            'actors' => [['name' => 'Patient', 'kind' => 'external', 'description' => 'Books appointments.']],
            'journeys' => [],
        ], 'Appointments');
        $this->bindAiStub(null, null);

        $controller = new BlueprintStudioController;
        try {
            $response = $controller->proposeMindJourneys(Request::create('/laraslice/studio/plan/propose-journeys', 'POST', [
                'instruction' => 'Patients should be able to pay a bill online after their visit.',
            ]));
            $data = json_decode($response->getContent(), true);

            $this->assertFalse($data['proposed']);
            $this->assertStringContainsString('No AI provider is connected', $data['reason']);
        } finally {
            $path = \LaraSlice\Support\SystemMind::path();
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    public function test_draft_from_brief_without_a_provider_key_is_honest(): void
    {
        $this->bindAiStub(null, null);

        $controller = new BlueprintStudioController;
        $response = $controller->draftFromBrief(Request::create('/laraslice/studio/blueprint/draft-from-brief', 'POST', [
            'brief' => 'A clinic app with appointments and doctors and patients and reminders.',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['drafted']);
        $this->assertStringContainsString('No AI provider is connected', $data['reason']);
    }

    public function test_draft_from_brief_rejects_an_invalid_blueprint(): void
    {
        $this->bindAiStub("```yaml\nschema_version: 9\nname: Nope\n```");

        $controller = new BlueprintStudioController;
        $response = $controller->draftFromBrief(Request::create('/laraslice/studio/blueprint/draft-from-brief', 'POST', [
            'brief' => 'A clinic app with appointments and doctors and patients and reminders.',
        ]));
        $data = json_decode($response->getContent(), true);

        $this->assertFalse($data['drafted']);
        $this->assertStringContainsString('did not validate', $data['reason']);
    }

    public function test_blueprint_draft_round_trip(): void
    {
        $controller = new BlueprintStudioController;
        $path = storage_path('app/laraslice/drafts/invoices.blueprint.yaml');
        @unlink($path);

        $missing = $controller->blueprintDraft(Request::create('/laraslice/studio/blueprint/draft?handle=invoices'));
        $this->assertFalse(json_decode($missing->getContent(), true)['exists']);

        $saved = $controller->blueprintDraftSave(Request::create('/laraslice/studio/blueprint/draft', 'POST', [
            'handle' => 'invoices',
            'source' => "schema_version: 1\nhandle: invoices\n",
        ]));
        $this->assertTrue(json_decode($saved->getContent(), true)['saved']);

        try {
            $loaded = $controller->blueprintDraft(Request::create('/laraslice/studio/blueprint/draft?handle=invoices'));
            $data = json_decode($loaded->getContent(), true);
            $this->assertTrue($data['exists']);
            $this->assertStringContainsString('handle: invoices', $data['source']);
            $this->assertNotEmpty($data['saved_at']);

            $rejected = $controller->blueprintDraftSave(Request::create('/laraslice/studio/blueprint/draft', 'POST', [
                'handle' => 'invoices',
                'source' => '   ',
            ]));
            $this->assertSame(422, $rejected->getStatusCode());
        } finally {
            @unlink($path);
        }
    }
}
