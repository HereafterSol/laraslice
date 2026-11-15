<?php

namespace LaraSlice\Core\Base\Traits;

use Illuminate\Http\Request;
use Symfony\Component\Yaml\Yaml;

/**
 * Shared field-behaviour enforcement (Seat 1, Stage D) for
 * slice controllers — web AND API (W5, D-012 parity).
 *
 * This trait is the single home of applyFieldBehaviour()
 * and applyAutoname(): BaseSliceWebController has used it
 * since Seat 1, and BaseSliceApiController now uses the
 * same code, so a write is judged identically whichever
 * surface it arrives through — mandatory_depends_on
 * rejects with a ValidationException, fetch_from fills
 * only an empty value, read_only_depends_on restores the
 * stored/default value, computed + fallback_from recompute
 * server-side, and autoname mints the naming series on
 * create. Display-only depends_on has no server effect on
 * either surface (hidden values still save).
 *
 * The host controller may define its own ownManifest()
 * (the web controller does); a class method takes
 * precedence over this trait's default implementation,
 * which resolves slice.yaml exactly as the web and
 * service bases do: two directories above the concrete
 * controller file (Controllers/ -> slice root).
 */
trait AppliesFieldBehaviour
{
    protected function ownManifest(): array
    {
        try {
            $ref = new \ReflectionClass($this);
            $sliceDir = dirname((string) $ref->getFileName(), 2);
            foreach (['slice.yaml', 'slice.yml'] as $file) {
                $path = $sliceDir.DIRECTORY_SEPARATOR.$file;
                if (is_file($path)) {
                    $data = Yaml::parseFile($path);

                    return is_array($data) ? $data : [];
                }
            }
        } catch (\Throwable $e) {
            // A missing manifest means no declared behaviour; never fail a save.
        }

        return [];
    }

