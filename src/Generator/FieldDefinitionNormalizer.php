<?php

namespace LaraSlice\Generator;

use Illuminate\Support\Str;
use LaraSlice\Schema\FieldType;

/**
 * Turns the field definitions given to SliceGenerator (CLI, Slice Studio, blueprints) into
 * everything the generated slice needs per field.
 */
final class FieldDefinitionNormalizer
{
    /**
     * Validate and normalize user field input before the generator creates any files: column,
     * validation rules, schema call, form markup and DTO type for each field.
     */
    public function normalize(array $fields): array
    {
        if (count($fields) > 250) {
            throw new SliceFieldDefinitionException('A slice can define at most 250 custom fields per generation.');
        }

        $definitions = [];
        $reserved = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $types = [
            'string' => ['migration' => 'string', 'validation' => 'string', 'input' => 'text'],
            'text' => ['migration' => 'text', 'validation' => 'string', 'input' => 'textarea'],
            // Rich text (D-054): the source's Text Editor fields —
            // stored as HTML in a text column, edited through the
            // shell's dependency-free contenteditable editor
            // (data-ls-richtext in blatui-core).
            'richtext' => ['migration' => 'text', 'validation' => 'string', 'input' => 'richtext'],
            // Code (D-054 lesser seat): the source's Code fields —
            // a monospace textarea, no execution semantics.
            'code' => ['migration' => 'text', 'validation' => 'string', 'input' => 'code'],
            'integer' => ['migration' => 'integer', 'validation' => 'integer', 'input' => 'number'],
            'decimal' => ['migration' => 'decimal', 'validation' => 'numeric', 'input' => 'decimal'],
            'boolean' => ['migration' => 'boolean', 'validation' => 'boolean', 'input' => 'boolean'],
            'date' => ['migration' => 'date', 'validation' => 'date', 'input' => 'date'],
            'datetime' => ['migration' => 'dateTime', 'validation' => 'date', 'input' => 'datetime-local'],
            'email' => ['migration' => 'string', 'validation' => 'email', 'input' => 'email'],
            'select' => ['migration' => 'string', 'validation' => 'string', 'input' => 'select'],
            'foreign_id' => ['migration' => 'unsignedBigInteger', 'validation' => 'integer', 'input' => 'select'],
            'url' => ['migration' => 'string', 'validation' => 'url', 'input' => 'url'],
            'json' => ['migration' => 'json', 'validation' => 'json', 'input' => 'textarea'],
            'float' => ['migration' => 'float', 'validation' => 'numeric', 'input' => 'decimal'],
            'bigInteger' => ['migration' => 'bigInteger', 'validation' => 'integer', 'input' => 'number'],
            'timestamp' => ['migration' => 'timestamp', 'validation' => 'date', 'input' => 'datetime-local'],
            // A child collection (the source systems' Table
            // fields): no column on this table — the rows live
            // in the child table and sync through the service's
            // repeater machinery. The migration entry is never
            // emitted (SliceGenerator skips repeater fields);
            // validation accepts the submitted row array.
            'repeater' => ['migration' => 'json', 'validation' => 'array', 'input' => 'repeater'],
            // A Table MultiSelect (the source systems' picker
            // over a child table): also no column here — the
            // selection lives as child rows holding one link
            // each, synced through the service's multiselect
            // machinery. The declaration must name the child
            // table, its parent key, the child's link column
            // and the target slice (validated below).
            'multiselect' => ['migration' => 'json', 'validation' => 'array', 'input' => 'multiselect'],
        ];

        foreach ($fields as $index => $definition) {
            if (! is_array($definition) || ! isset($definition['name']) || ! is_string($definition['name'])) {
                throw new SliceFieldDefinitionException("Custom field at index {$index} must include a string name.");
            }

            $name = $definition['name'];
            $type = $definition['type'] ?? 'string';
            if (! is_string($type)) {
                throw new SliceFieldDefinitionException("Field '{$name}' must use a supported text type name.");
            }
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                throw new SliceFieldDefinitionException("Invalid field name '{$name}'. Use lowercase snake_case identifiers.");
            }
            if (in_array($name, $reserved, true) || isset($definitions[$name])) {
                throw new SliceFieldDefinitionException("Field '{$name}' is reserved or duplicated.");
            }
            if (! isset($types[$type])) {
                throw new SliceFieldDefinitionException("Unsupported type '{$type}' for field '{$name}'.");
            }

            $label = $definition['label'] ?? Str::headline($name);
            if (! is_string($label) || mb_strlen($label) > 120) {
                throw new SliceFieldDefinitionException("Field '{$name}' must have a text label up to 120 characters.");
            }
            if ($problem = BladeSafeText::problem($label, "Field '{$name}' label")) {
                throw new SliceFieldDefinitionException($problem);
            }

            // Declared options make ANY field a select in the form and a
            // labeled value in listings — the storage type stays as declared
            // (e.g. a MySQL enum ported as string + options). Previously only
            // the literal 'select' type kept its options; string+options
            // fields silently lost them and rendered as free text.
            $options = [];
            if (! empty($definition['options'])) {
                $options = $definition['options'] ?? [];
                if (! is_array($options) || count($options) > 50) {
                    throw new SliceFieldDefinitionException("Field '{$name}' requires between 1 and 50 options when options are declared.");
                }
                foreach ($options as $value => $optionLabel) {
                    // Spaces are legal in option VALUES (real-world enum
                    // vocabularies store them — a project status of
                    // "in progress" must be declarable). Quotes, angle
                    // brackets and commas stay excluded: a comma would
                    // corrupt the generated `in:` validation rule and the
                    // rest endanger the rendered markup.
                    if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_. -]{1,80}$/', $value) || ! is_string($optionLabel) || ! BladeSafeText::isSafe($optionLabel)) {
                        throw new SliceFieldDefinitionException("Field '{$name}' has an invalid option.");
                    }
                }
            }

            // Dynamic option sources (Stage C-5): `slices`
            // (the value is a slice NAME — a registry link)
            // and `slice_records:<field>` (a dynamic link
            // whose options are the records of the slice the
            // named field selects). Resolved at runtime by
            // BaseSliceWebController + the options endpoint;
            // the form renders a plain select fed by the
            // resolved {handle}Options var.
            $optionsFrom = null;
            if (isset($definition['options_from'])) {
                if (! is_string($definition['options_from'])
                    || ($definition['options_from'] !== 'slices'
                        && ! preg_match('/^slice_records:[a-z][a-z0-9_]{0,63}$/', $definition['options_from']))) {
                    throw new SliceFieldDefinitionException("Field '{$name}' has an unsupported options_from source.");
                }
                $optionsFrom = $definition['options_from'];
            }

            // Field behaviour vocabulary (Seat 1, Stage D):
            // conditions are declarative clause lists (see
            // Support\FieldCondition — runtime-neutral, no
            // code strings); fetch_from names a sibling link
            // field and the target record's field to copy.
            $behaviour = [];
            foreach (['depends_on', 'mandatory_depends_on', 'read_only_depends_on', 'clear_when'] as $behaviourKey) {
                if (isset($definition[$behaviourKey])) {
                    try {
                        if (is_array($definition[$behaviourKey]) && isset($definition[$behaviourKey]['any'])) {
                            // OR-of-groups shape: store the
                            // normalized groups under 'any' —
                            // the shape both evaluators (PHP
                            // normalizeGroups, the JS mirror)
                            // read natively.
                            $behaviour[$behaviourKey] = ['any' => \LaraSlice\Support\FieldCondition::normalizeGroups($definition[$behaviourKey], $name)];
                        } else {
                            $behaviour[$behaviourKey] = \LaraSlice\Support\FieldCondition::normalize($definition[$behaviourKey], $name);
                        }
                    } catch (\InvalidArgumentException $e) {
                        throw new SliceFieldDefinitionException($e->getMessage());
                    }
                }
            }
            // stamp_when (D-071): {when: <clauses>, value:
            // "@today"|"@now"|literal} — the nested condition
            // normalizes like every other behaviour key; the
            // value token rides verbatim.
            if (isset($definition['stamp_when'])) {
                if (! is_array($definition['stamp_when']) || ! isset($definition['stamp_when']['when'])
                    || ! array_key_exists('value', $definition['stamp_when'])
                    || ! is_scalar($definition['stamp_when']['value'])) {
                    throw new SliceFieldDefinitionException("Field '{$name}' stamp_when must be {when: <clauses>, value: <token or literal>}.");
                }
                try {
                    if (is_array($definition['stamp_when']['when']) && isset($definition['stamp_when']['when']['any'])) {
                        $stampGroups = ['any' => \LaraSlice\Support\FieldCondition::normalizeGroups($definition['stamp_when']['when'], $name)];
                    } else {
                        $stampGroups = \LaraSlice\Support\FieldCondition::normalize($definition['stamp_when']['when'], $name);
                    }
                } catch (\InvalidArgumentException $e) {
                    throw new SliceFieldDefinitionException($e->getMessage());
                }
                $behaviour['stamp_when'] = ['when' => $stampGroups, 'value' => $definition['stamp_when']['value']];
            }
            $fetchFrom = null;
            if (isset($definition['fetch_from'])) {
                if (! is_string($definition['fetch_from'])
                    || ! preg_match('/^[a-z][a-z0-9_]{0,63}\.[a-z][a-z0-9_]{0,63}$/', $definition['fetch_from'])) {
                    throw new SliceFieldDefinitionException("Field '{$name}' fetch_from must look like '<link field>.<target field>'.");
                }
                $fetchFrom = $definition['fetch_from'];
            }
            // Computed values (source-logic vocabulary):
            // the server recomputes the field from sibling
            // fields on every save — the portable form of
            // the source controllers' validate() derivations
            // (a full name joined from its parts, a title
            // picked from the first non-empty candidate).
            // Grammar (data only, runtime-neutral):
            //   computed: {from: [field, ...], join: ' '}
            //   computed: {from: [field, ...], pick: first}
            //   either may add requires: <field> — the
            //   computation runs only when that field is
            //   non-empty.
            // fallback_from: <field> — when the field is
            // still empty after computation, copy that
            // sibling field's value.
            $computed = null;
            if (isset($definition['computed'])) {
                $c = $definition['computed'];
                if (! is_array($c)) {
                    throw new SliceFieldDefinitionException("Field '{$name}' computed must be a declaration map.");
                }
                $requires = $c['requires'] ?? null;
                if ($requires !== null && (! is_string($requires) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $requires))) {
                    throw new SliceFieldDefinitionException("Field '{$name}' computed.requires must be a sibling field name.");
                }
                if (isset($c['count_of'])) {
                    // Child-count derivation (D-021 / D-034):
                    // the field's value is the row count of a
                    // sibling repeater / multiselect field — the
                    // source's read-only "Total Holidays" and
                    // "Number of Agents" are exactly this. The
                    // behaviour trait counts the submitted rows
                    // on every save; the form renders it read-only.
                    if (! is_string($c['count_of']) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $c['count_of'])) {
                        throw new SliceFieldDefinitionException("Field '{$name}' computed.count_of must name a sibling repeater or multiselect field.");
                    }
                    $computed = ['count_of' => $c['count_of'], 'requires' => $requires];
                } else {
                    if (empty($c['from']) || ! is_array($c['from'])
                        || count($c['from']) > 8) {
                        throw new SliceFieldDefinitionException("Field '{$name}' computed.from must list 1 to 8 sibling fields.");
                    }
                    foreach ($c['from'] as $part) {
                        if (! is_string($part) || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $part)) {
                            throw new SliceFieldDefinitionException("Field '{$name}' computed.from entries must be sibling field names.");
                        }
                    }
                    $join = $c['join'] ?? null;
                    $pick = $c['pick'] ?? null;
                    if ($join !== null && $pick !== null) {
                        throw new SliceFieldDefinitionException("Field '{$name}' computed takes either join or pick, not both.");
                    }
                    if ($join !== null && (! is_string($join) || mb_strlen($join) > 8)) {
                        throw new SliceFieldDefinitionException("Field '{$name}' computed.join must be a short separator string.");
                    }
                    if ($pick !== null && $pick !== 'first') {
                        throw new SliceFieldDefinitionException("Field '{$name}' computed.pick supports only 'first'.");
                    }
                    if ($join === null && $pick === null) {
                        $pick = 'first';
                    }
                    $computed = ['from' => array_values($c['from']), 'join' => $join, 'pick' => $pick, 'requires' => $requires];
                }
            }
            $fallbackFrom = null;
            if (isset($definition['fallback_from'])) {
                if (! is_string($definition['fallback_from'])
                    || ! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $definition['fallback_from'])) {
                    throw new SliceFieldDefinitionException("Field '{$name}' fallback_from must be a sibling field name.");
                }
                $fallbackFrom = $definition['fallback_from'];
            }
            $section = null;
            if (isset($definition['section'])) {
                if (! is_string($definition['section']) || mb_strlen($definition['section']) > 80
                    || ($problem = BladeSafeText::problem($definition['section'], "Field '{$name}' section"))) {
                    throw new SliceFieldDefinitionException($problem ?? "Field '{$name}' section must be a short text label.");
                }
                $section = $definition['section'];
            }

            $requiredInput = $definition['required'] ?? null;
            if ($requiredInput !== null && ! is_bool($requiredInput) && ! in_array($requiredInput, [0, 1, '0', '1'], true)) {
                throw new SliceFieldDefinitionException("Field '{$name}' required must be true or false.");
            }

            $nullableInput = $definition['nullable'] ?? ! (bool) $requiredInput;
            if (! is_bool($nullableInput) && ! in_array($nullableInput, [0, 1, '0', '1'], true)) {
                throw new SliceFieldDefinitionException("Field '{$name}' nullable must be true or false.");
            }
            if ($requiredInput !== null && (bool) $requiredInput === (bool) $nullableInput) {
                throw new SliceFieldDefinitionException("Field '{$name}' required and nullable settings conflict.");
            }
            $nullable = (bool) $nullableInput;
            $required = ! $nullable;
            $default = FieldType::coerceDefault($type, $definition['default'] ?? null) ?? ($type === 'boolean' ? false : null);
            if (! is_null($default) && ! is_scalar($default)) {
                throw new SliceFieldDefinitionException("Field '{$name}' default must be a scalar value or null.");
            }
            if (is_string($default) && ($problem = BladeSafeText::problem($default, "Field '{$name}' default"))) {
                throw new SliceFieldDefinitionException($problem);
            }
            if (! FieldType::defaultMatches($type, $default)) {
                throw new SliceFieldDefinitionException("Field '{$name}' has a default value that does not match its {$type} type.");
            }
            if ($default !== null && ! empty($options) && ! str_ends_with($name, '_id') && ! array_key_exists($default, $options)) {
                throw new SliceFieldDefinitionException("Field '{$name}' default must match one of its select option values.");
            }

            $encrypted = (bool) ($definition['encrypted'] ?? false);
            if ($encrypted && ! FieldType::isEncryptable($type)) {
                throw new SliceFieldDefinitionException("Field '{$name}' cannot be encrypted; only text-like fields support encryption.");
            }

            $length = $definition['length'] ?? null;
            // Forms (the Slice Studio wizard) send lengths as strings: "" means none, "50" means 50
            if ($length === '') {
                $length = null;
            } elseif (is_string($length) && ctype_digit($length)) {
                $length = (int) $length;
            }
            $maxLength = 255;
            $column = $types[$type]['migration'];
            if ($encrypted) {
                // Ciphertext is much longer than the value, so encrypted columns are always text
                $columnExpression = "\$table->text('{$name}')";
            } elseif (in_array($type, ['decimal', 'float'], true)) {
                [$precision, $scale] = [12, 2];
                if ($length !== null) {
                    if (! is_string($length) || ! preg_match('/^(\d{1,2})\s*,\s*(\d{1,2})$/', $length, $m) || (int) $m[2] > (int) $m[1]) {
                        throw new SliceFieldDefinitionException("Field '{$name}' length must be \"precision,scale\", e.g. \"12,2\".");
                    }
                    [$precision, $scale] = [(int) $m[1], (int) $m[2]];
                }
                $columnExpression = "\$table->decimal('{$name}', {$precision}, {$scale})";
            } elseif (in_array($column, ['string'], true) && $length !== null) {
                if (! is_int($length) || $length < 1 || $length > 65535) {
                    throw new SliceFieldDefinitionException("Field '{$name}' length must be between 1 and 65535.");
                }
                $maxLength = $length;
                $columnExpression = "\$table->string('{$name}', {$length})";
            } elseif ($length !== null) {
                throw new SliceFieldDefinitionException("Field '{$name}' does not support a length.");
            } else {
                $columnExpression = "\$table->{$column}('{$name}')";
            }
            if ($nullable) {
                $columnExpression .= '->nullable()';
            }
            if ($default !== null) {
                $columnExpression .= '->default('.var_export($default, true).')';
            }
            if ($type === 'foreign_id') {
                $references = $definition['references'] ?? null;
                if ($references !== null && ! preg_match('/^[a-z][a-z0-9_]{0,62}$/', (string) $references)) {
                    throw new SliceFieldDefinitionException("Field '{$name}' references an invalid table name.");
                }
                // A real constraint only when the referenced table is known; always an index
                $columnExpression = $references !== null
                    ? "\$table->foreignId('{$name}')".($nullable ? '->nullable()' : '')."->constrained('{$references}')->".($nullable ? 'nullOnDelete()' : 'restrictOnDelete()')
                    : $columnExpression.'->index()';
            }

            $rules = array_values(array_filter([
                $nullable ? 'nullable' : 'required',
                $types[$type]['validation'],
                in_array($type, ['string', 'email', 'select', 'url'], true) ? 'max:'.$maxLength : null,
                ! empty($options) && ! str_ends_with($name, '_id') ? 'in:'.implode(',', array_keys($options)) : null,
            ]));

            // Form hygiene (Body v2 rules): the input for a
            // date-domain column is a date input even when the
            // storage type is datetime — people pick days, not
            // timestamps, for leave_date / start_date / *_on.
            // The date-domain names are the ones source systems
            // consistently use for day-granularity values:
            // *_date, *_on, valid *_till, date_of_birth, a bare
            // `date`, `deadline`, `dob` (the comparative test
            // found every one of these rendered datetime-local
            // on the ported ERP).
            $input = $types[$type]['input'];
            if (in_array($type, ['datetime', 'timestamp'], true) && self::isDateDomainName($name)) {
                $input = 'date';
            }

            $schema = "Field::make('{$name}', '{$input}')->label(".var_export($label, true).')';
            if ($required) {
                $schema .= '->required()';
            }
            if ($default !== null) {
                $schema .= '->default('.var_export($default, true).')';
            }
            if ($options) {
                $schema .= '->options('.var_export($options, true).')';
            }

            // Visibility flags SURVIVE normalization (they were
            // silently dropped, which made manifest show_in_form
            // edits no-ops). System/audit columns (added_by,
            // approved_*, event_id, *_by) default to hidden from
            // forms; an explicit show_in_form in the manifest
            // still wins — the Heart is edited deliberately.
            $hidden = ! empty($definition['hidden']);
            $showInForm = isset($definition['show_in_form'])
                ? (bool) $definition['show_in_form']
                : ! $hidden && ! self::isSystemField($name);

            // Repeater (child collection) declarations: the
            // sub-fields normalize to one shape here so the
            // generator's two form paths and the service
            // definitions all read the same array.
            $repeaterFields = [];
            if ($type === 'repeater') {
                foreach ((array) ($definition['repeater_fields'] ?? []) as $rf) {
                    if (! is_array($rf)) {
                        continue;
                    }
                    $rfName = $rf['handle'] ?? $rf['name'] ?? '';
                    if (! is_string($rfName) || $rfName === '' || $rfName === 'id') {
                        continue;
                    }
                    $repeaterEntry = [
                        'handle' => $rfName,
                        'label' => (string) ($rf['label'] ?? Str::headline($rfName)),
                        'type' => (string) ($rf['type'] ?? 'string'),
                        'required' => (bool) ($rf['required'] ?? false),
                        'options' => is_array($rf['options'] ?? null) ? $rf['options'] : [],
                    ];
                    // Link picker seat (D-030): an optional
                    // `target` names the slice a Link sub-field
                    // points at — the repeater component renders
                    // it as a record picker fed by that slice's
                    // records instead of a raw-id number input.
                    // Undeclared stays byte-identical to the
                    // historical shape (no key at all).
                    if (isset($rf['target'])) {
                        if (! is_string($rf['target']) || ! preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/', $rf['target'])) {
                            throw new SliceFieldDefinitionException("Field '{$name}' repeater sub-field '{$rfName}' target must be a slice name.");
                        }
                        $repeaterEntry['target'] = $rf['target'];
                    }
                    $repeaterFields[] = $repeaterEntry;
                }
                if ($repeaterFields === []) {
                    throw new SliceFieldDefinitionException("Field '{$name}' is a repeater and must declare repeater_fields.");
                }
            }

            // Multiselect (Table MultiSelect) declarations:
            // the four seat keys are all required — a picker
            // without its child table, parent key, link
            // column or target slice cannot load or save.
            if ($type === 'multiselect') {
                foreach (['table', 'foreign_key', 'target_field', 'target'] as $seatKey) {
                    if (! is_string($definition[$seatKey] ?? null) || $definition[$seatKey] === '') {
                        throw new SliceFieldDefinitionException("Field '{$name}' is a multiselect and must declare {$seatKey}.");
                    }
                }
            }

            // Field caption (D-066): the source systems print a
            // field's `description` under its control; the porter
            // dialect carries it verbatim and every regen keeps
            // it, so a caption can never silently drop again.
            $description = isset($definition['description']) && is_string($definition['description'])
                ? trim($definition['description'])
                : null;
            if ($description === '') {
                $description = null;
            }

            // Dynamic label (D-019): a field whose LABEL follows
            // another field's value (an Opportunity's Party reads
            // "Lead" / "Customer" per Opportunity From). The
            // declaration {field, map: {via value: label}} rides
            // the form as data attributes; the shell swaps the
            // label text live (and on load) from the via value.
            $labelDependsOn = null;
            if (is_array($definition['label_depends_on'] ?? null)
                && is_string($definition['label_depends_on']['field'] ?? null)
                && is_array($definition['label_depends_on']['map'] ?? null)) {
                $map = [];
                foreach ($definition['label_depends_on']['map'] as $viaValue => $mappedLabel) {
                    if (is_string($mappedLabel)) {
                        $map[(string) $viaValue] = $mappedLabel;
                    }
                }
                if ($map !== []) {
                    $labelDependsOn = ['field' => $definition['label_depends_on']['field'], 'map' => $map];
                }
            }

            $formMarkup = '';
            if ($type !== 'repeater' && $type !== 'multiselect') {
                $formMarkup = $this->renderFormField($name, $label, ! empty($options) ? 'select' : $input, $required, $default, $options, is_string($definition['relation'] ?? null) ? $definition['relation'] : null, $optionsFrom, $description);
                if ($labelDependsOn !== null) {
                    $labelAttrs = ' data-ls-label-for="'.htmlspecialchars($labelDependsOn['field'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" data-ls-label-map="'.htmlspecialchars((string) json_encode($labelDependsOn['map']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"';
                    $formMarkup = (string) preg_replace('/<x-ui\.label for="'.preg_quote($name, '/').'"/', '<x-ui.label for="'.$name.'"'.$labelAttrs, $formMarkup, 1);
                }
                if (is_array($computed) && isset($computed['count_of'])) {
                    // A derived count is never typed (D-021):
                    // the input renders read-only; the value is
                    // recomputed server-side on every save.
                    $formMarkup = (string) preg_replace('/(<x-ui\.input id="'.preg_quote($name, '/').'"(?:[^">]|"[^"]*")*?)(\s*\/?>)/', '$1 readonly$2', $formMarkup, 1);
                }
                $formMarkup = $this->withBehaviourAttributes($formMarkup, $name, $behaviour, $fetchFrom);
            }

            $definitions[$name] = [
                'name' => $name,
                'label' => $label,
                'description' => $description,
                'label_depends_on' => $labelDependsOn,
                'rules' => $rules,
                'type' => $type,
                'schema' => $schema,
                'form' => $formMarkup,
                'migration' => $columnExpression.';',
                'default' => $default,
                'nullable' => $nullable,
                'options' => $options,
                'options_from' => $optionsFrom,
                // Field behaviour (Seat 1): normalized
                // clause lists + fetch source + directory
                // and layout flags, carried verbatim so
                // every regen keeps the behaviour.
                'depends_on' => $behaviour['depends_on'] ?? null,
                'mandatory_depends_on' => $behaviour['mandatory_depends_on'] ?? null,
                'read_only_depends_on' => $behaviour['read_only_depends_on'] ?? null,
                'clear_when' => $behaviour['clear_when'] ?? null,
                'stamp_when' => $behaviour['stamp_when'] ?? null,
                'fetch_from' => $fetchFrom,
                'computed' => $computed,
                'fallback_from' => $fallbackFrom,
                'in_list_view' => ! empty($definition['in_list_view']),
                'in_standard_filter' => ! empty($definition['in_standard_filter']),
                'section' => $section,
                'encrypted' => $encrypted,
                // Declared Eloquent cast (the field-level
                // `cast` key, absorbed vocabulary 7 Nov):
                // carried verbatim so SliceGenerator can
                // emit it into the model's $casts.
                'cast' => isset($definition['cast']) && is_string($definition['cast']) && $definition['cast'] !== '' ? $definition['cast'] : null,
                // Declared length, carried verbatim like `cast`:
                // for decimal/float fields this is the "p,s"
                // precision the migration expression above was
                // baked from — SliceGenerator records it in the
                // manifest so a regen from the Heart keeps the
                // precision instead of reverting to (12,2).
                'length' => $length,
                // Both relation dialects survive: the array
                // form ({name, model} from blueprint model
                // relations) and the string form (the porter
                // dialect — a target entity name on a non-_id
                // field, e.g. award_to -> 'user'). Dropping
                // the string form here silently reverted
                // such fields to free inputs on regen (D3).
                'relation' => (is_array($definition['relation'] ?? null) && $definition['relation'] !== [])
                    || (is_string($definition['relation'] ?? null) && $definition['relation'] !== '')
                    ? $definition['relation']
                    : null,
                'hidden' => $hidden,
                'show_in_form' => $showInForm,
                'show_in_list' => $type === 'repeater' || $type === 'multiselect' ? false : (isset($definition['show_in_list']) ? (bool) $definition['show_in_list'] : ! $hidden),
                'system' => self::isSystemField($name),
                // Repeater seat (child collections declared
                // in the fields map, the field-dialect twin of
                // the ui.forms.sections declaration): carried
                // verbatim so SliceGenerator can build the
                // service definitions + form component.
                'table' => ($type === 'repeater' || $type === 'multiselect') && is_string($definition['table'] ?? null) ? $definition['table'] : null,
                'foreign_key' => ($type === 'repeater' || $type === 'multiselect') && is_string($definition['foreign_key'] ?? null) ? $definition['foreign_key'] : null,
                'repeater_fields' => $repeaterFields,
                // Multiselect seat keys (Table MultiSelect
                // picker): the child's link column, the
                // target slice options resolve from, and the
                // polymorphic parent type written onto child
                // rows when the child table carries one.
                'target_field' => $type === 'multiselect' && is_string($definition['target_field'] ?? null) ? $definition['target_field'] : null,
                'target' => $type === 'multiselect' && is_string($definition['target'] ?? null) ? $definition['target'] : null,
                'parent_type' => ($type === 'multiselect' || $type === 'repeater') && is_string($definition['parent_type'] ?? null) ? $definition['parent_type'] : null,
                // Repeater actions (D-020): declared grid-level
                // buttons the component renders beside Add —
                // built-in kinds only (clear; fill_weekday_dates
                // with from/to/weekday/date_field/label_field
                // naming the form + row fields they read/write).
                'actions' => $type === 'repeater' && is_array($definition['actions'] ?? null)
                    ? array_values(array_filter(array_map(static function ($action) {
                        if (! is_array($action) || ! is_string($action['kind'] ?? null)
                            || ! in_array($action['kind'], ['clear', 'fill_weekday_dates'], true)
                            || ! is_string($action['label'] ?? null)) {
                            return null;
                        }
                        $out = ['kind' => $action['kind'], 'label' => $action['label'], 'name' => is_string($action['name'] ?? null) ? $action['name'] : $action['kind']];
                        foreach (['from', 'to', 'weekday', 'date_field', 'label_field'] as $param) {
                            if (is_string($action[$param] ?? null)) {
                                $out[$param] = $action[$param];
                            }
                        }

                        return $out;
                    }, $definition['actions'])))
                    : [],
            ];
        }

        return array_values($definitions);
    }

    /**
     * A typed DTO property declaration whose default always matches its type.
     *
     * Built-in columns without a custom definition use $fallbackType / $fallbackDefault.
     */
    public function dtoProperty(string $name, ?array $field, string $fallbackType = '?string', mixed $fallbackDefault = null): string
    {
        if ($field === null) {
            return "public {$fallbackType} \${$name} = ".var_export($fallbackDefault, true).';';
        }

        $default = $field['default'] ?? null;
        [$type, $default] = match ($field['type']) {
            'boolean' => ['bool', (bool) $default],
            'integer', 'foreign_id', 'bigInteger' => ['?int', $default === null ? null : (int) $default],
            'decimal', 'float' => ['?float', $default === null ? null : (float) $default],
            default => [! $field['nullable'] && $default !== null ? 'string' : '?string', $default === null ? null : (string) $default],
        };

        if ($type === 'string' && $default === null) {
            $default = '';
        }

        return "public {$type} \${$name} = ".var_export($default, true).';';
    }

    /**
     * Attach the Seat 1 behaviour declarations to the
     * field's wrapper element as data attributes — the
     * shell's JS evaluator reads exactly this JSON and
     * mirrors Support\FieldCondition's semantics.
     * Fields without behaviour stay byte-identical.
     */
    private function withBehaviourAttributes(string $form, string $name, array $behaviour, ?string $fetchFrom): string
    {
        $attrs = '';
        foreach (['depends_on' => 'data-ls-depends-on', 'mandatory_depends_on' => 'data-ls-mandatory-on', 'read_only_depends_on' => 'data-ls-readonly-on'] as $key => $attr) {
            if (! empty($behaviour[$key])) {
                $attrs .= ' '.$attr.'="'.htmlspecialchars(json_encode($behaviour[$key]), ENT_QUOTES, 'UTF-8').'"';
            }
        }
        if ($fetchFrom !== null) {
            $attrs .= ' data-ls-fetch-from="'.$fetchFrom.'"';
        }
        if ($attrs === '') {
            return $form;
        }

        $tagged = preg_replace('/<div class="space-y-1"/', '<div class="space-y-1" data-ls-field="'.$name.'"'.$attrs, $form, 1) ?? $form;
        if ($tagged === $form) {
            // Boolean fields render in a flex row instead of
            // the space-y-1 stack — tag that container, or a
            // declared behaviour on a checkbox silently
            // produces no wrapper for the JS evaluator.
            $tagged = preg_replace('/<div class="flex items-center justify-between min-h-8"/', '<div class="flex items-center justify-between min-h-8" data-ls-field="'.$name.'"'.$attrs, $form, 1) ?? $form;
        }

        return $tagged;
    }

    private function renderFormField(string $name, string $label, string $type, bool $required, mixed $default, array $options, ?string $relation = null, ?string $optionsFrom = null, ?string $description = null): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $requiredHtml = $required ? ' required' : '';
        $requiredMark = $required ? ' *' : '';
        $defaultExpression = var_export($default, true);
        $valueExpression = "old('{$name}', \$form->{$name} ?? {$defaultExpression})";

        // Caption (D-066): a declared description prints under
        // the control. Datetime controls additionally carry the
        // app timezone — the source prints the zone under every
        // Datetime field because the value is understood in
        // that zone (D-059).
        $captionText = is_string($description) ? trim($description) : '';
        if ($type === 'datetime-local') {
            $tz = 'UTC';
            try {
                $configured = config('app.timezone');
                if (is_string($configured) && $configured !== '') {
                    $tz = $configured;
                }
            } catch (\Throwable) {
            }
            if (! str_contains($captionText, $tz)) {
                $captionText = $captionText === '' ? $tz : $captionText.' · '.$tz;
            }
        }
        $captionHtml = $captionText === ''
            ? ''
            : "\n                    <p class=\"text-[11px] leading-snug text-muted-foreground\">".htmlspecialchars($captionText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';

        // Dynamic option sources (Stage C-5): a plain select
        // fed by the controller-resolved {handle}Options var.
        // A slice_records field additionally carries the via
        // field's id; the shell's JS refills its options from
        // the options endpoint whenever the via field changes.
        if ($optionsFrom !== null) {
            $optionsVar = Str::camel($name).'Options';
            $dynamicAttr = '';
            if (str_starts_with($optionsFrom, 'slice_records:')) {
                $via = substr($optionsFrom, strlen('slice_records:'));
                $dynamicAttr = ' data-ls-dynamic-from="'.$via.'"';
            }

            return <<<BLADE
                <div class="space-y-1">
                    <x-ui.label for="{$name}" class="text-xs font-medium text-foreground/80">{$safeLabel}{$requiredMark}</x-ui.label>
                    <select id="{$name}" name="{$name}" class="flex h-8 w-full rounded-md border border-input bg-background px-2.5 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"{$requiredHtml}{$dynamicAttr}>
                        <option value="">Select {$safeLabel}...</option>
                        @foreach((\${$optionsVar} ?? []) as \$optValue => \$optLabel)
                            <option value="{{ \$optValue }}" @selected((string) {$valueExpression} === (string) \$optValue)>{{ \$optLabel }}</option>
                        @endforeach
                    </select>{$captionHtml}
                    @error('{$name}') <p class="text-xs text-destructive font-medium">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        // A declared relation makes ANY field a relationship
        // picker — including source columns that break the *_id
        // convention (award_to → users, project_admin → users):
        // the controller's options resolver synthesizes the
        // relation's conventional options var for exactly these
        // declarations, so the form must ask for it by that name.
        // The bare *_id suffix is NOT enough on its own: a
        // string-typed *_id is a human code, not a foreign key
        // (employee_details.employee_id stores 'EMP-12') — forcing
        // it into a picker leaves the stored code unmatched by any
        // option, so an untouched edit submits '' and wipes it
        // (Gate T A2d). Real string FKs declare their relation.
        $suffixPicker = str_ends_with($name, '_id') && ! in_array($type, ['text', 'string', 'textarea'], true);
        if ($type === 'select' || $type === 'foreign_id' || $suffixPicker || $relation !== null) {
            $baseRel = str_ends_with($name, '_id') ? Str::replaceLast('_id', '', $name) : ($relation ?? $name);
            $optionsVar = Str::camel(Str::plural($baseRel)).'Options';
            $quickStoreUrlVar = Str::camel(Str::plural($baseRel)).'QuickStoreUrl';
            $requiredBool = $required ? 'true' : 'false';
            $cleanTitle = str_ends_with($name, '_id') ? Str::headline($baseRel) : $safeLabel;

            if (empty($options) || str_ends_with($name, '_id') || $type === 'foreign_id' || $relation !== null) {
                return <<<BLADE
                <div class="space-y-1">
                    <x-ui.combobox-relationship
                        name="{$name}"
                        label="{$cleanTitle}"
                        :options="\${$optionsVar} ?? []"
                        :selected="{$valueExpression}"
                        placeholder="Select {$cleanTitle}..."
                        :quickAddUrl="\${$quickStoreUrlVar} ?? null"
                        quickAddTitle="{$cleanTitle}"
                        :required="{$requiredBool}"
                    />{$captionHtml}
                    @error('{$name}') <p class="text-xs text-destructive font-medium">{{ \$message }}</p> @enderror
                </div>
BLADE;
            }

            $optionHtml = '<option value="">Select '.$safeLabel.'...</option>';
            foreach ($options as $value => $optionLabel) {
                $displayLabel = ($optionLabel === $value) ? Str::headline($optionLabel) : $optionLabel;
                $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $safeOptionLabel = htmlspecialchars($displayLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $optionHtml .= "\n                    <option value=\"{$safeValue}\" @selected({$valueExpression} === '{$safeValue}')>{$safeOptionLabel}</option>";
            }

            return <<<BLADE
                <div class="space-y-1">
                    <x-ui.label for="{$name}" class="text-xs font-medium text-foreground/80">{$safeLabel}{$requiredMark}</x-ui.label>
                    <select id="{$name}" name="{$name}" class="flex h-8 w-full rounded-md border border-input bg-background px-2.5 py-1 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"{$requiredHtml}>
{$optionHtml}
                    </select>{$captionHtml}
                    @error('{$name}') <p class="text-xs text-destructive font-medium">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        if ($type === 'richtext') {
            return <<<BLADE
                <div class="space-y-1">
                    <x-ui.label for="{$name}" class="text-xs font-medium text-foreground/80">{$safeLabel}{$requiredMark}</x-ui.label>
                    <div data-ls-richtext>
                        <div class="flex flex-wrap items-center gap-0.5 rounded-t-md border border-input bg-muted/40 px-1.5 py-1" data-ls-richtext-toolbar>
                            <button type="button" data-ls-rt="bold" title="Bold" class="inline-flex size-7 items-center justify-center rounded text-xs font-bold text-foreground/80 hover:bg-muted">B</button>
                            <button type="button" data-ls-rt="italic" title="Italic" class="inline-flex size-7 items-center justify-center rounded text-xs italic text-foreground/80 hover:bg-muted">I</button>
                            <button type="button" data-ls-rt="underline" title="Underline" class="inline-flex size-7 items-center justify-center rounded text-xs underline text-foreground/80 hover:bg-muted">U</button>
                            <button type="button" data-ls-rt="insertUnorderedList" title="Bulleted list" class="inline-flex size-7 items-center justify-center rounded text-foreground/80 hover:bg-muted"><x-lucide-list class="size-3.5" /></button>
                            <button type="button" data-ls-rt="insertOrderedList" title="Numbered list" class="inline-flex size-7 items-center justify-center rounded text-foreground/80 hover:bg-muted"><x-lucide-list-ordered class="size-3.5" /></button>
                            <button type="button" data-ls-rt="createLink" title="Insert link" class="inline-flex size-7 items-center justify-center rounded text-foreground/80 hover:bg-muted"><x-lucide-link class="size-3.5" /></button>
                            <button type="button" data-ls-rt="removeFormat" title="Clear formatting" class="inline-flex size-7 items-center justify-center rounded text-foreground/80 hover:bg-muted"><x-lucide-eraser class="size-3.5" /></button>
                        </div>
                        <div contenteditable="true" data-ls-richtext-editor class="prose prose-sm min-h-28 w-full max-w-none rounded-b-md border border-t-0 border-input bg-background px-2.5 py-2 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">{!! old('{$name}', \$form->{$name} ?? '') !!}</div>
                        <textarea id="{$name}" name="{$name}" class="hidden" data-ls-richtext-input{{-- sync target: the editor's HTML lands here on input/submit --}}>{{ old('{$name}', \$form->{$name} ?? '') }}</textarea>
                    </div>{$captionHtml}
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        if ($type === 'code') {
            return <<<BLADE
                <div class="space-y-1">
                    <x-ui.label for="{$name}" class="text-xs font-medium text-foreground/80">{$safeLabel}{$requiredMark}</x-ui.label>
                    <x-ui.textarea id="{$name}" name="{$name}" rows="4" class="font-mono text-xs"{$requiredHtml}>{{ old('{$name}', \$form->{$name} ?? '') }}</x-ui.textarea>{$captionHtml}
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        if ($type === 'textarea') {
            return <<<BLADE
                <div class="space-y-1">
                    <x-ui.label for="{$name}" class="text-xs font-medium text-foreground/80">{$safeLabel}{$requiredMark}</x-ui.label>
                    <x-ui.textarea id="{$name}" name="{$name}" rows="3"{$requiredHtml}>{{ old('{$name}', \$form->{$name} ?? '') }}</x-ui.textarea>{$captionHtml}
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        if ($type === 'boolean') {
            if ($captionHtml !== '') {
                return <<<BLADE
                <div class="space-y-1">
                    <div class="flex items-center justify-between min-h-8">
                        <x-ui.label for="{$name}" class="text-xs font-medium text-foreground/80">{$safeLabel}</x-ui.label>
                        <input type="hidden" name="{$name}" value="0">
                        <input type="checkbox" id="{$name}" name="{$name}" value="1" @checked(old('{$name}', \$form->{$name} ?? false)) class="h-4 w-4 rounded border-gray-300 text-primary" />
                    </div>{$captionHtml}
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
            }

            return <<<BLADE
                <div class="flex items-center justify-between min-h-8">
                    <x-ui.label for="{$name}" class="text-xs font-medium text-foreground/80">{$safeLabel}</x-ui.label>
                    <input type="hidden" name="{$name}" value="0">
                    <input type="checkbox" id="{$name}" name="{$name}" value="1" @checked(old('{$name}', \$form->{$name} ?? false)) class="h-4 w-4 rounded border-gray-300 text-primary" />
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
        }

        // Date-family inputs accept only their own wire format
        // (Y-m-d / Y-m-d\TH:i): a stored datetime string rendered
        // raw is invalid, so the browser shows the field empty
        // and an untouched edit submits '' — silently wiping the
        // stored date (comparative Gate T A2d: employee joining
        // dates nulled by an unrelated edit). Format the value
        // exactly as BladeFieldInjector does.
        if ($type === 'date' || $type === 'datetime-local') {
            $format = $type === 'date' ? 'Y-m-d' : 'Y-m-d\\TH:i';
            $valueExpression = "old('{$name}', ! empty(\$form->{$name}) ? \\Illuminate\\Support\\Carbon::parse(\$form->{$name})->format('{$format}') : {$defaultExpression})";
        }

        $inputType = $type === 'decimal' ? 'number' : $type;
        $step = $type === 'decimal' ? ' step="0.01"' : '';

        return <<<BLADE
                <div class="space-y-1">
                    <x-ui.label for="{$name}" class="text-xs font-medium text-foreground/80">{$safeLabel}{$requiredMark}</x-ui.label>
                    <x-ui.input id="{$name}" size="sm" type="{$inputType}" name="{$name}" value="{{ {$valueExpression} }}"{$step}{$requiredHtml} />{$captionHtml}
                    @error('{$name}') <p class="text-xs text-destructive">{{ \$message }}</p> @enderror
                </div>
BLADE;
    }

    /**
     * Day-granularity field names: a bare `date`, `deadline`
     * or `dob`, or any *_date / *_on / *_till / *_birth name.
     * Time-of-day names (logged_at, clock_in_at, *_at) are
     * deliberately NOT date-domain — they keep their
     * datetime-local input.
     */
    public static function isDateDomainName(string $name): bool
    {
        return in_array($name, ['date', 'deadline', 'dob'], true)
            || preg_match('/(_date|_on|_till|_birth)$/', $name) === 1;
    }

    /**
     * Audit/system columns that exist for the record's
     * bookkeeping, never for a person to type: author stamps
     * (*_by), approval stamps, cross-system event links, and
     * server-generated public-link tokens (hash).
     * Mirrors the Body v2 system_fields rule.
     */
    public static function isSystemField(string $name): bool
    {
        // Audit stamps only, named explicitly: a bare *_by
        // catch-all false-hid business fields whose names
        // merely end that way (campaign_naming_by — a
        // settings choice; qualified_by — a Lead's qualifying
        // user). A porter can still hide any field
        // deliberately via show_in_form: false.
        // Nested-set bookkeeping (lft / rgt / old_parent) is
        // the same class: the tree machinery maintains those
        // columns itself in the source systems — a person
        // never types them into a form (D-065).
        return in_array($name, ['added_by', 'last_updated_by', 'created_by', 'updated_by', 'modified_by', 'deleted_by', 'approved_by', 'approved_at', 'event_id', 'ip_address', 'hash', 'lft', 'rgt', 'old_parent'], true)
            // Server-stamped network values (clock_in_ip,
            // clock_out_ip, login_ip, …): the server records
            // them (cf. the clock punch); a human never types
            // them into a form.
            || str_ends_with($name, '_ip');
    }
}
