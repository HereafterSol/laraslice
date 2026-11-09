<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;
use LaraSlice\Core\Discovery\ManifestRepository;
use LaraSlice\Core\Discovery\SliceManifest;
use Symfony\Component\Yaml\Yaml;

/**
 * Reverse path for slice knowledge (owner doctrine: porting
 * exists to mature the core; every recurring port challenge
 * becomes a core capability).
 *
 * slice:regen-artefacts re-derives the generated artefacts
 * from the Heart and names what the Heart cannot re-derive
 * (its loss list). Until now that knowledge — hand-added hub
 * relations from the Flagship fix phases, payroll table
 * divergences, fillable drift — had NO way back into the
 * Heart: it lived only in generated PHP, so those models
 * stayed loss-blocked on every regen. This service is the
 * missing reverse path: it takes the regenerator's own loss
 * list (no rival differ — staging and diffing are delegated
 * to ArtefactRegenerator), inspects the LIVE artefacts, and
 * classifies every loss exactly one way:
 *
 *   - declarable:      expressible in today's Heart
 *                      vocabulary (models[].relations,
 *                      fields, models[].table, multitenant).
 *                      Carries a concrete Heart proposal.
 *   - vocabulary-gap:  real, declaration-shaped knowledge
 *                      today's schema cannot express (a
 *                      hasOne, a polymorphic relation,
 *                      chained relation constraints, …).
 *                      Reported precisely, with the
 *                      schema addition that would express
 *                      it. Never written. (Two former gaps
 *                      joined the declarable vocabulary on
 *                      7 Nov: the hasManyThrough relation
 *                      type and the field-level `cast`
 *                      key — see classifyModelMethod and
 *                      classifyCast.)
 *                      (One sanctioned exception was taken
 *                      while building this: relation key
 *                      arguments — local_key/owner_key and
 *                      pivot parent_key/related_key — were
 *                      added to SliceGenerator's declared-
 *                      relation emission, because 22 of the
 *                      26 gaps in the founding census were
 *                      exactly that one missing key family,
 *                      across the flagship hub slices. The
 *                      addition is purely additive: a
 *                      declaration without those keys emits
 *                      byte-identical calls to before.)
 *   - hand-owned:      real code (method bodies, custom
 *                      logic, empty vestigial shells). Never
 *                      proposed as a declaration; stays
 *                      --force-gated on regen by design.
 *
 * Writing is conservative: absorb ONLY ever writes the
 * Heart (slice.yaml), never a generated artefact. The
 * canonical manifest write path (ManifestRepository::
 * writeYaml) re-dumps the whole file and — measured on the
 * ported Hearts — churns hundreds of formatting-only lines
 * on any Heart a human or the porter has touched, which
 * would bury the real change. Absorb therefore splices:
 * semantics come from the framework parser
 * (ManifestRepository), inserted fragments are rendered
 * with the same Symfony Yaml component, untouched regions
 * stay byte-identical, and every write is verified twice
 * (semantic re-read equality against the intended mutation
 * + a removed-lines guard) before it lands. If an anchor
 * cannot be located with certainty, the write is refused
 * for that slice — never guessed.
 *
 * The framework's own core slices (core: true in their
 * manifests — Users, Roles, Settings, Workflows) are
 * hand-built BY DESIGN: classified hand-owned wholesale,
 * never absorbed.
 */
class SliceAbsorber
{
    public const CLASS_DECLARABLE = 'declarable';

    public const CLASS_GAP = 'vocabulary-gap';

    public const CLASS_HAND = 'hand-owned';

    /** @var array<int, string> */
    public const CORE_SLICE_NAMES = ['Users', 'Roles', 'Settings', 'Workflows'];

    private ArtefactRegenerator $regenerator;

    public function __construct(?ArtefactRegenerator $regenerator = null)
    {
        $this->regenerator = $regenerator ?? new ArtefactRegenerator;
    }

    /**
     * Analyse one slice: stage + diff through the
     * regenerator, then classify every reported loss.
     *
     * @return array{slice: string, path: string, excluded: bool, exclusion_reason: ?string, items: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function analyse(SliceManifest $manifest): array
    {
        $exclusion = $this->coreSliceExclusion($manifest);
        if ($exclusion !== null) {
            return [
                'slice' => $manifest->name,
                'path' => $manifest->path,
                'excluded' => true,
                'exclusion_reason' => $exclusion,
                'items' => [[
                    'artefact' => 'slice',
                    'loss' => 'core-slice',
                    'class' => self::CLASS_HAND,
                    'reason' => $exclusion,
                    'proposal' => null,
                ]],
                'counts' => [self::CLASS_DECLARABLE => 0, self::CLASS_GAP => 0, self::CLASS_HAND => 1],
            ];
        }

        $staged = $this->regenerator->stage($manifest);
        try {
            $rows = $this->regenerator->diff($manifest, $staged);
        } finally {
            $this->regenerator->discardStaging($staged['staging']);
        }

        $contents = [];
        foreach (ArtefactRegenerator::ARTEFACTS as $key) {
            $path = $manifest->path.'/'.$this->relativePath($key, $manifest);
            $contents[$key] = file_exists($path) ? (string) file_get_contents($path) : '';
        }

        $items = [];
        $modelItemsByMethod = [];
        foreach ($rows as $row) {
            foreach ($row['lost'] as $loss) {
                $item = match ($row['artefact']) {
                    'model' => $this->classifyModelLoss($manifest, $loss, $contents['model']),
                    'service' => $this->classifyServiceLoss($manifest, $loss, $contents['service'], $contents['model']),
                    default => $this->classifyShowLoss($loss, $modelItemsByMethod),
                };
                $item = ['artefact' => $row['artefact'], 'loss' => $loss] + $item;
                if ($row['artefact'] === 'model' && str_starts_with($loss, 'method:')) {
                    $modelItemsByMethod[substr($loss, 7)] = $item;
                }
                $items[] = $item;
            }
        }
        // Declared-relation audit: the loss list is keyed by
        // method NAME, so it cannot see a declared relation
        // whose declaration derives a DIFFERENT target than
        // the lived method (the generator derives the target
        // as studly(singular(model)) — and the inflector
        // rewrites already-singular names, e.g.
        // singular('...quota') = '...quotum'). Audit every
        // declared relation against the lived method it
        // names; an unfaithful but correctable declaration
        // is a declarable correction, never a duplicate.
        foreach ($this->declaredRelationEntries($manifest) as $declaredName => $entry) {
            $method = Str::camel($declaredName);
            if (isset($modelItemsByMethod[$method])) {
                continue; // already classified from the loss list
            }
            $audit = $this->auditDeclaredRelation($method, $entry, $contents['model']);
            if ($audit !== null) {
                $items[] = ['artefact' => 'model', 'loss' => 'method:'.$method] + $audit;
            }
        }
        // Show relation-calls can only be linked once every
        // model item is known; the match above processes
        // artefacts in ARTEFACTS order (service, model,
        // show), so model items exist by the time show rows
        // are classified.
        $counts = [self::CLASS_DECLARABLE => 0, self::CLASS_GAP => 0, self::CLASS_HAND => 0];
        foreach ($items as $item) {
            $counts[$item['class']]++;
        }

        return [
            'slice' => $manifest->name,
            'path' => $manifest->path,
            'excluded' => false,
            'exclusion_reason' => null,
            'items' => $items,
            'counts' => $counts,
        ];
    }

    /**
     * The unique Heart proposals carried by an analysis'
     * declarable items, keyed for deduplication (a field
     * loss and a validation loss for the same column share
     * one field proposal).
     *
     * @param  array<string, mixed>  $analysis
     * @return array<string, array<string, mixed>>
     */
    public function proposals(array $analysis): array
    {
        $proposals = [];
        foreach ($analysis['items'] as $item) {
            if (($item['proposal'] ?? null) !== null) {
                $proposals[$item['proposal']['key']] = $item['proposal'];
            }
        }

        return $proposals;
    }