    /**
     * Field behaviour enforcement (Seat 1, Stage D). The
     * Heart's conditions are enforced HERE, server-side —
     * the form's JS mirror is a courtesy, never the
     * authority:
     * - depends_on is DISPLAY-ONLY (source parity,
     *   corrected 13 Nov 2026 against live Desk evidence):
     *   a hidden field's submitted value still saves,
     *   exactly as the source form carries hidden values
     *   in the doc — mapped-doc conversions prefill fields
     *   the condition hides, and dropping them loses data
     *   the source keeps;
     * - a field read-only under its condition is restored
     *   (update keeps the stored value, create takes the
     *   declared default);
     * - mandatory_depends_on raises a validation error
     *   when its condition holds and the value is empty;
     * - fetch_from fills an empty value from the linked
     *   record (the user's own input always wins);
     * - clear_when empties the field when its condition
     *   holds (status-dependent cleanup: a record that
     *   leaves the gated state keeps none of the gated
     *   state's data — D-072);
     * - stamp_when sets the field to its token value
     *   (@today/@now) or literal on its condition's rising
     *   edge only (state-entry stamping: lost_on = the day
     *   the status BECAME lost — D-071).
     * - computed may instead declare `cases` (Phase 8,
     *   D-078): an ordered conditional derivation — each
     *   case's `when` is a FieldCondition over sibling
     *   values, collection aggregates
     *   ('<repeater>.count', '<repeater>.<sub>.count'),
     *   field-to-field operands ('<op>_from') and the
     *   @today / @now clock tokens; the first matching
     *   case's `value` (or `value_from`) wins, else the
     *   computed `default` / `default_from`. This is the
     *   seat for the source's validate-time status
     *   derivations (Contract, Email Campaign).
     * - computed may declare `date_add` (D-078): base
     *   date + an aggregate over a LINKED record's child
     *   rows (Email Campaign's end_date from its
     *   Campaign's schedule); a linked record with no
     *   rows to aggregate refuses the save, as the
     *   source's validate() does.
     * - mandatory_depends_on also treats an empty
     *   collection as missing, and a declaration may pin
     *   the refusal wording via mandatory_message.
     */
    protected function applyFieldBehaviour(Request $request, object $form, mixed $existing = null, bool $creating = false): void
    {
        $fields = \LaraSlice\Support\SliceRecordOptions::manifestFieldsOf($this->ownManifest());
        $behaviourFields = [];
        foreach ($fields as $name => $definition) {
            if (is_array($definition) && (! empty($definition['depends_on']) || ! empty($definition['mandatory_depends_on'])
                || ! empty($definition['read_only_depends_on']) || ! empty($definition['fetch_from'])
                || ! empty($definition['computed']) || ! empty($definition['fallback_from'])
                || ! empty($definition['clear_when']) || ! empty($definition['stamp_when']))) {
                $behaviourFields[$name] = $definition;
            }
        }
        if ($behaviourFields === []) {
            return;
        }

        $values = [];
        foreach ($fields as $name => $definition) {
            $values[$name] = $form->{$name} ?? (is_object($existing) ? ($existing->{$name} ?? null) : null);
            // A collection field (multiselect / repeater) that
            // this save did NOT provide keeps its stored set
            // for evaluation purposes: a partial write that
            // never mentions the collection must be judged
            // against the rows that exist, not against an
            // empty set (a status-only edit of a Lost record
            // still HAS its lost reasons). The stored set is
            // never written back from here — evaluation only;
            // the service's diff sync still owns persistence
            // and still keys off the provided markers.
            if (in_array((string) ($definition['type'] ?? ''), ['multiselect', 'repeater'], true)
                && ($values[$name] === null || $values[$name] === [])
                && is_object($existing) && is_array($existing->{$name} ?? null) && $existing->{$name} !== []
                && ! (method_exists($form, 'provided') && $form->provided($name))) {
                $values[$name] = $existing->{$name};
            }
        }

        // The stored record's values alone, for transition rules:
        // stamp_when fires on its condition's rising edge (false on
        // the stored record, true on the new values).
        $existingValues = [];
        foreach ($fields as $name => $definition) {
            $existingValues[$name] = is_object($existing) ? ($existing->{$name} ?? null) : null;
        }

        // Snapshot the behaviour fields' submitted values:
        // whatever this pass derives, clears, stamps, fetches
        // or restores must be marked provided at the end, or
        // the service's update intersect (provided fields
        // only) would silently drop a derived value for a
        // field the form never submits (a hidden status).
        $submittedValues = [];
        foreach ($behaviourFields as $name => $definition) {
            $submittedValues[$name] = $form->{$name} ?? null;
        }

        $errors = [];
        foreach ($behaviourFields as $name => $definition) {
            $restore = function () use ($form, $name, $definition, $existing, $creating) {
                $value = $creating ? ($definition['default'] ?? null) : (is_object($existing) ? ($existing->{$name} ?? null) : null);
                try {
                    $form->{$name} = $value;
                } catch (\TypeError) {
                    // A typed property that cannot hold the
                    // restored value keeps the submitted one;
                    // never fatal a save over behaviour.
                }
            };

            // depends_on intentionally has NO server-side
            // restore: visibility is display-only (see the
            // docblock) — the submitted value stands.
            $readOnly = $this->behaviourGroups($definition['read_only_depends_on'] ?? null, $name);
            if ($readOnly !== null && \LaraSlice\Support\FieldCondition::evaluateGroups($readOnly, $values)) {
                $restore();
                continue;
            }
            // clear_when (D-072): when the condition holds,
            // the field is emptied on save — the source's
            // status-dependent cleanup (an Opportunity that
            // is no longer Lost keeps no lost reasons, no
            // detailed reason, no lost_on stamp). Collection
            // fields (multiselect / repeater) clear to an
            // empty set so the child-row diff sync deletes
            // their rows; scalars clear to null. The cleared
            // value feeds the rest of this pass, so computed
            // and fallback derivations see the final state.
            $clearWhen = $this->behaviourGroups($definition['clear_when'] ?? null, $name);
            if ($clearWhen !== null && \LaraSlice\Support\FieldCondition::evaluateGroups($clearWhen, $values)) {
                $cleared = in_array((string) ($definition['type'] ?? ''), ['multiselect', 'repeater'], true) ? [] : null;
                try {
                    $form->{$name} = $cleared;
                } catch (\TypeError) {
                }
                $values[$name] = $cleared;
                continue;
            }
            // stamp_when: on the condition's rising edge, set the
            // field to the declared token value (@today/@now) or
            // literal — the source's "stamp when the state is
            // entered" semantics (e.g. lost_on = today exactly when
            // the status BECOMES lost; an already-lost record keeps
            // its original stamp, and leaving the state is handled
            // by clear_when).
            $stampWhen = is_array($definition['stamp_when'] ?? null) ? $definition['stamp_when'] : null;
            if ($stampWhen !== null && ! empty($stampWhen['when'])) {
                $stampGroups = $this->behaviourGroups($stampWhen['when'], $name);
                if ($stampGroups !== null
                    && \LaraSlice\Support\FieldCondition::evaluateGroups($stampGroups, $values)
                    && ! \LaraSlice\Support\FieldCondition::evaluateGroups($stampGroups, $existingValues)) {
                    $resolved = match ($stampWhen['value'] ?? null) {
                        '@today' => date('Y-m-d'),
                        '@now' => date('Y-m-d H:i:s'),
                        default => $stampWhen['value'] ?? null,
                    };
                    if ($resolved !== null) {
                        try {
                            $form->{$name} = $resolved;
                        } catch (\TypeError) {
                        }
                        $values[$name] = $resolved;
                    }
                }
            }

            $mandatory = $this->behaviourGroups($definition['mandatory_depends_on'] ?? null, $name);
            if ($mandatory !== null && \LaraSlice\Support\FieldCondition::evaluateGroups($mandatory, $values)
                && ($values[$name] === null || $values[$name] === '' || $values[$name] === [])) {
                // An empty collection is as missing as an
                // empty scalar (a Lost record with zero lost
                // reasons is refused by the source exactly
                // like a blank required field). A declaration
                // may pin the refusal wording with
                // mandatory_message — the source's own words
                // for the rule it mirrors.
                $label = (string) ($definition['label'] ?? $name);
                $message = isset($definition['mandatory_message']) && is_string($definition['mandatory_message']) && $definition['mandatory_message'] !== ''
                    ? $definition['mandatory_message']
                    : "The {$label} field is required under the current values.";
                $errors[$name] = [$message];
                continue;
            }
            if (! empty($definition['fetch_from']) && is_string($definition['fetch_from'])
                && ($values[$name] === null || $values[$name] === '')) {
                [$via, $targetField] = explode('.', $definition['fetch_from'], 2);
                $viaValue = $values[$via] ?? null;
                if ($viaValue !== null && $viaValue !== '') {
                    $targetSlice = \LaraSlice\Support\SliceRecordOptions::viaTargetSlice($fields, $via);
                    if ($targetSlice !== null) {
                        $fetched = \LaraSlice\Support\SliceRecordOptions::recordValue($targetSlice, $viaValue, $targetField);
                        if ($fetched !== null) {
                            try {
                                $form->{$name} = $fetched;
                            } catch (\TypeError) {
                            }
                        }
                    }
                }
            }
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        // Computed values + same-record fallbacks (the
        // source controllers' validate() derivations,
        // declared as data): the server recomputes the
        // field from its sibling parts on EVERY save, so
        // submitted input for the field itself never wins.
        // Two passes let a field computed from another
        // computed field (a title picked from a computed
        // name) settle in dependency order.
        $valueOf = function (string $field) use ($form, $values) {
            return $form->{$field} ?? $values[$field] ?? null;
        };
        $dateAddErrors = [];
        for ($pass = 0; $pass < 2; $pass++) {
            $changed = false;
            foreach ($behaviourFields as $name => $definition) {
                $computed = is_array($definition['computed'] ?? null) ? $definition['computed'] : null;
                if ($computed !== null) {
                    $requires = $computed['requires'] ?? null;
                    if ($requires !== null && $this->behaviourIsEmpty($valueOf((string) $requires))) {
                        // Guard unmet: the source leaves the
                        // field alone (a fallback may still
                        // fill it below).
                    } elseif (isset($computed['cases']) && is_array($computed['cases'])) {
                        // Conditional derivation (D-078): the
                        // source's validate-time status logic —
                        // an ordered case list over sibling
                        // values, collection aggregates and
                        // the clock; first matching case wins,
                        // else the declared default. No opinion
                        // (a referenced aggregate is unavailable
                        // in this save) leaves the field alone.
                        [$hasOpinion, $derived] = $this->behaviourComputedCases(
                            $computed,
                            $this->behaviourEvalValues($fields, $valueOf),
                            $name
                        );
                        if ($hasOpinion && $derived !== ($form->{$name} ?? null)) {
                            try {
                                $form->{$name} = $derived;
                                $changed = true;
                            } catch (\TypeError) {
                            }
                        }
                    } elseif (isset($computed['date_add']) && is_array($computed['date_add'])) {
                        // Date derivation from a linked
                        // record's child rows (D-078, Email
                        // Campaign end_date = start + the
                        // campaign's longest schedule offset):
                        // recomputed on every save, exactly as
                        // the source's validate() recomputes it.
                        $derived = $this->behaviourDateAdd($computed['date_add'], $fields, $valueOf, $dateAddErrors);
                        if ($derived !== null && $derived !== ($form->{$name} ?? null)) {
                            try {
                                $form->{$name} = $derived;
                                $changed = true;
                            } catch (\TypeError) {
                            }
                        }
                    } elseif (isset($computed['count_of']) && is_string($computed['count_of'])) {
                        // Child-count derivation (D-021 /
                        // D-034): count the rows submitted
                        // under the sibling repeater /
                        // multiselect handle. When the handle
                        // is absent from this save (a partial
                        // API write), the stored count is left
                        // alone rather than zeroed.
                        $rows = $form->{$computed['count_of']} ?? $values[$computed['count_of']] ?? null;
                        if (is_array($rows)) {
                            $count = count($rows);
                            if ($count !== ($form->{$name} ?? null)) {
                                try {
                                    $form->{$name} = $count;
                                    $changed = true;
                                } catch (\TypeError) {
                                }
                            }
                        }
                    } else {
                        $parts = [];
                        foreach ((array) ($computed['from'] ?? []) as $partField) {
                            $part = $valueOf((string) $partField);
                            if (! $this->behaviourIsEmpty($part) && is_scalar($part)) {
                                $parts[] = (string) $part;
                            }
                        }
                        $result = null;
                        if (($computed['pick'] ?? null) === 'first') {
                            $result = $parts[0] ?? null;
                        } elseif (isset($computed['join']) && $parts !== []) {
                            $result = implode((string) $computed['join'], $parts);
                        }
                        if ($result !== null && $result !== ($form->{$name} ?? null)) {
                            try {
                                $form->{$name} = $result;
                                $changed = true;
                            } catch (\TypeError) {
                            }
                        }
                    }
                }
                $fallback = $definition['fallback_from'] ?? null;
                if (is_string($fallback) && $fallback !== ''
                    && $this->behaviourIsEmpty($form->{$name} ?? $values[$name] ?? null)) {
                    $fallbackValue = $valueOf($fallback);
                    if (! $this->behaviourIsEmpty($fallbackValue) && is_scalar($fallbackValue)) {
                        try {
                            $form->{$name} = $fallbackValue;
                            $changed = true;
                        } catch (\TypeError) {
                        }
                    }
                }
            }
            if (! $changed) {
                break;
            }
        }

        if ($dateAddErrors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($dateAddErrors);
        }

        // A value this pass changed is part of the save by
        // definition: mark it provided so the service's
        // provided-fields intersect persists it (Phase 8:
        // hidden derived statuses — Contract status is
        // never a form input, yet its derivation must land
        // on update exactly as it lands on create).
        if (method_exists($form, 'markProvided')) {
            foreach ($submittedValues as $name => $submitted) {
                if (($form->{$name} ?? null) !== $submitted) {
                    try {
                        $form->markProvided($name);
                    } catch (\Throwable) {
                    }
                }
            }
        }
    }

    /**
     * The evaluation map for conditional derivations: every
     * field's current value, plus the collection aggregates
     * a case clause may name — '<collection>.count' (row
     * count) and '<repeater>.<subfield>.count' (rows whose
     * subfield holds a Check-truthy value: true / 1 / '1').
     * An aggregate is present only when the collection's
     * rows are part of this save (submitted, or the stored
     * set the values build-up fell back to) — its absence
     * is how a case learns it has no opinion here.
     */
    protected function behaviourEvalValues(array $fields, callable $valueOf): array
    {
        $eval = [];
        foreach ($fields as $fieldName => $definition) {
            $eval[$fieldName] = $valueOf((string) $fieldName);
        }
        foreach ($fields as $fieldName => $definition) {
            if (! is_array($definition) || ! in_array((string) ($definition['type'] ?? ''), ['multiselect', 'repeater'], true)) {
                continue;
            }
            $rows = $eval[$fieldName] ?? null;
            if (! is_array($rows)) {
                continue;
            }
            $eval[$fieldName.'.count'] = count($rows);
            if ((string) ($definition['type'] ?? '') === 'repeater') {
                foreach ((array) ($definition['repeater_fields'] ?? []) as $sub) {
                    $handle = is_array($sub) ? (string) ($sub['handle'] ?? '') : '';
                    if ($handle === '') {
                        continue;
                    }
                    $truthy = 0;
                    foreach ($rows as $row) {
                        $cell = is_array($row) ? ($row[$handle] ?? null) : (is_object($row) ? ($row->{$handle} ?? null) : null);
                        if ($cell === true || $cell === 1 || $cell === '1' || $cell === 1.0) {
                            $truthy++;
                        }
                    }
                    $eval[$fieldName.'.'.$handle.'.count'] = $truthy;
                }
            }
        }

        return $eval;
    }

    /**
     * Evaluate a conditional derivation's case list.
     * Returns [hasOpinion, value]. A case referencing an
     * aggregate the evaluation map does not carry (the
     * collection is not part of this save) cannot be
     * judged: it is skipped, and if no other case
     * matches, the derivation has no opinion rather than
     * letting the default fire on a guess — the stored
     * value stands, exactly as a partial write leaves an
     * unmentioned derivation alone. Aggregate-free cases
     * are always judged (a fulfilment-free record still
     * derives its N/A).
     *
     * @return array{0: bool, 1: mixed}
     */
    protected function behaviourComputedCases(array $computed, array $evalValues, string $fieldName): array
    {
        $sawUnavailable = false;
        foreach ((array) ($computed['cases'] ?? []) as $case) {
            if (! is_array($case) || ! array_key_exists('when', $case)) {
                continue;
            }
            $groups = $this->behaviourGroups($case['when'], $fieldName);
            if ($groups === null) {
                continue; // A malformed condition never matches.
            }
            // A case that references an aggregate this save
            // does not carry cannot be judged: it neither
            // matches nor lets the default fire (a later,
            // aggregate-free case still may).
            $unavailable = false;
            foreach ($groups as $clauses) {
                foreach ($clauses as $clause) {
                    foreach ([(string) ($clause['field'] ?? ''), (string) ($clause['value_from'] ?? '')] as $operand) {
                        if ($operand !== '' && str_contains($operand, '.') && ! array_key_exists($operand, $evalValues)) {
                            $unavailable = true;
                            break 2;
                        }
                    }
                }
                if ($unavailable) {
                    break;
                }
            }
            if ($unavailable) {
                $sawUnavailable = true;
                continue;
            }
            if (\LaraSlice\Support\FieldCondition::evaluateGroups($groups, $evalValues)) {
                if (array_key_exists('value', $case)) {
                    return [true, $case['value']];
                }
                if (isset($case['value_from']) && is_string($case['value_from'])) {
                    return [true, $evalValues[$case['value_from']] ?? null];
                }

                return [true, null];
            }
        }
        if ($sawUnavailable) {
            return [false, null];
        }
        if (array_key_exists('default', $computed)) {
            return [true, $computed['default']];
        }
        if (isset($computed['default_from']) && is_string($computed['default_from'])) {
            return [true, $evalValues[$computed['default_from']] ?? null];
        }

        return [false, null];
    }

    /**
     * The date_add derivation: base date + an aggregate
     * (max / min / sum) over a child-row column of the
     * record a link field points at. Returns the derived
     * 'Y-m-d' or null for "no opinion" (base or link
     * empty, target unresolvable). When the link and base
     * are present but the linked record has NO rows to
     * aggregate, the source refuses the save — that
     * refusal is collected into $errors under the link
     * field with the source's own message.
     */
    protected function behaviourDateAdd(array $dateAdd, array $fields, callable $valueOf, array &$errors): ?string
    {
        $baseField = (string) ($dateAdd['base'] ?? '');
        $daysFrom = is_array($dateAdd['days_from'] ?? null) ? $dateAdd['days_from'] : null;
        if ($baseField === '' || $daysFrom === null) {
            return null;
        }
        $base = $valueOf($baseField);
        $linkField = (string) ($daysFrom['link'] ?? '');
        $linkValue = $linkField !== '' ? $valueOf($linkField) : null;
        if (! is_string($base) || $base === '' || $linkValue === null || $linkValue === '') {
            return null;
        }
        $targetSlice = \LaraSlice\Support\SliceRecordOptions::viaTargetSlice($fields, $linkField);
        if ($targetSlice === null) {
            return null;
        }
        try {
            $slice = app(\LaraSlice\Core\Discovery\SliceManager::class)->getSlice($targetSlice);
            if ($slice === null) {
                return null;
            }
            $targetFields = \LaraSlice\Support\SliceRecordOptions::manifestFieldsOf(
                \LaraSlice\Core\Discovery\ManifestRepository::readFromSliceDir($slice->path)
            );
            $repeater = $targetFields[(string) ($daysFrom['repeater'] ?? '')] ?? null;
            $table = is_array($repeater) ? ($repeater['table'] ?? null) : null;
            $foreignKey = is_array($repeater) ? ($repeater['foreign_key'] ?? null) : null;
            $column = (string) ($daysFrom['field'] ?? '');
            if (! is_string($table) || ! is_string($foreignKey) || $column === '') {
                return null;
            }
            $values = \Illuminate\Support\Facades\DB::table($table)->where($foreignKey, $linkValue)->pluck($column)->all();
        } catch (\Throwable) {
            return null;
        }
        if ($values === []) {
            // Desk (Email Campaign.set_date): an EC whose
            // campaign carries no schedule cannot derive an
            // end date, and the save is refused outright.
            $errors[$linkField] = ['Please set up the Campaign Schedule in the Campaign.'];

            return null;
        }
        $numbers = array_map(static fn ($v) => is_numeric($v) ? (float) $v : 0.0, $values);
        $days = match ((string) ($daysFrom['agg'] ?? 'max')) {
            'min' => min($numbers),
            'sum' => array_sum($numbers),
            default => max($numbers),
        };
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', substr($base, 0, 10));
        if ($date === false) {
            return null;
        }

        return $date->modify('+'.((int) $days).' days')->format('Y-m-d');
    }

    /** Emptiness with FieldCondition's semantics (an unchecked Check is empty; the string '0' is not). */
    protected function behaviourIsEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || $value === false || $value === 0 || $value === 0.0;
    }

