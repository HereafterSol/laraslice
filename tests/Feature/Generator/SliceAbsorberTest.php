<?php

declare(strict_types=1);

namespace LaraSlice\Tests\Feature\Generator;

use LaraSlice\Core\Discovery\SliceManifest;
use LaraSlice\Generator\ArtefactRegenerator;
use LaraSlice\Generator\SliceAbsorber;
use LaraSlice\Generator\SliceGenerator;
use LaraSlice\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * slice:absorb (reverse path): regen-artefacts names what
 * the Heart cannot re-derive; absorb classifies each loss
 * and, for the declarable ones, writes the knowledge back
 * into the Heart — the only file it may ever write. Custom
 * logic must classify hand-owned and never be proposed;
 * knowledge today's vocabulary cannot express must
 * classify vocabulary-gap; a second absorb must propose
 * nothing.
 */
final class SliceAbsorberTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/ls-absorb-'.uniqid();
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function generateWidget(): string
    {
        return (new SliceGenerator($this->root, 'App\\Slices'))->generate('AbsorbWidget', [
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ['name' => 'starts_on', 'type' => 'date', 'nullable' => true],
            ['name' => 'owner_id', 'type' => 'integer', 'nullable' => true],
            ['name' => 'notes', 'type' => 'text', 'nullable' => true],
        ], false, ['app' => 'Sandbox', 'domain' => 'Testing']);
    }

    private function appendToModel(string $sliceDir, string $php): void
    {
        $path = $sliceDir.'/Models/AbsorbWidget.php';
        $model = rtrim((string) file_get_contents($path));
        $model = substr($model, 0, -1).$php."\n}\n";
        file_put_contents($path, $model);
    }

    /** @return array<string, array<string, mixed>> loss => item */
    private function itemsByLoss(array $analysis): array
    {
        $out = [];
        foreach ($analysis['items'] as $item) {
            $out[$item['loss']] = $item;
        }

        return $out;
    }

    public function test_dry_run_classifies_losses_and_writes_nothing(): void
    {
        $sliceDir = $this->generateWidget();
        $modelPath = $sliceDir.'/Models/AbsorbWidget.php';

        // Hand knowledge of every class: a plain relation,
        // a relation keyed on a non-id local key, a hasOne
        // (no declaration vocabulary), custom logic, and a
        // mass-assigned column the Heart never declared.
        $model = (string) file_get_contents($modelPath);
        $model = str_replace("'notes'", "'notes',\n  99 => 'legacy_code'", $model);
        file_put_contents($modelPath, $model);
        $this->appendToModel($sliceDir, <<<'PHP'

    public function legacyOwner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }

    public function members(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Member::class, 'widget_id', 'user_id');
    }

    public function profile(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\Profile::class, 'widget_id');
    }

    public function summary(): string
    {
        return strtoupper((string) ($this->title ?? ''));
    }