    /**
     * Apply an analysis' declarable proposals to the slice's
     * Heart. Only slice.yaml is written; the original is
     * backed up first (timestamped, regen-family discipline).
     *
     * @param  array<string, mixed>  $analysis
     * @return array{written: bool, backupDir: ?string, applied: array<int, string>, refusal: ?string}
     */
    public function applyHeart(SliceManifest $manifest, array $analysis, string $backupRoot): array
    {
        $proposals = $this->proposals($analysis);
        if ($proposals === []) {
            return ['written' => false, 'backupDir' => null, 'applied' => [], 'refusal' => null];
        }
        $yamlPath = $manifest->path.'/slice.yaml';
        if (! file_exists($yamlPath)) {
            return ['written' => false, 'backupDir' => null, 'applied' => [], 'refusal' => 'no slice.yaml at '.$yamlPath];
        }
        $originalText = (string) file_get_contents($yamlPath);
        $parsed = Yaml::parseFile($yamlPath);
        if (! is_array($parsed)) {
            return ['written' => false, 'backupDir' => null, 'applied' => [], 'refusal' => 'slice.yaml did not parse to a map'];
        }

        try {
            $expected = $this->mutatedManifest($parsed, $proposals);
            $spliced = $this->spliceHeartText($originalText, $parsed, $proposals);
        } catch (\Throwable $e) {
            return ['written' => false, 'backupDir' => null, 'applied' => [], 'refusal' => $e->getMessage()];
        }

        // Semantic verification on the SPLICED TEXT, before
        // anything touches disk: parsing it must yield
        // exactly the intended mutation of the parsed Heart.
        try {
            $reparsed = Yaml::parse($spliced['text']);
            if (! is_array($reparsed)
                || ManifestRepository::normalize($reparsed) != ManifestRepository::normalize($expected)) {
                return ['written' => false, 'backupDir' => null, 'applied' => [], 'refusal' => 'spliced Heart does not parse back to the intended mutation — write refused'];
            }
        } catch (\Throwable $e) {
            return ['written' => false, 'backupDir' => null, 'applied' => [], 'refusal' => 'spliced Heart failed to parse: '.$e->getMessage()];
        }

        // Removed-lines guard: the splice may only REMOVE
        // the exact lines it recorded replacing (an inline
        // relations placeholder, a table scalar, …). Any
        // other disappearance means the splice misfired.
        $removedActual = $this->multisetDiff(explode("\n", $originalText), explode("\n", $spliced['text']));
        foreach ($removedActual as $line) {
            if (! in_array($line, $spliced['removedLines'], true)) {
                return ['written' => false, 'backupDir' => null, 'applied' => [], 'refusal' => 'splice removed an unrecorded line ('.trim($line).') — write refused'];
            }
        }

        $backupDir = rtrim($backupRoot, '/').'/'.$manifest->name.'-'.date('YmdHis');
        if (! is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        copy($yamlPath, $backupDir.'/slice.yaml');
        file_put_contents($yamlPath, $spliced['text'], LOCK_EX);

        // Final proof from disk, through the framework's own
        // reader (which round-trip-asserts the file).
        try {
            $after = ManifestRepository::readFromSliceDir($manifest->path);
            if (ManifestRepository::normalize($after) != ManifestRepository::normalize($expected)) {
                copy($backupDir.'/slice.yaml', $yamlPath);

                return ['written' => false, 'backupDir' => $backupDir, 'applied' => [], 'refusal' => 'written Heart failed post-write verification — original restored from backup'];
            }
        } catch (\Throwable $e) {
            copy($backupDir.'/slice.yaml', $yamlPath);

            return ['written' => false, 'backupDir' => $backupDir, 'applied' => [], 'refusal' => 'written Heart failed post-write read: '.$e->getMessage().' — original restored from backup'];
        }

        return [
            'written' => true,
            'backupDir' => $backupDir,
            'applied' => array_keys($proposals),
            'refusal' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Exclusion
    // ------------------------------------------------------------------

    private function coreSliceExclusion(SliceManifest $manifest): ?string
    {
        if (! empty($manifest->raw['core'])) {
            return 'framework core slice (core: true in its manifest) — hand-built by design, never absorbed';
        }
        if (in_array($manifest->name, self::CORE_SLICE_NAMES, true)) {
            $root = function_exists('config') ? config('laraslice.slices_path') : null;
            $realRoot = is_string($root) && $root !== '' ? realpath($root) : false;
            $realPath = realpath($manifest->path);
            if ($realRoot !== false && $realPath !== false && ! str_starts_with($realPath, $realRoot)) {
                return 'framework core slice (lives outside the application slices root) — hand-built by design, never absorbed';
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Classification — model
    // ------------------------------------------------------------------

    /** @return array{class: string, reason: string, proposal: ?array} */
    private function classifyModelLoss(SliceManifest $manifest, string $loss, string $modelSource): array
    {
        [$kind, $name] = array_pad(explode(':', $loss, 2), 2, '');

        return match ($kind) {
            'method' => $this->classifyModelMethod($manifest, $name, $modelSource),
            'fillable' => $this->classifyFillable($manifest, $name, $modelSource),
            'cast' => $this->classifyCast($manifest, $name, $modelSource),
            'relation-target' => $this->classifyRelationTarget($manifest, $name, $modelSource),
            'trait' => $this->classifyTrait($name),
            'table' => [
                'class' => self::CLASS_DECLARABLE,
                'reason' => "the live model points at table '{$name}' while the Heart's root model declares a different one — the Heart is corrected to the lived table",
                'proposal' => ['kind' => 'table', 'key' => 'table', 'table' => $name],
            ],
            default => ['class' => self::CLASS_HAND, 'reason' => "unrecognised model loss '{$loss}' — left hand-owned", 'proposal' => null],
        };
    }

    /** @return array{class: string, reason: string, proposal: ?array} */
    private function classifyModelMethod(SliceManifest $manifest, string $name, string $modelSource): array
    {
        if ($name === 'casts') {
            $casts = $this->castsMethodEntries($modelSource);

            return [
                'class' => self::CLASS_GAP,
                'reason' => 'method-style casts() declares '.($casts === [] ? 'cast entries' : implode(', ', array_map(fn ($k, $v) => "{$k} => {$v}", array_keys($casts), $casts))).' — the ENTRIES are declarable per field (see the cast: items, field-level `cast` key), but the generator emits the $casts property, not a casts() method; the method form itself has no declaration home',
                'proposal' => null,
            ];
        }
        if (preg_match('/^(get|set)[A-Z]\w*Attribute$/', $name) || str_starts_with($name, 'scope') || str_starts_with($name, 'boot')) {
            return ['class' => self::CLASS_HAND, 'reason' => 'accessor/mutator/scope/boot method — real code, not a declaration', 'proposal' => null];
        }
        $body = $this->methodBody($modelSource, $name);
        if ($body === null) {
            return ['class' => self::CLASS_HAND, 'reason' => 'method body could not be located in the live model — cannot inspect, left hand-owned', 'proposal' => null];
        }
        $relation = $this->parseRelationMethod($body);
        if ($relation === null) {
            return ['class' => self::CLASS_HAND, 'reason' => 'method body is not a plain relation return — it contains logic, so it stays hand-owned code', 'proposal' => null];
        }
        if ($relation['target'] === null) {
            return ['class' => self::CLASS_HAND, 'reason' => 'relation target is not statically resolvable (computed at runtime) — cannot be declared faithfully', 'proposal' => null];
        }
        if ($relation['nonliteral']) {
            return ['class' => self::CLASS_HAND, 'reason' => 'relation call passes a computed (non-literal) argument — not a plain declaration', 'proposal' => null];
        }

        // Already declared under this name: audit the
        // declaration against the lived method instead of
        // proposing a duplicate (see auditDeclaredRelation).
        $declaredEntry = $this->findDeclaredRelationEntry($manifest, $name);
        if ($declaredEntry !== null) {
            return $this->auditDeclaredRelation($name, $declaredEntry, $modelSource) ?? [
                'class' => self::CLASS_GAP,
                'reason' => "already declared in the Heart as '{$declaredEntry['type']}' but staging does not reproduce the lived method and the audit offers no faithful correction — the gap is between this declaration's shape and what SliceGenerator emits for it",
                'proposal' => null,
            ];
        }

        $type = $relation['kind'];
        $args = $relation['args']; // literal args AFTER the target

        if ($type === 'hasOne') {
            return [
                'class' => self::CLASS_GAP,
                'reason' => "plain hasOne to {$relation['target']} — SliceGenerator's declared-relation emission supports hasMany/belongsTo/belongsToMany only; proposed addition: 'hasOne' in the supported types with HasOne emission (mirrors belongsTo)",
                'proposal' => null,
            ];
        }
        if ($type === 'hasManyThrough') {
            if ($relation['chained']) {
                return [
                    'class' => self::CLASS_GAP,
                    'reason' => 'relation return carries chained modifiers (where/orderBy/with/…) — the declaration vocabulary cannot express relation constraints; proposed addition: optional \'constraints\'/\'with\' keys on a relation declaration, emitted as the same chain',
                    'proposal' => null,
                ];
            }
            // Through relations are declarable since the
            // vocabulary addition (7 Nov): the declaration
            // names the final model, the through model and
            // the key chain, and SliceGenerator emits the
            // hasManyThrough call in the lived idiom.
            $targetForm = $this->declarationForm((string) $relation['target']);
            if ($targetForm === null) {
                return [
                    'class' => self::CLASS_GAP,
                    'reason' => "plain hasManyThrough to {$relation['target']} via {$relation['through']} — no declaration form survives the generator's model-name derivation for the target, so the Heart cannot name this relation faithfully",
                    'proposal' => null,
                ];
            }
            $built = $this->buildRelationDeclaration($name, $relation, $targetForm);
            if (isset($built['gap'])) {
                return ['class' => self::CLASS_GAP, 'reason' => $built['gap'], 'proposal' => null];
            }

            return [
                'class' => self::CLASS_DECLARABLE,
                'reason' => "plain hasManyThrough to {$relation['target']} via {$relation['through']} with its key chain — declarable as a models[].relations entry (type hasManyThrough)",
                'proposal' => ['kind' => 'relation', 'key' => 'relation:'.$name, 'relation' => $built['decl']],
            ];
        }
        if (str_starts_with($type, 'morph')) {
            return [
                'class' => self::CLASS_GAP,
                'reason' => "polymorphic relation ({$type}) — the declared-relation vocabulary has no morph types; proposed addition: morph type + morph type-column fields in the declaration",
                'proposal' => null,
            ];
        }
        if ($relation['chained']) {
            return [
                'class' => self::CLASS_GAP,
                'reason' => "relation return carries chained modifiers (where/orderBy/with/…) — the declaration vocabulary cannot express relation constraints; proposed addition: optional 'constraints'/'with' keys on a relation declaration, emitted as the same chain",
                'proposal' => null,
            ];
        }

        // The written form of the declaration's model
        // value must survive the generator's target
        // derivation (studly(singular(...))) and yield
        // exactly the lived class basename — ported Hearts
        // write snake handles, and only the plural handle
        // round-trips for inflector-rewritten names
        // (singular('quota') = 'quotum').
        $form = $this->declarationForm((string) $relation['target']);
        if ($form === null) {
            return [
                'class' => self::CLASS_GAP,
                'reason' => "plain {$type} to {$relation['target']} — no declaration form survives the generator's model-name derivation, so the Heart cannot name this target faithfully",
                'proposal' => null,
            ];
        }
        $built = $this->buildRelationDeclaration($name, $relation, $form);
        if (isset($built['gap'])) {
            return ['class' => self::CLASS_GAP, 'reason' => $built['gap'], 'proposal' => null];
        }

        return [
            'class' => self::CLASS_DECLARABLE,
            'reason' => "plain {$type} to {$relation['target']}".($type === 'belongsToMany' ? ' with its pivot declaration' : (isset($args[0]) ? " via {$args[0]}" : '')).' — declarable as a models[].relations entry',
            'proposal' => ['kind' => 'relation', 'key' => 'relation:'.$name, 'relation' => $built['decl']],
        ];
    }

    /**
     * Build the models[].relations entry for a parsed
     * plain relation, with the model value already in its
     * round-tripping declaration form. Returns a 'gap'
     * reason instead when the call's argument count exceeds
     * what the declaration shape can express.
     *
     * @param  array<string, mixed>  $relation  parseRelationMethod() output
     * @return array{decl?: array<string, mixed>, gap?: string}
     */
    private function buildRelationDeclaration(string $name, array $relation, string $modelForm): array
    {
        $type = $relation['kind'];
        $args = $relation['args'];
        $decl = ['name' => $name, 'type' => $type, 'model' => $modelForm];

        if ($type === 'belongsToMany') {
            $pivot = [];
            if (isset($args[0])) {
                $pivot['table'] = $args[0];
            }
            if (isset($args[1])) {
                $pivot['foreign_pivot_key'] = $args[1];
            }
            if (isset($args[2])) {
                $pivot['related_pivot_key'] = $args[2];
            }
            // The pivot may also carry the keys the call
            // is keyed on (parent/related) — declarable
            // since the generator emits them as the call's
            // 5th/6th arguments (absorb key addition, 7 Nov).
            if (isset($args[3])) {
                $pivot['parent_key'] = $args[3];
            }
            if (isset($args[4])) {
                $pivot['related_key'] = $args[4];
            }
            if (count($args) > 5) {
                return ['gap' => 'belongsToMany passes more arguments than the pivot declaration can express (custom pivot class / relation name)'];
            }
            if ($pivot !== []) {
                $decl['pivot'] = $pivot;
            }

            return ['decl' => $decl];
        }

        if ($type === 'hasManyThrough') {
            $throughForm = $relation['through'] !== null ? $this->declarationForm((string) $relation['through']) : null;
            if ($throughForm === null) {
                return ['gap' => 'hasManyThrough through model '.($relation['through'] ?? '?').' cannot be named in a declaration form that survives the generator\'s model-name derivation'];
            }
            $decl['through'] = $throughForm;
            // The key chain after (target, through):
            // first_key on the through table, second_key
            // on the final table, local_key on this model,
            // second_local_key on the through model — the
            // generator emits them as the call's 3rd–6th
            // arguments (vocabulary addition, 7 Nov).
            foreach (['first_key', 'second_key', 'local_key', 'second_local_key'] as $position => $keyName) {
                if (isset($args[$position])) {
                    $decl[$keyName] = $args[$position];
                }
            }
            if (count($args) > 4) {
                return ['gap' => 'hasManyThrough passes more arguments than the (first, second, local, second local) key chain — beyond the declaration\'s shape'];
            }

            return ['decl' => $decl];
        }

        // belongsTo / hasMany
        if (isset($args[0])) {
            $decl['foreign_key'] = $args[0];
        }
        if (isset($args[1])) {
            // The other-side key the call is keyed on
            // (local_key for hasMany, owner_key for
            // belongsTo) — declarable since the generator
            // emits it as the call's third argument
            // (absorb key addition, 7 Nov).
            $decl[$type === 'hasMany' ? 'local_key' : 'owner_key'] = $args[1];
        }
        if (count($args) > 2) {
            return ['gap' => "{$type} passes more arguments than (target, foreign key, key) — beyond the declaration's shape"];
        }

        return ['decl' => $decl];
    }

    /**
     * The written form a relation's `model:` value must
     * take so the generator's derivation
     * (studly(singular(...))) yields exactly the lived
     * class basename — or null when no form round-trips.
     */
    private function declarationForm(string $target): ?string
    {
        foreach ([Str::snake($target), Str::snake(Str::plural($target)), $target, Str::plural($target)] as $candidate) {
            if (Str::studly(Str::singular($candidate)) === $target) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Audit one declared relation against the lived model
     * method it names. Faithful declarations (the derived
     * target, type and foreign key all match the lived
     * method) return null; an unfaithful declaration whose
     * target has a round-tripping form returns a
     * DECLARABLE correction proposal that REPLACES the
     * entry; anything else is a classified gap.
     *
     * @param  array<string, mixed>  $entry
     * @return array{class: string, reason: string, proposal: ?array}|null
     */
    private function auditDeclaredRelation(string $method, array $entry, string $modelSource): ?array
    {
        $body = $this->methodBody($modelSource, $method);
        if ($body === null) {
            return null; // no lived method under that name — nothing to audit against
        }
        $relation = $this->parseRelationMethod($body);
        if ($relation === null || $relation['target'] === null || $relation['nonliteral'] || $relation['chained']) {
            return null; // the lived side is not a plain declaration either
        }
        $type = $relation['kind'];
        if ($type === 'hasOne' || str_starts_with($type, 'morph')) {
            return null; // type-level gap; classified through the loss list when it surfaces
        }
        $target = (string) $relation['target'];
        $derived = Str::studly(Str::singular((string) ($entry['model'] ?? '')));
        $keysMatch = true;
        if ($type === 'belongsTo' || $type === 'hasMany') {
            $keysMatch = (string) ($entry['foreign_key'] ?? '') === (string) ($relation['args'][0] ?? '');
        } elseif ($type === 'hasManyThrough') {
            // Faithful means the declaration derives BOTH
            // class names (final + through) and its key
            // chain equals the lived call's, position for
            // position.
            $derivedThrough = Str::studly(Str::singular((string) ($entry['through'] ?? '')));
            $keysMatch = $derivedThrough === (string) $relation['through']
                && (string) ($entry['first_key'] ?? '') === (string) ($relation['args'][0] ?? '')
                && (string) ($entry['second_key'] ?? '') === (string) ($relation['args'][1] ?? '')
                && (string) ($entry['local_key'] ?? '') === (string) ($relation['args'][2] ?? '')
                && (string) ($entry['second_local_key'] ?? '') === (string) ($relation['args'][3] ?? '');
        }
        if ($derived === $target && ($entry['type'] ?? null) === $type && $keysMatch) {
            return null; // faithful
        }
        $form = $this->declarationForm($target);
        if ($form === null) {
            return [
                'class' => self::CLASS_GAP,
                'reason' => "declared as model '{$entry['model']}' which derives to {$derived}, not the lived {$target} — and no declaration form derives the lived target name",
                'proposal' => null,
            ];
        }
        $built = $this->buildRelationDeclaration($method, $relation, $form);
        if (isset($built['gap'])) {
            return ['class' => self::CLASS_GAP, 'reason' => $built['gap'], 'proposal' => null];
        }

        return [
            'class' => self::CLASS_DECLARABLE,
            'reason' => "the declaration does not reproduce the lived method (it derives target {$derived} where the lived target is {$target}, or its type/key chain differs) — correctable: re-declare with the faithful entry (model '{$form}')",
            'proposal' => ['kind' => 'relation', 'key' => 'relation:'.$method, 'relation' => $built['decl'], 'replace' => (string) $entry['name']],
        ];
    }

    /** @return array{class: string, reason: string, proposal: ?array} */
    private function classifyFillable(SliceManifest $manifest, string $column, string $modelSource): array
    {
        $proposal = $this->fieldProposal($manifest, $column, $modelSource);

        return [
            'class' => self::CLASS_DECLARABLE,
            'reason' => "the live model mass-assigns '{$column}' but the Heart declares no such field — the column is declared as a Heart field (type inferred from the live model's casts / column shape)",
            'proposal' => $proposal,
        ];
    }

    /** @return array{class: string, reason: string, proposal: ?array} */
    private function classifyCast(SliceManifest $manifest, string $column, string $modelSource): array
    {
        $casts = $this->modelCasts($modelSource);
        $cast = $casts[$column] ?? null;
        if ($cast === 'encrypted') {
            return [
                'class' => self::CLASS_DECLARABLE,
                'reason' => "cast '{$column}' => 'encrypted' is expressible today: the field declaration's `encrypted` flag is exactly what the generator turns into this cast",
                'proposal' => ['kind' => 'field-encrypted', 'key' => 'field-encrypted:'.$column, 'column' => $column],
            ];
        }
        if ($cast === null) {
            return [
                'class' => self::CLASS_GAP,
                'reason' => "cast '{$column}' is reported by regen but no literal cast value could be read from the live model (method-style casts() entries are not literal property entries) — cannot be declared faithfully",
                'proposal' => null,
            ];
        }

        return [
            'class' => self::CLASS_DECLARABLE,
            'reason' => "cast '{$column}' => '{$cast}' — declarable since the field-level `cast` key (vocabulary addition, 7 Nov): the field declaration names the cast, the field normalizer carries it, and the generator emits it into \$casts",
            'proposal' => ['kind' => 'field-cast', 'key' => 'field-cast:'.$column, 'column' => $column, 'cast' => $cast],
        ];
    }

    /** @return array{class: string, reason: string, proposal: ?array} */
    private function classifyRelationTarget(SliceManifest $manifest, string $name, string $modelSource): array
    {
        // Regen reports a target drift when staging binds
        // a same-named relation to a different target than
        // the lived model (the blind spot closed in
        // ArtefactRegenerator). A DECLARED relation's
        // drift is the audit's business — delegate, so a
        // correctable declaration is corrected here rather
        // than duplicated or mislabelled.
        $declaredEntry = $this->findDeclaredRelationEntry($manifest, $name);
        if ($declaredEntry !== null) {
            return $this->auditDeclaredRelation($name, $declaredEntry, $modelSource) ?? [
                'class' => self::CLASS_HAND,
                'reason' => "relation '{$name}' is declared in the Heart but staging still binds a different target and the audit offers no faithful correction — left hand-owned and visible rather than silently transplantable",
                'proposal' => null,
            ];
        }

        // Undeclared, but the lived binding itself may be
        // declaration-shaped (the fix-phase hand corrections:
        // Deals'/Tickets' agent() → User, Tickets' channel()
        // → TicketChannel, type() → TicketType — the truth
        // lived only in the PHP, comments and all). Declaring
        // the lived binding in models[].relations is the
        // layer-correct cure: staging then emits the declared
        // relation and the drift is gone at its source.
        $body = $this->methodBody($modelSource, $name);
        $relation = $body !== null ? $this->parseRelationMethod($body) : null;
        if ($relation !== null && $relation['target'] !== null && ! $relation['nonliteral'] && ! $relation['chained']) {
            $form = $this->declarationForm((string) $relation['target']);
            if ($form !== null) {
                $built = $this->buildRelationDeclaration($name, $relation, $form);
                if (! isset($built['gap'])) {
                    return [
                        'class' => self::CLASS_DECLARABLE,
                        'reason' => "relation '{$name}' binds {$relation['target']} in the lived model but staging derives a different target and the Heart declares none — declarable: write the lived binding as a models[].relations entry and staging reproduces it",
                        'proposal' => ['kind' => 'relation', 'key' => 'relation:'.$name, 'relation' => $built['decl']],
                    ];
                }
            }
        }

        return [
            'class' => self::CLASS_HAND,
            'reason' => "relation '{$name}' binds a different target in the lived model than staging derives, and the Heart declares no target for it — the drift is at least REPORTED by regen now (it was silently transplantable before); it stays hand-owned and --force-gated until the lived target is declared",
            'proposal' => null,
        ];
    }

    /** @return array{class: string, reason: string, proposal: ?array} */
    private function classifyTrait(string $trait): array
    {
        return match ($trait) {
            'HasTenant' => [
                'class' => self::CLASS_DECLARABLE,
                'reason' => 'the model carries the tenancy trait but the Heart does not declare multitenant — declaring `multitenant: true` makes staging reproduce the trait (the staging path forwards it since the 7 Nov fix)',
                'proposal' => ['kind' => 'multitenant', 'key' => 'multitenant'],
            ],
            'HasWorkflow' => [
                'class' => self::CLASS_GAP,
                'reason' => 'the model carries HasWorkflow but no workflow: block is declared — a state machine cannot be synthesized from the trait alone; the workflow declaration must be authored, not absorbed',
                'proposal' => null,
            ],
            'SoftDeletes' => [
                'class' => self::CLASS_GAP,
                'reason' => 'the model uses SoftDeletes but the staging path forwards no soft-deletes declaration; proposed addition: a `soft_deletes` Heart key read by FormRegenerator staging and SliceGenerator alike',
                'proposal' => null,
            ],
            default => [
                'class' => self::CLASS_HAND,
                'reason' => "trait {$trait} is a code inclusion with no declaration counterpart in the Heart vocabulary",
                'proposal' => null,
            ],
        };
    }

    // ------------------------------------------------------------------
    // Classification — service
    // ------------------------------------------------------------------

    /** @return array{class: string, reason: string, proposal: ?array} */
    private function classifyServiceLoss(SliceManifest $manifest, string $loss, string $serviceSource, string $modelSource): array
    {
        [$kind, $name] = array_pad(explode(':', $loss, 2), 2, '');

        if ($kind === 'method' && in_array($name, ['repeaterDefinitions', 'relationMultiDefinitions'], true)) {
            $body = $this->methodBody($serviceSource, $name);
            if ($body !== null && $this->returnsEmptyArray($body)) {
                return [
                    'class' => self::CLASS_HAND,
                    'reason' => "empty {$name}() shell — the definitions were cured out of the Heart in the D2 pass and the empty method remained as residue; no knowledge is left to declare, and the shell stays hand-owned",
                    'proposal' => null,
                ];
            }

            return [
                'class' => self::CLASS_GAP,
                'reason' => "the live service returns {$name}() definitions that the Heart's ui declarations do not produce — reverse-mapping service definitions into ui repeater/relation-multi declarations is not a path the vocabulary offers today",
                'proposal' => null,
            ];
        }
        if ($kind === 'method') {
            return ['class' => self::CLASS_HAND, 'reason' => "service method {$name}() is business logic (code), not a declaration — it stays hand-owned", 'proposal' => null];
        }
        if ($kind === 'validation') {
            $fields = $manifest->raw['fields'] ?? [];
            $declared = is_array($fields) && (isset($fields[$name]) || collect(is_array($fields) ? $fields : [])->contains(fn ($f) => is_array($f) && ($f['name'] ?? null) === $name));
            if (! $declared) {
                return [
                    'class' => self::CLASS_DECLARABLE,
                    'reason' => "the live service validates '{$name}' but the Heart declares no such field — declaring the field restores the generated validation with it",
                    'proposal' => $this->fieldProposal($manifest, $name, $modelSource),
                ];
            }

            return [
                'class' => self::CLASS_GAP,
                'reason' => "field '{$name}' is declared but its live validation rule is not the generated one — custom rules have no per-field override the staging path honours",
                'proposal' => null,
            ];
        }
        if ($kind === 'repeater' || $kind === 'relation-multi') {
            return [
                'class' => self::CLASS_GAP,
                'reason' => "the live service carries a {$kind} definition '{$name}' that the Heart's ui declarations do not produce — its declaration home (ui form sections) cannot be reconstructed from the baked definition alone",
                'proposal' => null,
            ];
        }

        return ['class' => self::CLASS_HAND, 'reason' => "unrecognised service loss '{$loss}' — left hand-owned", 'proposal' => null];
    }

    // ------------------------------------------------------------------
    // Classification — show
    // ------------------------------------------------------------------

    /**
     * @param  array<string, array<string, mixed>>  $modelItemsByMethod
     * @return array{class: string, reason: string, proposal: ?array}
     */
    private function classifyShowLoss(string $loss, array $modelItemsByMethod): array
    {
        [$kind, $name] = array_pad(explode(':', $loss, 2), 2, '');
        if ($kind === 'relation-call' && isset($modelItemsByMethod[$name])) {
            $linked = $modelItemsByMethod[$name];

            return [
                'class' => $linked['class'],
                'reason' => "the show blade calls \$record->{$name}() — it follows the model method's classification: ".$linked['reason'],
                'proposal' => $linked['proposal'],
            ];
        }

        return [
            'class' => self::CLASS_HAND,
            'reason' => "show-blade surface token '{$loss}' — blade routes/includes/calls are view surface, not model declarations; absorb covers the declaration layer only",
            'proposal' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Proposals
    // ------------------------------------------------------------------

    /** @return array{kind: string, key: string, field: array<string, mixed>} */
    private function fieldProposal(SliceManifest $manifest, string $column, string $modelSource): array
    {
        $casts = $this->modelCasts($modelSource);
        $field = $this->fieldDefFor($column, $casts[$column] ?? null, $manifest->tenant_key ?? 'company_id');
        if (isset($casts[$column]) && $casts[$column] !== 'encrypted') {
            // The new field declaration names the column's
            // cast as well, so a single absorb pass
            // converges — no cast: loss is left standing
            // behind the field proposal.
            $field['cast'] = $casts[$column];
        }

        return ['kind' => 'field', 'key' => 'field:'.$column, 'field' => $field];
    }

    /** @return array<string, mixed> */
    private function fieldDefFor(string $column, ?string $cast, string $tenantKey): array
    {
        $type = match (true) {
            in_array($cast, ['array', 'json', 'collection'], true) => 'json',
            in_array($cast, ['boolean', 'bool'], true) => 'boolean',
            in_array($cast, ['integer', 'int'], true) => 'integer',
            in_array($cast, ['datetime', 'immutable_datetime', 'custom_datetime'], true) => 'datetime',
            $cast === 'date' || $cast === 'immutable_date' => 'date',
            is_string($cast) && (str_starts_with($cast, 'decimal') || in_array($cast, ['float', 'double', 'real'], true)) => 'decimal',
            str_ends_with($column, '_id') => 'integer',
            $column === $tenantKey => 'integer',
            default => 'string',
        };
        $field = [
            'name' => $column,
            'label' => Str::headline($column),
            'type' => $type,
            'nullable' => true,
            'default' => null,
            'options' => [],
            'hidden' => false,
            'show_in_form' => true,
            'show_in_list' => true,
        ];
        if ($column === $tenantKey) {
            // The tenant key is stamped by the HasTenant
            // trait, never typed into a form: declare it
            // hidden, exactly as it behaves.
            $field['hidden'] = true;
            $field['show_in_form'] = false;
            $field['show_in_list'] = false;
        }

        return $field;
    }

    // ------------------------------------------------------------------
    // Live-artefact parsing
    // ------------------------------------------------------------------

    /**
     * Extract a method's full body (the braces' contents)
     * from PHP source, or null when it cannot be located.
     */
    private function methodBody(string $php, string $method): ?string
    {
        $pos = strpos($php, 'function '.$method.'(');
        if ($pos === false) {
            return null;
        }
        $open = strpos($php, '{', $pos);
        if ($open === false) {
            return null;
        }
        $depth = 0;
        $length = strlen($php);
        $inString = null;
        for ($i = $open; $i < $length; $i++) {
            $ch = $php[$i];
            $next = $i + 1 < $length ? $php[$i + 1] : '';
            if ($inString !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $inString) {
                    $inString = null;
                }

                continue;
            }
            // Comments are skipped wholesale: an apostrophe
            // in a prose comment ("the deal's agent") is not
            // a string opener — without this, comment-bearing
            // hand methods scan as unbalanced and vanish
            // from classification (found on Deals' agent(),
            // whose target then looked unparseable while
            // regen's comment-aware scan saw it plainly).
            if ($ch === '/' && ($next === '/' || $next === '*')) {
                if ($next === '/') {
                    $nl = strpos($php, "\n", $i);
                    $i = $nl === false ? $length : $nl;
                } else {
                    $close = strpos($php, '*/', $i + 2);
                    $i = $close === false ? $length : $close + 1;
                }

                continue;
            }
            if ($ch === '#') {
                $nl = strpos($php, "\n", $i);
                $i = $nl === false ? $length : $nl;

                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = $ch;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($php, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }

    /**
     * Parse a method body as a PLAIN relation return:
     * optional modelClass()/::class assignment boilerplate,
     * then exactly one `return $this->kind(args);`.
     *
     * @return ?array{kind: string, target: ?string, through: ?string, args: array<int, string>, nonliteral: bool, chained: bool}
     */
    private function parseRelationMethod(string $body): ?array
    {
        $code = $this->stripComments($body);

        // Variable => target model, from the resolution
        // boilerplate the generator/porter idiom uses.
        $varTargets = [];
        if (preg_match_all('/\$(\w+)\s*=\s*app\([^;]*?->modelClass\(\s*\'([^\']+)\'\s*\)[^;]*;/s', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $varTargets[$row[1]] = $row[2];
            }
        }
        if (preg_match_all('/\$(\w+)\s*=\s*([A-Za-z_\\\\][\w\\\\]*)::class\s*;/', $code, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $varTargets[$row[1]] = $this->basename($row[2]);
            }
        }
        // Remove those assignment statements; what remains
        // must be the single return.
        $code = preg_replace('/\$\w+\s*=\s*app\([^;]*?->modelClass\(\s*\'[^\']+\'\s*\)[^;]*;/s', '', $code) ?? $code;
        $code = preg_replace('/\$\w+\s*=\s*[A-Za-z_\\\\][\w\\\\]*::class\s*;/', '', $code) ?? $code;
        $code = trim($code);

        if (! preg_match('/^return\s+\$this->(belongsTo|hasMany|hasOne|belongsToMany|hasManyThrough|hasOneThrough|morphTo|morphOne|morphMany|morphToMany|morphedByMany)\s*\(/', $code, $km)) {
            return null;
        }
        $kind = $km[1];
        $open = strpos($code, '(', strlen($km[0]) - 1);
        $close = $this->matchingParen($code, $open);
        if ($close === null) {
            return null;
        }
        $argsSource = substr($code, $open + 1, $close - $open - 1);
        $rest = trim(substr($code, $close + 1));
        $chained = false;
        if ($rest === ';') {
            // plain
        } elseif (str_starts_with($rest, '->')) {
            $chained = true;
            if (! str_ends_with(rtrim($rest), ';')) {
                return null;
            }
        } else {
            return null; // something else follows the call
        }

        $rawArgs = $this->splitArgs($argsSource);
        if ($rawArgs === [] || $rawArgs === ['']) {
            return null;
        }
        $target = null;
        $through = null;
        $first = trim($rawArgs[0]);
        if (preg_match('/^\$(\w+)$/', $first, $vm)) {
            $target = $varTargets[$vm[1]] ?? null;
        } elseif (preg_match('/^([A-Za-z_\\\\][\w\\\\]*)::class$/', $first, $cm)) {
            $target = $this->basename($cm[1]);
        } elseif (($lit = $this->literalString($first)) !== null) {
            $target = $this->basename($lit);
        }
        if ($kind === 'hasManyThrough' && isset($rawArgs[1])) {
            $second = trim($rawArgs[1]);
            if (preg_match('/^\$(\w+)$/', $second, $vm)) {
                $through = $varTargets[$vm[1]] ?? null;
            } elseif (preg_match('/^([A-Za-z_\\\\][\w\\\\]*)::class$/', $second, $cm)) {
                $through = $this->basename($cm[1]);
            } elseif (($lit = $this->literalString($second)) !== null) {
                $through = $this->basename($lit);
            }
        }

        $args = [];
        $nonliteral = false;
        foreach (array_slice($rawArgs, $kind === 'hasManyThrough' ? 2 : 1) as $raw) {
            $lit = $this->literalString(trim($raw));
            if ($lit === null) {
                $nonliteral = true;
                $args[] = trim($raw);
            } else {
                $args[] = $lit;
            }
        }

        return [
            'kind' => $kind,
            'target' => $target,
            'through' => $through,
            'args' => $args,
            'nonliteral' => $nonliteral,
            'chained' => $chained,
        ];
    }

    private function returnsEmptyArray(string $body): bool
    {
        $code = trim($this->stripComments($body));

        return (bool) preg_match('/^return\s+(?:array\s*\(\s*\)|\[\s*\])\s*;$/', $code);
    }

    private function stripComments(string $code): string
    {
        $code = preg_replace('/\/\*.*?\*\//s', '', $code) ?? $code;
        $code = preg_replace('/(^|\s)\/\/[^\n]*/', '$1', $code) ?? $code;

        return $code;
    }

    private function matchingParen(string $code, int $open): ?int
    {
        $depth = 0;
        $length = strlen($code);
        $inString = null;
        for ($i = $open; $i < $length; $i++) {
            $ch = $code[$i];
            if ($inString !== null) {
                if ($ch === '\\') {
                    $i++;
                } elseif ($ch === $inString) {
                    $inString = null;
                }

                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = $ch;
            } elseif ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function splitArgs(string $source): array
    {
        $args = [];
        $depth = 0;
        $inString = null;
        $current = '';
        $length = strlen($source);
        for ($i = 0; $i < $length; $i++) {
            $ch = $source[$i];
            if ($inString !== null) {
                $current .= $ch;
                if ($ch === '\\') {
                    $current .= $source[$i + 1] ?? '';
                    $i++;
                } elseif ($ch === $inString) {
                    $inString = null;
                }

                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = $ch;
                $current .= $ch;
            } elseif ($ch === '(' || $ch === '[' || $ch === '{') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')' || $ch === ']' || $ch === '}') {
                $depth--;
                $current .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $args[] = $current;
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if (trim($current) !== '') {
            $args[] = $current;
        }

        return $args;
    }

    private function literalString(string $arg): ?string
    {
        if (preg_match("/^'((?:[^'\\\\]|\\\\.)*)'$/", $arg, $m)) {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $m[1]);
        }
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $arg, $m)) {
            return stripcslashes($m[1]);
        }

        return null;
    }

    private function basename(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /** @return array<string, string> column => cast, from the $casts property and a casts() method */
    private function modelCasts(string $php): array
    {
        $casts = [];
        if (preg_match('/\$casts\s*=\s*(?:array\s*\(|\[)(.*?)(?:\)|\])\s*;/s', $php, $m)
            && preg_match_all("/'([^']+)'\s*=>\s*'([^']+)'/", $m[1], $mm, PREG_SET_ORDER)) {
            foreach ($mm as $row) {
                $casts[$row[1]] = $row[2];
            }
        }
        foreach ($this->castsMethodEntries($php) as $k => $v) {
            $casts[$k] = $v;
        }

        return $casts;
    }

    /** @return array<string, string> */
    private function castsMethodEntries(string $php): array
    {
        $body = $this->methodBody($php, 'casts');
        if ($body === null || ! preg_match_all("/'([^']+)'\s*=>\s*'([^']+)'/", $body, $mm, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($mm as $row) {
            $out[$row[1]] = $row[2];
        }

        return $out;
    }

    /** @return array<string, string> declared relation name => type, across models[].relations and top-level relations */
    /**
     * Every relation declared for the root model (plus
     * any top-level `relations:` entries), keyed by
     * declared name with the full entry.
     *
     * @return array<string, array<string, mixed>>
     */
    private function declaredRelationEntries(SliceManifest $manifest): array
    {
        $raw = $manifest->raw;
        $out = [];
        $root = null;
        foreach ((array) ($raw['models'] ?? []) as $model) {
            if (is_array($model) && ! empty($model['root'])) {
                $root = $model;
                break;
            }
        }
        if ($root === null && is_array($raw['models'][0] ?? null)) {
            $root = $raw['models'][0];
        }
        foreach ((array) ($root['relations'] ?? []) as $relation) {
            if (is_array($relation) && ! empty($relation['name'])) {
                $out[(string) $relation['name']] = $relation;
            }
        }
        foreach ((array) ($raw['relations'] ?? []) as $relation) {
            if (is_array($relation) && ! empty($relation['name']) && ! isset($out[(string) $relation['name']])) {
                $out[(string) $relation['name']] = $relation;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function findDeclaredRelationEntry(SliceManifest $manifest, string $methodName): ?array
    {
        foreach ($this->declaredRelationEntries($manifest) as $declaredName => $entry) {
            if (Str::camel($declaredName) === $methodName) {
                return $entry;
            }
        }

        return null;
    }

    private function relativePath(string $key, SliceManifest $manifest): string
    {
        $studly = SliceName::canonical($manifest->name);

        return match ($key) {
            'service' => "Services/{$studly}SliceService.php",
            'model' => "Models/{$studly}.php",
            'show' => 'Resources/views/show.blade.php',
        };
    }

    /** @return array<int, string> lines in $a (multiset) not in $b */
    private function multisetDiff(array $a, array $b): array
    {
        $counts = array_count_values($b);
        $out = [];
        foreach ($a as $line) {
            if (($counts[$line] ?? 0) > 0) {
                $counts[$line]--;
            } else {
                $out[] = $line;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Heart mutation (semantic target)
    // ------------------------------------------------------------------

    /**
     * The parsed Heart as it must read after the proposals
     * are applied. The splice below is verified against
     * this array; the two must agree exactly.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, array<string, mixed>>  $proposals
     * @return array<string, mixed>
     */
    private function mutatedManifest(array $parsed, array $proposals): array
    {
        $mut = $parsed;
        $rootIdx = $this->rootModelIndex($mut);

        foreach ($proposals as $proposal) {
            switch ($proposal['kind']) {
                case 'relation':
                    if ($rootIdx === null) {
                        throw new \RuntimeException('Heart has no models[] root entry to hold the absorbed relation');
                    }
                    $relations = $mut['models'][$rootIdx]['relations'] ?? [];
                    if (! is_array($relations)) {
                        $relations = [];
                    }
                    $exists = false;
                    foreach ($relations as $i => $existing) {
                        if (is_array($existing) && ($existing['name'] ?? null) === $proposal['relation']['name']) {
                            $exists = true;
                            if (! empty($proposal['replace'])) {
                                // Correction: substitute the
                                // faithful declaration in place.
                                $relations[$i] = $proposal['relation'];
                                $mut['models'][$rootIdx]['relations'] = $relations;
                            }
                            break;
                        }
                    }
                    if (! $exists) {
                        $relations[] = $proposal['relation'];
                        $mut['models'][$rootIdx]['relations'] = $relations;
                    }
                    break;
                case 'field':
                    $fields = ManifestRepository::fieldMap(is_array($mut['fields'] ?? null) ? $mut['fields'] : []);
                    $fields[$proposal['field']['name']] = $proposal['field'];
                    $mut['fields'] = $fields;
                    break;
                case 'field-encrypted':
                    $fields = ManifestRepository::fieldMap(is_array($mut['fields'] ?? null) ? $mut['fields'] : []);
                    $column = $proposal['column'];
                    if (isset($fields[$column])) {
                        $fields[$column]['encrypted'] = true;
                    } else {
                        $def = $this->fieldDefFor($column, null, (string) ($mut['tenant_key'] ?? 'company_id'));
                        $def['encrypted'] = true;
                        $fields[$column] = $def;
                    }
                    $mut['fields'] = $fields;
                    break;
                case 'field-cast':
                    $fields = ManifestRepository::fieldMap(is_array($mut['fields'] ?? null) ? $mut['fields'] : []);
                    $column = $proposal['column'];
                    if (isset($fields[$column])) {
                        $fields[$column]['cast'] = $proposal['cast'];
                    } else {
                        // Cast-only column (SalarySlips'
                        // JSON payloads): the field is
                        // declared HIDDEN — the cast is the
                        // knowledge being absorbed; the
                        // column stays out of forms and
                        // lists, exactly as the live
                        // artefacts keep it.
                        $def = $this->fieldDefFor($column, $proposal['cast'], (string) ($mut['tenant_key'] ?? 'company_id'));
                        $def['cast'] = $proposal['cast'];
                        $def['hidden'] = true;
                        $def['show_in_form'] = false;
                        $def['show_in_list'] = false;
                        $fields[$column] = $def;
                    }
                    $mut['fields'] = $fields;
                    break;
                case 'table':
                    if ($rootIdx === null) {
                        throw new \RuntimeException('Heart has no models[] root entry to hold the absorbed table');
                    }
                    $mut['models'][$rootIdx]['table'] = $proposal['table'];
                    if (isset($mut['tables']) && is_array($mut['tables']) && $mut['tables'] !== []) {
                        $mut['tables'][0] = $proposal['table'];
                    }
                    break;
                case 'multitenant':
                    $mut['multitenant'] = true;
                    break;
            }
        }

        return $mut;
    }

    /** @param  array<string, mixed>  $manifest */
    private function rootModelIndex(array $manifest): ?int
    {
        foreach ((array) ($manifest['models'] ?? []) as $i => $model) {
            if (is_array($model) && ! empty($model['root'])) {
                return (int) $i;
            }
        }

        return isset($manifest['models'][0]) && is_array($manifest['models'][0]) ? 0 : null;
    }

    // ------------------------------------------------------------------
    // Heart splice (text level)
    // ------------------------------------------------------------------

    /**
     * Splice the proposals into the Heart's text. Returns
     * the new text plus the exact lines that were removed
     * (each a line the splice intentionally replaced).
     * Throws when an anchor cannot be located with
     * certainty — the caller turns that into a refusal.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, array<string, mixed>>  $proposals
     * @return array{text: string, removedLines: array<int, string>}
     */
    private function spliceHeartText(string $text, array $parsed, array $proposals): array
    {
        $lines = explode("\n", $text);
        $removed = [];
        $byKind = ['relation' => [], 'field' => [], 'field-encrypted' => [], 'field-cast' => [], 'table' => [], 'multitenant' => []];
        foreach ($proposals as $proposal) {
            $byKind[$proposal['kind']][] = $proposal;
        }

        if ($byKind['table'] !== []) {
            $lines = $this->spliceTable($lines, $parsed, $byKind['table'][0]['table'], $removed);
        }
        if ($byKind['relation'] !== []) {
            $appends = [];
            $replaces = [];
            foreach ($byKind['relation'] as $p) {
                if (! empty($p['replace'])) {
                    $replaces[] = $p['relation'];
                } else {
                    $appends[] = $p['relation'];
                }
            }
            $lines = $this->spliceRelations($lines, $parsed, $appends, $removed, $replaces);
        }
        foreach ($byKind['field'] as $proposal) {
            $lines = $this->spliceFieldEntry($lines, $proposal['field'], $removed);
        }
        foreach ($byKind['field-encrypted'] as $proposal) {
            $lines = $this->spliceFieldEncrypted($lines, $parsed, $proposal['column'], $removed);
        }
        foreach ($byKind['field-cast'] as $proposal) {
            $lines = $this->spliceFieldCast($lines, $parsed, $proposal['column'], $proposal['cast'], $removed);
        }
        if ($byKind['multitenant'] !== []) {
            $lines = $this->spliceMultitenant($lines, $removed);
        }

        return ['text' => implode("\n", $lines), 'removedLines' => $removed];
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $parsed
     * @param  array<int, string>  $removed
     * @return array<int, string>
     */
    private function spliceTable(array $lines, array $parsed, string $table, array &$removed): array
    {
        // Explicit top-level tables: list, when the file
        // carries one (staging reads tables[0] first).
        $tablesIdx = $this->findTopLevelKey($lines, 'tables');
        if ($tablesIdx !== null) {
            $line = $lines[$tablesIdx];
            if (preg_match('/^tables:\s*\[(.*)\]\s*$/', $line, $m)) {
                $items = array_map('trim', explode(',', $m[1]));
                $items[0] = $this->yamlScalar($table);
                $removed[] = $line;
                $lines[$tablesIdx] = 'tables: ['.implode(', ', $items).']';
            } else {
                $end = $this->blockEnd($lines, $tablesIdx, 0);
                for ($i = $tablesIdx + 1; $i < $end; $i++) {
                    if (preg_match('/^(\s*-\s+)(\S.*?)\s*$/', $lines[$i], $m)) {
                        $removed[] = $lines[$i];
                        $lines[$i] = $m[1].$this->yamlScalar($table);
                        break;
                    }
                }
            }
        }

        // The root model entry's own table: line.
        $span = $this->rootEntrySpan($lines, $parsed);
        [$start, $end, $keyIndent] = $span;
        for ($i = $start + 1; $i < $end; $i++) {
            if ($this->indentOf($lines[$i]) === $keyIndent && preg_match('/^(\s*table:\s*)(\S.*?)\s*$/', $lines[$i], $m)) {
                $removed[] = $lines[$i];
                $lines[$i] = $m[1].$this->yamlScalar($table);

                return $lines;
            }
        }

        throw new \RuntimeException('could not locate the root model table: line to correct');
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $parsed
     * @param  array<int, array<string, mixed>>  $relations
     * @param  array<int, string>  $removed
     * @return array<int, string>
     */
    private function spliceRelations(array $lines, array $parsed, array $relations, array &$removed, array $replaces = []): array
    {
        [$start, $end, $keyIndent] = $this->rootEntrySpan($lines, $parsed);
        $pad = str_repeat(' ', $keyIndent);

        $relationsIdx = null;
        for ($i = $start + 1; $i < $end; $i++) {
            if ($this->indentOf($lines[$i]) === $keyIndent && preg_match('/^\s*relations:/', $lines[$i])) {
                $relationsIdx = $i;
                break;
            }
        }

        if ($replaces !== [] && $relationsIdx !== null) {
            // Corrections first: swap the named items in
            // place, then re-locate (line counts may shift).
            $lines = $this->replaceRelationItems($lines, $relationsIdx, $keyIndent, $replaces, $removed);
            if ($relations === []) {
                return $lines;
            }
            [$start, $end, $keyIndent] = $this->rootEntrySpan($lines, $parsed);
            $pad = str_repeat(' ', $keyIndent);
            $relationsIdx = null;
            for ($i = $start + 1; $i < $end; $i++) {
                if ($this->indentOf($lines[$i]) === $keyIndent && preg_match('/^\s*relations:/', $lines[$i])) {
                    $relationsIdx = $i;
                    break;
                }
            }
        }

        if ($relationsIdx !== null
            && preg_match('/^\s*relations:\s*(\{\s*\}|\[\s*\]|null|~)?\s*$/', $lines[$relationsIdx])
            && $this->blockEnd($lines, $relationsIdx, $keyIndent) === $relationsIdx + 1) {
            // Single-line empty value ({  }, [], null, ~,
            // bare) -> block with the new entries.
            $removed[] = $lines[$relationsIdx];
            $block = [$pad.'relations:'];
            foreach ($relations as $relation) {
                array_push($block, ...$this->renderRelation($relation, $keyIndent));
            }
            array_splice($lines, $relationsIdx, 1, $block);

            return $lines;
        }

        if ($relationsIdx !== null) {
            // Existing block list: append at its end, in
            // the list's own dash style.
            $blockEnd = $this->blockEnd($lines, $relationsIdx, $keyIndent);
            $dashIndent = $keyIndent;
            for ($i = $relationsIdx + 1; $i < $blockEnd; $i++) {
                if (preg_match('/^(\s*)-(\s|$)/', $lines[$i], $m)) {
                    $dashIndent = strlen($m[1]);
                    break;
                }
            }
            $block = [];
            foreach ($relations as $relation) {
                array_push($block, ...$this->renderRelation($relation, $dashIndent));
            }
            array_splice($lines, $blockEnd, 0, $block);

            return $lines;
        }

        // No relations: key yet — insert one after the root
        // entry's fields: block when present, else after
        // its table:/root: line, else right after the
        // entry's opening line.
        $insertAt = $start + 1;
        for ($i = $start + 1; $i < $end; $i++) {
            if ($this->indentOf($lines[$i]) === $keyIndent && preg_match('/^\s*fields:/', $lines[$i])) {
                $insertAt = $this->blockEnd($lines, $i, $keyIndent);
                break;
            }
            if ($this->indentOf($lines[$i]) === $keyIndent && preg_match('/^\s*(table|root):/', $lines[$i])) {
                $insertAt = $i + 1;
            }
        }
        $block = [$pad.'relations:'];
        foreach ($relations as $relation) {
            array_push($block, ...$this->renderRelation($relation, $keyIndent));
        }
        array_splice($lines, $insertAt, 0, $block);

        return $lines;
    }

    /**
     * Swap already-declared relation items (matched by
     * their `name:`) inside an existing relations block
     * for corrected declarations, in the block's own dash
     * style. Items are replaced last-to-first so earlier
     * indices stay valid while line counts shift.
     *
     * @param  array<int, string>  $lines
     * @param  array<int, array<string, mixed>>  $replaces
     * @param  array<int, string>  $removed
     * @return array<int, string>
     */
    private function replaceRelationItems(array $lines, int $relationsIdx, int $keyIndent, array $replaces, array &$removed): array
    {
        $blockEnd = $this->blockEnd($lines, $relationsIdx, $keyIndent);
        $dashIndent = null;
        $starts = [];
        for ($i = $relationsIdx + 1; $i < $blockEnd; $i++) {
            if (preg_match('/^(\s*)-(\s|$)/', $lines[$i], $m)) {
                $dashIndent = strlen($m[1]);
                $starts[] = $i;
            }
        }
        if ($dashIndent === null) {
            return $lines;
        }
        $starts[] = $blockEnd;
        for ($s = count($starts) - 2; $s >= 0; $s--) {
            $from = $starts[$s];
            $to = $starts[$s + 1];
            $itemName = null;
            for ($i = $from; $i < min($to, $from + 3); $i++) {
                if (preg_match('/^\s*-?\s*name:\s*([^\s]+)\s*$/', $lines[$i], $m)) {
                    $itemName = trim($m[1], "'\"");
                    break;
                }
            }
            if ($itemName === null) {
                continue;
            }
            foreach ($replaces as $relation) {
                if ((string) $relation['name'] === $itemName) {
                    array_push($removed, ...array_slice($lines, $from, $to - $from));
                    array_splice($lines, $from, $to - $from, $this->renderRelation($relation, $dashIndent));
                    break;
                }
            }
        }

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  $removed
     * @return array<int, string>
     */
    private function spliceFieldEntry(array $lines, array $field, array &$removed): array
    {
        $fieldsIdx = $this->findTopLevelKey($lines, 'fields');
        if ($fieldsIdx === null) {
            // Create the fields: map just before models:.
            $modelsIdx = $this->findTopLevelKey($lines, 'models');
            if ($modelsIdx === null) {
                throw new \RuntimeException('Heart has no fields: or models: anchor for the absorbed field');
            }
            $block = ['fields:'];
            array_push($block, ...$this->renderFieldEntry($field));
            array_splice($lines, $modelsIdx, 0, $block);

            return $lines;
        }
        if (preg_match('/^fields:\s*(\{\s*\}|\[\s*\]|null|~)?\s*$/', $lines[$fieldsIdx])
            && $this->blockEnd($lines, $fieldsIdx, 0) === $fieldsIdx + 1) {
            $removed[] = $lines[$fieldsIdx];
            $block = ['fields:'];
            array_push($block, ...$this->renderFieldEntry($field));
            array_splice($lines, $fieldsIdx, 1, $block);

            return $lines;
        }
        $end = $this->blockEnd($lines, $fieldsIdx, 0);
        array_splice($lines, $end, 0, $this->renderFieldEntry($field));

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $parsed
     * @param  array<int, string>  $removed
     * @return array<int, string>
     */
    private function spliceFieldEncrypted(array $lines, array $parsed, string $column, array &$removed): array
    {
        $fields = ManifestRepository::fieldMap(is_array($parsed['fields'] ?? null) ? $parsed['fields'] : []);
        if (! isset($fields[$column])) {
            // No entry yet: append the full field, flagged.
            $def = $this->fieldDefFor($column, null, (string) ($parsed['tenant_key'] ?? 'company_id'));
            $def['encrypted'] = true;

            return $this->spliceFieldEntry($lines, $def, $removed);
        }
        $fieldsIdx = $this->findTopLevelKey($lines, 'fields');
        if ($fieldsIdx === null) {
            throw new \RuntimeException('could not locate the fields: block for the encrypted flag');
        }
        $end = $this->blockEnd($lines, $fieldsIdx, 0);
        $entryIdx = null;
        for ($i = $fieldsIdx + 1; $i < $end; $i++) {
            if ($this->indentOf($lines[$i]) === 2 && preg_match('/^\s*'.preg_quote($column, '/').':\s*$/', $lines[$i])) {
                $entryIdx = $i;
                break;
            }
        }
        if ($entryIdx === null) {
            throw new \RuntimeException("could not locate the fields entry for {$column}");
        }
        $entryEnd = $end;
        for ($i = $entryIdx + 1; $i < $end; $i++) {
            if (trim($lines[$i]) !== '' && $this->indentOf($lines[$i]) <= 2) {
                $entryEnd = $i;
                break;
            }
            $entryEnd = $i + 1;
        }
        for ($i = $entryIdx + 1; $i < $entryEnd; $i++) {
            if (preg_match('/^(\s*encrypted:\s*)\S+/', $lines[$i], $m)) {
                $removed[] = $lines[$i];
                $lines[$i] = $m[1].'true';

                return $lines;
            }
        }
        array_splice($lines, $entryEnd, 0, ['    encrypted: true']);

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $parsed
     * @param  array<int, string>  $removed
     * @return array<int, string>
     */
    private function spliceFieldCast(array $lines, array $parsed, string $column, string $cast, array &$removed): array
    {
        $fields = ManifestRepository::fieldMap(is_array($parsed['fields'] ?? null) ? $parsed['fields'] : []);
        if (! isset($fields[$column])) {
            // No entry yet: append the full field, hidden,
            // carrying the cast (mirrors the field-cast
            // case in mutatedManifest).
            $def = $this->fieldDefFor($column, $cast, (string) ($parsed['tenant_key'] ?? 'company_id'));
            $def['cast'] = $cast;
            $def['hidden'] = true;
            $def['show_in_form'] = false;
            $def['show_in_list'] = false;

            return $this->spliceFieldEntry($lines, $def, $removed);
        }
        $fieldsIdx = $this->findTopLevelKey($lines, 'fields');
        if ($fieldsIdx === null) {
            throw new \RuntimeException('could not locate the fields: block for the cast key');
        }
        $end = $this->blockEnd($lines, $fieldsIdx, 0);
        $entryIdx = null;
        for ($i = $fieldsIdx + 1; $i < $end; $i++) {
            if ($this->indentOf($lines[$i]) === 2 && preg_match('/^\s*'.preg_quote($column, '/').':\s*$/', $lines[$i])) {
                $entryIdx = $i;
                break;
            }
        }
        if ($entryIdx === null) {
            throw new \RuntimeException("could not locate the fields entry for {$column}");
        }
        $entryEnd = $end;
        for ($i = $entryIdx + 1; $i < $end; $i++) {
            if (trim($lines[$i]) !== '' && $this->indentOf($lines[$i]) <= 2) {
                $entryEnd = $i;
                break;
            }
            $entryEnd = $i + 1;
        }
        for ($i = $entryIdx + 1; $i < $entryEnd; $i++) {
            if (preg_match('/^(\s*cast:\s*)\S.*$/', $lines[$i], $m)) {
                $removed[] = $lines[$i];
                $lines[$i] = $m[1].$this->yamlScalar($cast);

                return $lines;
            }
        }
        array_splice($lines, $entryEnd, 0, ['    cast: '.$this->yamlScalar($cast)]);

        return $lines;
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<int, string>  $removed
     * @return array<int, string>
     */
    private function spliceMultitenant(array $lines, array &$removed): array
    {
        $idx = $this->findTopLevelKey($lines, 'multitenant');
        if ($idx !== null) {
            $removed[] = $lines[$idx];
            $lines[$idx] = 'multitenant: true';

            return $lines;
        }
        $anchor = $this->findTopLevelKey($lines, 'tenant_key') ?? $this->findTopLevelKey($lines, 'table_prefix');
        if ($anchor !== null) {
            array_splice($lines, $anchor, 0, ['multitenant: true']);

            return $lines;
        }
        $lines[] = 'multitenant: true';

        return $lines;
    }

    // ------------------------------------------------------------------
    // Splice helpers
    // ------------------------------------------------------------------

    /** @param  array<int, string>  $lines */
    private function findTopLevelKey(array $lines, string $key): ?int
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/^'.preg_quote($key, '/').':/', $line)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Index just past the block that starts at a key line:
     * children sit deeper than the key, or — for sequences
     * — as dash items at the key's own indent. Decided by
     * the block's first child line.
     *
     * @param  array<int, string>  $lines
     */
    private function blockEnd(array $lines, int $keyIdx, int $keyIndent): int
    {
        $count = count($lines);
        $sequence = false;
        for ($i = $keyIdx + 1; $i < $count; $i++) {
            if (trim($lines[$i]) === '') {
                continue;
            }
            $sequence = $this->indentOf($lines[$i]) === $keyIndent && $this->isDashLine($lines[$i]);
            break;
        }
        for ($i = $keyIdx + 1; $i < $count; $i++) {
            $line = $lines[$i];
            if (trim($line) === '') {
                continue;
            }
            $indent = $this->indentOf($line);
            if ($indent > $keyIndent) {
                continue;
            }
            if ($sequence && $indent === $keyIndent && $this->isDashLine($line)) {
                continue;
            }

            return $i;
        }

        return $count;
    }

    private function isDashLine(string $line): bool
    {
        $trimmed = ltrim($line);

        return $trimmed === '-' || str_starts_with($trimmed, '- ');
    }

    /**
     * The root model entry's line span within the models:
     * block: [dash line index, end index, key indent].
     *
     * @param  array<int, string>  $lines
     * @param  array<string, mixed>  $parsed
     * @return array{0: int, 1: int, 2: int}
     */
    private function rootEntrySpan(array $lines, array $parsed): array
    {
        $modelsIdx = $this->findTopLevelKey($lines, 'models');
        if ($modelsIdx === null) {
            throw new \RuntimeException('Heart has no models: block');
        }
        $modelsEnd = $this->blockEnd($lines, $modelsIdx, 0);
        $starts = [];
        $dashIndent = null;
        for ($i = $modelsIdx + 1; $i < $modelsEnd; $i++) {
            if (preg_match('/^(\s*)-(\s|$)/', $lines[$i], $m)) {
                if ($dashIndent === null) {
                    $dashIndent = strlen($m[1]);
                }
                if (strlen($m[1]) === $dashIndent) {
                    $starts[] = $i;
                }
            }
        }
        if ($starts === [] || $dashIndent === null) {
            throw new \RuntimeException('models: block has no entries to anchor to');
        }
        $rootIdx = $this->rootModelIndex($parsed) ?? 0;
        if (! isset($starts[$rootIdx])) {
            throw new \RuntimeException('root model entry not found in the models: block');
        }
        $start = $starts[$rootIdx];
        $end = $starts[$rootIdx + 1] ?? $modelsEnd;

        return [$start, $end, $dashIndent + 2];
    }

    private function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }

    /** @return array<int, string> */
    private function renderRelation(array $relation, int $dashIndent): array
    {
        $pad = str_repeat(' ', $dashIndent);
        $keyPad = $pad.'  ';
        $lines = [
            $pad.'- name: '.$this->yamlScalar((string) $relation['name']),
            $keyPad.'type: '.$this->yamlScalar((string) $relation['type']),
            $keyPad.'model: '.$this->yamlScalar((string) $relation['model']),
        ];
        if (isset($relation['through'])) {
            $lines[] = $keyPad.'through: '.$this->yamlScalar((string) $relation['through']);
        }
        if (isset($relation['foreign_key'])) {
            $lines[] = $keyPad.'foreign_key: '.$this->yamlScalar((string) $relation['foreign_key']);
        }
        if (isset($relation['local_key'])) {
            $lines[] = $keyPad.'local_key: '.$this->yamlScalar((string) $relation['local_key']);
        }
        if (isset($relation['owner_key'])) {
            $lines[] = $keyPad.'owner_key: '.$this->yamlScalar((string) $relation['owner_key']);
        }
        foreach (['first_key', 'second_key', 'second_local_key'] as $throughKey) {
            if (isset($relation[$throughKey])) {
                $lines[] = $keyPad.$throughKey.': '.$this->yamlScalar((string) $relation[$throughKey]);
            }
        }
        if (isset($relation['pivot']) && is_array($relation['pivot'])) {
            $lines[] = $keyPad.'pivot:';
            foreach (['table', 'foreign_pivot_key', 'related_pivot_key', 'parent_key', 'related_key'] as $pivotKey) {
                if (isset($relation['pivot'][$pivotKey])) {
                    $lines[] = $keyPad.'  '.$pivotKey.': '.$this->yamlScalar((string) $relation['pivot'][$pivotKey]);
                }
            }
        }

        return $lines;
    }

    /** @return array<int, string> */
    private function renderFieldEntry(array $field): array
    {
        $dump = Yaml::dump([$field['name'] => $field], 4, 2);
        $out = [];
        foreach (explode("\n", rtrim($dump, "\n")) as $line) {
            $out[] = '  '.$line;
        }

        return $out;
    }

    private function yamlScalar(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_\.\-]+$/', $value)) {
            return $value;
        }

        return "'".str_replace("'", "''", $value)."'";
    }
}