    /** Normalize a raw declared condition; malformed data disables the condition rather than fataling a save. */
    protected function behaviourClauses(mixed $declaration, string $fieldName): ?array
    {
        if ($declaration === null) {
            return null;
        }
        try {
            return \LaraSlice\Support\FieldCondition::normalize($declaration, $fieldName);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Normalize a declared condition into OR-of-AND groups ({any: [...]} or a plain clause list). */
    protected function behaviourGroups(mixed $declaration, string $fieldName): ?array
    {
        if ($declaration === null) {
            return null;
        }
        try {
            return \LaraSlice\Support\FieldCondition::normalizeGroups($declaration, $fieldName);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Naming-series generation (Seat 1): when the slice
     * declares autoname and the create left the target
     * field empty, mint the next number. A user-supplied
     * value always wins.
     */
    protected function applyAutoname(object $form, ?Request $request = null): void
    {
        $manifest = $this->ownManifest();
        $autoname = $manifest['autoname'] ?? null;
        if (! is_array($autoname) || empty($autoname['pattern'])) {
            return;
        }
        $field = (string) ($autoname['field'] ?? 'name');
        if (! property_exists($form, $field)) {
            return;
        }
        if ($request !== null) {
            // A value the user actually submitted always wins.
            // Anything else on the BO is not user input: the
            // form object's name/title bridge fills an empty
            // name from a sibling field (label/title), and that
            // convenience must never pre-empt a declared
            // naming series.
            $submitted = $request->input($field) ?? $request->input(\Illuminate\Support\Str::camel($field));
            if (! empty($submitted)) {
                return;
            }
        } elseif (! empty($form->{$field})) {
            return;
        }
        $next = \LaraSlice\Support\NamingSeries::next((string) ($manifest['name'] ?? static::class), $autoname);
        if ($next !== null) {
            try {
                $form->{$field} = $next;
            } catch (\TypeError) {
            }
        }
    }
}