PHP);

        $yamlPath = $sliceDir.'/slice.yaml';
        $heartBefore = md5_file($yamlPath);
        $manifest = new SliceManifest($yamlPath);

        $absorber = new SliceAbsorber;
        $analysis = $absorber->analyse($manifest);
        $items = $this->itemsByLoss($analysis);

        $this->assertSame(SliceAbsorber::CLASS_DECLARABLE, $items['method:legacyOwner']['class']);
        $relation = $items['method:legacyOwner']['proposal']['relation'];
        $this->assertSame('belongsTo', $relation['type']);
        $this->assertSame('user', $relation['model']);
        $this->assertSame('owner_id', $relation['foreign_key']);

        $this->assertSame(SliceAbsorber::CLASS_DECLARABLE, $items['method:members']['class']);
        $this->assertSame('user_id', $items['method:members']['proposal']['relation']['local_key']);

        $this->assertSame(SliceAbsorber::CLASS_GAP, $items['method:profile']['class']);
        $this->assertNull($items['method:profile']['proposal']);

        $this->assertSame(SliceAbsorber::CLASS_HAND, $items['method:summary']['class']);
        $this->assertNull($items['method:summary']['proposal']);

        $this->assertSame(SliceAbsorber::CLASS_DECLARABLE, $items['fillable:legacy_code']['class']);
        $this->assertSame('legacy_code', $items['fillable:legacy_code']['proposal']['field']['name']);

        // Analysis is a dry-run: the Heart is untouched.
        $this->assertSame($heartBefore, md5_file($yamlPath));
    }

    public function test_write_absorbs_declarations_into_the_heart_only(): void
    {
        $sliceDir = $this->generateWidget();
        $modelPath = $sliceDir.'/Models/AbsorbWidget.php';
        $model = (string) file_get_contents($modelPath);
        $model = str_replace("'notes'", "'notes',\n  99 => 'legacy_code'", $model);
        file_put_contents($modelPath, $model);
        $this->appendToModel($sliceDir, <<<'PHP'

    public function legacyOwner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }
PHP);

        $yamlPath = $sliceDir.'/slice.yaml';
        $heartTextBefore = (string) file_get_contents($yamlPath);
        $modelBefore = md5_file($modelPath);

        $absorber = new SliceAbsorber;
        $manifest = new SliceManifest($yamlPath);
        $analysis = $absorber->analyse($manifest);
        $result = $absorber->applyHeart($manifest, $analysis, $this->root.'/backups');

        $this->assertTrue($result['written'], 'Heart write was refused: '.($result['refusal'] ?? ''));
        $this->assertFileExists($result['backupDir'].'/slice.yaml');
        $this->assertSame($heartTextBefore, (string) file_get_contents($result['backupDir'].'/slice.yaml'));

        // Absorb NEVER edits a generated artefact.
        $this->assertSame($modelBefore, md5_file($modelPath));

        // The Heart now carries the knowledge.
        $data = Yaml::parseFile($yamlPath);
        $relations = $data['models'][0]['relations'];
        $this->assertCount(1, $relations);
        $this->assertSame('legacyOwner', $relations[0]['name']);
        $this->assertSame('belongsTo', $relations[0]['type']);
        $this->assertSame('user', $relations[0]['model']);
        $this->assertSame('owner_id', $relations[0]['foreign_key']);
        $this->assertArrayHasKey('legacy_code', $data['fields']);

        // Whole-file discipline: the splice may only have
        // removed the inline relations placeholder line.
        $removed = array_diff(explode("\n", $heartTextBefore), explode("\n", (string) file_get_contents($yamlPath)));
        foreach ($removed as $line) {
            $this->assertMatchesRegularExpression('/^\s*relations:\s*\{\s*\}\s*$/', $line, 'splice removed an unexpected line: '.$line);
        }

        // Regen now re-derives the model without those
        // losses, and a second absorb proposes nothing.
        $manifest = new SliceManifest($yamlPath);
        $regenerator = new ArtefactRegenerator;
        $staged = $regenerator->stage($manifest);
        try {
            $report = $regenerator->diff($manifest, $staged);
            $modelRow = collect($report)->firstWhere('artefact', 'model');
            $this->assertNotContains('method:legacyOwner', $modelRow['lost']);
            $this->assertNotContains('fillable:legacy_code', $modelRow['lost']);
        } finally {
            $regenerator->discardStaging($staged['staging']);
        }

        $second = $absorber->analyse($manifest);
        $this->assertSame([], $absorber->proposals($second), 'second absorb still proposes changes');
        $again = $absorber->applyHeart($manifest, $second, $this->root.'/backups');
        $this->assertFalse($again['written']);
    }

    public function test_hand_owned_logic_is_never_proposed_or_written(): void
    {
        $sliceDir = $this->generateWidget();
        $this->appendToModel($sliceDir, <<<'PHP'

    public function summary(): string
    {
        return strtoupper((string) ($this->title ?? ''));
    }
PHP);
        $servicePath = $sliceDir.'/Services/AbsorbWidgetSliceService.php';
        $service = rtrim((string) file_get_contents($servicePath));
        $service = substr($service, 0, -1).<<<'PHP'

    public function customTotals(): array
    {
        return ['hand' => true];
    }
}
PHP;
        file_put_contents($servicePath, $service);

        $yamlPath = $sliceDir.'/slice.yaml';
        $heartBefore = md5_file($yamlPath);
        $absorber = new SliceAbsorber;
        $analysis = $absorber->analyse(new SliceManifest($yamlPath));
        $items = $this->itemsByLoss($analysis);

        $this->assertSame(SliceAbsorber::CLASS_HAND, $items['method:summary']['class']);
        $this->assertSame(SliceAbsorber::CLASS_HAND, $items['method:customTotals']['class']);
        $this->assertSame([], $absorber->proposals($analysis));

        $result = $absorber->applyHeart(new SliceManifest($yamlPath), $analysis, $this->root.'/backups');
        $this->assertFalse($result['written']);
        $this->assertSame($heartBefore, md5_file($yamlPath));
    }

    public function test_write_handles_null_and_empty_sequence_relations_lines(): void
    {
        // Ported Hearts in the wild carry the root model's
        // relations as `relations: null` or `relations: []`
        // — single-line empty values the splice must
        // replace with a block (the first port-fresh sweep
        // refused exactly these, safely but unnecessarily).
        foreach (['relations: null', 'relations: []'] as $shape) {
            $sliceDir = $this->generateWidget();
            $this->appendToModel($sliceDir, <<<'PHP'

    public function legacyOwner(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_id');
    }
PHP);
            $yamlPath = $sliceDir.'/slice.yaml';
            $text = (string) file_get_contents($yamlPath);
            $text = preg_replace('/^\s*relations:\s*\{\s*\}\s*$/m', '    '.$shape, $text, 1);
            file_put_contents($yamlPath, $text);

            $absorber = new SliceAbsorber;
            $manifest = new SliceManifest($yamlPath);
            $result = $absorber->applyHeart($manifest, $absorber->analyse($manifest), $this->root.'/backups');
            $this->assertTrue($result['written'], "write refused for shape {$shape}: ".($result['refusal'] ?? ''));

            $data = Yaml::parseFile($yamlPath);
            $this->assertSame('legacyOwner', $data['models'][0]['relations'][0]['name']);
            $second = $absorber->analyse(new SliceManifest($yamlPath));
            $this->assertSame([], $absorber->proposals($second));
            $this->removeTree($sliceDir);
        }
    }

    public function test_declaration_model_uses_the_form_that_survives_generator_derivation(): void
    {
        // The generator derives a declared relation's
        // target as studly(singular(model)) — and the
        // inflector rewrites already-singular Latin-form
        // names (singular('leave_quota') = 'leave_quotum'),
        // so absorb must declare the PLURAL handle
        // ('leave_quotas'), the only form that round-trips
        // to the lived LeaveQuota class.
        $sliceDir = $this->generateWidget();
        $this->appendToModel($sliceDir, <<<'PHP'

    public function quotas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\LeaveQuota::class, 'widget_id');
    }
PHP);
        $absorber = new SliceAbsorber;
        $proposals = $absorber->proposals($absorber->analyse(new SliceManifest($sliceDir.'/slice.yaml')));
        $this->assertArrayHasKey('relation:quotas', $proposals);
        $this->assertSame('leave_quotas', $proposals['relation:quotas']['relation']['model']);
        $this->removeTree($sliceDir);
    }

    public function test_audit_corrects_a_declaration_whose_model_derives_to_another_target(): void
    {
        // The name-keyed regen loss list cannot see this:
        // the Heart declares the relation and staging DOES
        // emit a method with the same name — but bound to
        // the WRONG target, because 'leave_quota' derives
        // to LeaveQuotum. The declared-relation audit must
        // propose a correction, and --write must replace
        // the entry in place.
        $sliceDir = $this->generateWidget();
        $this->appendToModel($sliceDir, <<<'PHP'

    public function quotas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\LeaveQuota::class, 'widget_id');
    }
PHP);
        $yamlPath = $sliceDir.'/slice.yaml';
        $text = (string) file_get_contents($yamlPath);
        $text = preg_replace_callback('/^(\s*)relations:\s*\{\s*\}\s*$/m', static function (array $m): string {
            $i = $m[1];

            return $i."relations:\n{$i}- name: quotas\n{$i}  type: hasMany\n{$i}  model: leave_quota\n{$i}  foreign_key: widget_id";
        }, $text, 1);
        file_put_contents($yamlPath, $text);

        $absorber = new SliceAbsorber;
        $analysis = $absorber->analyse(new SliceManifest($yamlPath));
        $proposals = $absorber->proposals($analysis);
        $this->assertArrayHasKey('relation:quotas', $proposals);
        $this->assertSame('quotas', $proposals['relation:quotas']['replace'] ?? null);
        $this->assertSame('leave_quotas', $proposals['relation:quotas']['relation']['model']);

        $result = $absorber->applyHeart(new SliceManifest($yamlPath), $analysis, $this->root.'/backups');
        $this->assertTrue($result['written'], 'correction write refused: '.($result['refusal'] ?? ''));

        $data = Yaml::parseFile($yamlPath);
        $this->assertSame('leave_quotas', $data['models'][0]['relations'][0]['model']);
        // Faithful now: a second absorb proposes nothing.
        $this->assertSame([], $absorber->proposals($absorber->analyse(new SliceManifest($yamlPath))));
        $this->removeTree($sliceDir);
    }

    public function test_generator_emits_has_many_through_declaration(): void
    {
        // The 7 Nov vocabulary addition: a declared
        // hasManyThrough emits the lived idiom (two
        // modelClass resolutions + the key chain), and a
        // declaration carrying no keys emits the bare
        // two-class call.
        $sliceDir = (new SliceGenerator($this->root, 'App\\Slices'))->generate('Ledger', [
            ['name' => 'label', 'type' => 'string', 'nullable' => true],
        ], false, [
            'app' => 'Sandbox', 'domain' => 'Testing',
            'relations' => [
                ['name' => 'payments', 'type' => 'hasManyThrough', 'model' => 'payments', 'through' => 'invoices',
                    'first_key' => 'client_id', 'second_key' => 'invoice_id', 'local_key' => 'user_id', 'second_local_key' => 'id'],
                ['name' => 'receipts', 'type' => 'hasManyThrough', 'model' => 'receipt', 'through' => 'invoice'],
            ],
        ]);
        $model = (string) file_get_contents($sliceDir.'/Models/Ledger.php');
        $this->assertStringContainsString('public function payments(): \Illuminate\Database\Eloquent\Relations\HasManyThrough', $model);
        $this->assertStringContainsString("modelClass('Payment')", $model);
        $this->assertStringContainsString("modelClass('Invoice')", $model);
        $this->assertStringContainsString("return \$this->hasManyThrough(\$class, \$throughClass, 'client_id', 'invoice_id', 'user_id', 'id');", $model);
        $this->assertStringContainsString('public function receipts(): \Illuminate\Database\Eloquent\Relations\HasManyThrough', $model);
        $this->assertStringContainsString("return \$this->hasManyThrough(\$class, \$throughClass);", $model);
    }

    public function test_generator_emits_declared_field_casts(): void
    {
        // The field-level `cast` key: the model gains the
        // declared cast; the encrypted flag remains the
        // stronger declaration for its own column.
        $sliceDir = (new SliceGenerator($this->root, 'App\\Slices'))->generate('Vaults', [
            ['name' => 'label', 'type' => 'string', 'nullable' => true],
            ['name' => 'payload', 'type' => 'json', 'nullable' => true, 'cast' => 'array'],
            ['name' => 'secret_note', 'type' => 'string', 'nullable' => true, 'encrypted' => true, 'cast' => 'array'],
        ], false, ['app' => 'Sandbox', 'domain' => 'Testing']);
        $model = (string) file_get_contents($sliceDir.'/Models/Vault.php');
        $this->assertStringContainsString("'payload' => 'array'", $model);
        $this->assertStringContainsString("'secret_note' => 'encrypted'", $model);
        $this->assertStringNotContainsString("'secret_note' => 'array'", $model);
    }

    public function test_has_many_through_is_declarable_and_absorbs_into_the_heart(): void
    {
        $sliceDir = $this->generateWidget();
        $yamlPath = $sliceDir.'/slice.yaml';
        $this->appendToModel($sliceDir, <<<'PHP'

    public function payments(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        $class = app(\LaraSlice\Core\Discovery\SliceManager::class)->modelClass('Payment')
            ?? throw new \LogicException('No model class named Payment was found for invoice_id.');
        $throughClass = app(\LaraSlice\Core\Discovery\SliceManager::class)->modelClass('Invoice')
            ?? throw new \LogicException('No model class named Invoice was found for client_id.');

        return $this->hasManyThrough($class, $throughClass, 'client_id', 'invoice_id', 'user_id', 'id');
    }
PHP);

        $absorber = new SliceAbsorber;
        $analysis = $absorber->analyse(new SliceManifest($yamlPath));
        $items = $this->itemsByLoss($analysis);
        $payments = $items['method:payments'] ?? null;
        $this->assertNotNull($payments, 'the hasManyThrough method surfaces as an item');
        $this->assertSame(SliceAbsorber::CLASS_DECLARABLE, $payments['class']);
        $this->assertSame('hasManyThrough', $payments['proposal']['relation']['type']);
        $this->assertSame('invoice', $payments['proposal']['relation']['through']);
        $this->assertSame('client_id', $payments['proposal']['relation']['first_key']);
        $this->assertSame('invoice_id', $payments['proposal']['relation']['second_key']);
        $this->assertSame('user_id', $payments['proposal']['relation']['local_key']);
        $this->assertSame('id', $payments['proposal']['relation']['second_local_key']);

        $result = $absorber->applyHeart(new SliceManifest($yamlPath), $analysis, $this->root.'/backups');
        $this->assertTrue($result['written']);
        $heart = (string) file_get_contents($yamlPath);
        $this->assertStringContainsString('hasManyThrough', $heart);
        $this->assertStringContainsString('through: invoice', $heart);

        $after = $absorber->analyse(new SliceManifest($yamlPath));
        foreach ($after['items'] as $item) {
            $this->assertStringNotContainsString('payments', $item['loss'], 'payments fully absorbed');
        }
        $this->assertSame([], $absorber->proposals($after));
    }

    public function test_field_cast_is_declarable_and_absorbs_into_the_heart(): void
    {
        $sliceDir = $this->generateWidget();
        $yamlPath = $sliceDir.'/slice.yaml';
        $this->appendToModel($sliceDir, <<<'PHP'

    protected $casts = ['notes' => 'array', 'payload_json' => 'array'];
PHP);

        $absorber = new SliceAbsorber;
        $analysis = $absorber->analyse(new SliceManifest($yamlPath));
        $items = $this->itemsByLoss($analysis);
        // A cast on a declared field, and a cast-only
        // column with no field at all: both declarable.
        $this->assertSame(SliceAbsorber::CLASS_DECLARABLE, $items['cast:notes']['class'] ?? null);
        $this->assertSame('field-cast', $items['cast:notes']['proposal']['kind'] ?? null);
        $this->assertSame(SliceAbsorber::CLASS_DECLARABLE, $items['cast:payload_json']['class'] ?? null);
        $this->assertSame('field-cast', $items['cast:payload_json']['proposal']['kind'] ?? null);

        $result = $absorber->applyHeart(new SliceManifest($yamlPath), $analysis, $this->root.'/backups');
        $this->assertTrue($result['written']);

        $raw = Yaml::parseFile($yamlPath);
        $fields = \LaraSlice\Core\Discovery\ManifestRepository::fieldMap($raw['fields']);
        $this->assertSame('array', $fields['notes']['cast']);
        // The cast-only column is declared hidden, exactly
        // as the live artefacts keep it out of forms/lists.
        $this->assertSame('array', $fields['payload_json']['cast']);
        $this->assertTrue($fields['payload_json']['hidden']);
        $this->assertFalse($fields['payload_json']['show_in_form']);

        $after = $absorber->analyse(new SliceManifest($yamlPath));
        $this->assertSame([], $after['items'], 'cast knowledge fully absorbed: '.json_encode($after['items']));
    }

    public function test_relation_target_drift_is_declarable_when_the_lived_binding_is_plain(): void
    {
        $sliceDir = $this->generateWidget();
        $yamlPath = $sliceDir.'/slice.yaml';
        $modelPath = $sliceDir.'/Models/AbsorbWidget.php';
        $model = (string) file_get_contents($modelPath);
        $this->assertStringContainsString("modelClass('Owner')", $model);
        // The lived binding is hand-corrected to User and
        // carries prose comments WITH APOSTROPHES (the
        // Deals agent() shape): the body scan must survive
        // the comments for the binding to parse at all.
        $model = str_replace("modelClass('Owner')", "modelClass('User')", $model);
        $model = preg_replace(
            '/(function owner\(\)[^{]*\{\n)/',
            "$1        // The deal's owner, per the porter's note\n",
            $model,
            1
        );
        file_put_contents($modelPath, $model);

        // Staging derives 'Owner', the lived model binds
        // 'User', the Heart declares no target: regen
        // reports the drift (it was silent before), and
        // absorb can now DECLARE the lived binding —
        // writing it into the Heart cures the drift at its
        // source.
        $absorber = new SliceAbsorber;
        $analysis = $absorber->analyse(new SliceManifest($yamlPath));
        $items = $this->itemsByLoss($analysis);
        $drift = $items['relation-target:owner'] ?? null;
        $this->assertNotNull($drift, 'the target drift surfaces as an absorb item');
        $this->assertSame(SliceAbsorber::CLASS_DECLARABLE, $drift['class']);
        $this->assertSame('user', $drift['proposal']['relation']['model']);
        $this->assertSame('owner_id', $drift['proposal']['relation']['foreign_key']);

        $result = $absorber->applyHeart(new SliceManifest($yamlPath), $analysis, $this->root.'/backups');
        $this->assertTrue($result['written']);
        $after = $absorber->analyse(new SliceManifest($yamlPath));
        $this->assertSame([], $after['items'], 'drift cured by the declaration: '.json_encode($after['items']));
    }

    public function test_relation_target_drift_stays_hand_owned_when_the_lived_binding_is_chained(): void
    {
        $sliceDir = $this->generateWidget();
        $modelPath = $sliceDir.'/Models/AbsorbWidget.php';
        $model = (string) file_get_contents($modelPath);
        $this->assertStringContainsString("modelClass('Owner')", $model);
        $model = str_replace(
            "return \$this->belongsTo(\$class, 'owner_id');",
            "return \$this->belongsTo(\$class, 'owner_id')->withDefault();",
            str_replace("modelClass('Owner')", "modelClass('User')", $model)
        );
        file_put_contents($modelPath, $model);

        // A chained lived binding cannot be expressed as a
        // plain declaration: the drift stays visible and
        // hand-owned, never silently transplantable.
        $analysis = (new SliceAbsorber)->analyse(new SliceManifest($sliceDir.'/slice.yaml'));
        $items = $this->itemsByLoss($analysis);
        $drift = $items['relation-target:owner'] ?? null;
        $this->assertNotNull($drift, 'the target drift surfaces as an absorb item');
        $this->assertSame(SliceAbsorber::CLASS_HAND, $drift['class']);
        $this->assertNull($drift['proposal']);
    }

    public function test_generator_emits_declared_relation_key_arguments(): void
    {
        // The absorb key addition: a declared relation may
        // carry the other-side key (local_key / pivot
        // parent_key + related_key), emitted as the call's
        // extra arguments. Without the keys, emission is
        // the historical shape.
        $sliceDir = (new SliceGenerator($this->root, 'App\\Slices'))->generate('KeyWidget', [
            ['name' => 'title', 'type' => 'string', 'nullable' => false],
        ], false, [
            'app' => 'Sandbox', 'domain' => 'Testing',
            'relations' => [
                ['name' => 'members', 'type' => 'hasMany', 'model' => 'Member', 'foreign_key' => 'widget_id', 'local_key' => 'user_id'],
                ['name' => 'plainItems', 'type' => 'hasMany', 'model' => 'PlainItem', 'foreign_key' => 'widget_id'],
                ['name' => 'tags', 'type' => 'belongsToMany', 'model' => 'Tag', 'pivot' => ['table' => 'tag_widget', 'foreign_pivot_key' => 'widget_id', 'related_pivot_key' => 'tag_id', 'parent_key' => 'user_id', 'related_key' => 'id']],
            ],
        ]);
        $model = (string) file_get_contents($sliceDir.'/Models/KeyWidget.php');
        $this->assertStringContainsString("hasMany(\$class, 'widget_id', 'user_id')", $model);
        $this->assertStringContainsString("belongsToMany(\$class, 'tag_widget', 'widget_id', 'tag_id', 'user_id', 'id')", $model);
        // The keyless relation keeps the two-argument call.
        $this->assertMatchesRegularExpression("/function plainItems\\(\\).*?hasMany\\(\\\$class, 'widget_id'\\)/s", $model);
    }
}
