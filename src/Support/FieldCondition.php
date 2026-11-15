<?php

namespace LaraSlice\Support;

/**
 * Field behaviour conditions (Seat 1, Stage D).
 *
 * The Heart declares a field's conditional behaviour as
 * DATA — a clause list any runtime can evaluate
 * identically (the JS evaluator in the shell mirrors
 * this class clause for clause). Frappe's eval strings
 * were deliberately not adopted: a universal framework
 * never stores one runtime's code inside a declaration.
 *
 * A clause names a sibling field and exactly one
 * operator: is / is_not / in / not_in / empty /
 * not_empty / gt / gte / lt / lte. Clauses combine
 * with AND. A declaration may instead be shaped
 * {any: [group, ...]} — an OR of clause groups, the
 * faithful form of the source expressions'
 * `a || b` shapes (each group is a clause or a clause
 * list, ANDed internally). An empty clause list is
 * "no condition" (always true for depends_on purposes
 * is the caller's choice; evaluate() of [] returns
 * true).
 *
 * Comparison operands (Phase 8, D-078): the ordered
 * operators (gt / gte / lt / lte) accept numbers, ISO
 * dates (YYYY-MM-DD, optionally with a time), and the
 * tokens '@today' / '@now', which evaluate() resolves
 * against the server clock — a date-aware condition
 * stays data, never one runtime's code. An operator
 * key may carry the suffix '_from' (is_from, gt_from,
 * ...) — the operand is then the VALUE OF THE NAMED
 * SIBLING FIELD instead of a literal (field-to-field
 * comparison). A clause field may name a collection
 * aggregate with a dotted path — '<repeater>.count'
 * or '<repeater>.<subfield>.count' — which the host
 * resolves into the value map (see
 * AppliesFieldBehaviour); evaluate() itself only ever
 * reads the map it is handed.
 */
class FieldCondition
{
    public const OPERATORS = ['is', 'is_not', 'in', 'not_in', 'empty', 'not_empty', 'gt', 'gte', 'lt', 'lte'];

    /**
     * Validate + normalize a declaration into a clause
     * list. Returns null when the declaration is absent;
     * throws InvalidArgumentException on malformed input
     * so generation fails loudly, never silently.
     *
     * @return array<int, array{field: string, op: string, value: mixed}>|null
     */
    public static function normalize(mixed $declaration, string $fieldName = ''): ?array
    {
        if ($declaration === null) {
            return null;
        }
        // A single clause map is sugar for a one-item list.
        if (is_array($declaration) && isset($declaration['field'])) {
            $declaration = [$declaration];
        }
        if (! is_array($declaration) || $declaration === []) {
            throw new \InvalidArgumentException("Field '{$fieldName}' has a malformed behaviour condition (expected a clause or a list of clauses).");
        }

        $clauses = [];
        foreach ($declaration as $clause) {
            if (! is_array($clause) || ! isset($clause['field']) || ! is_string($clause['field'])
                || ! preg_match('/^[a-z][a-z0-9_]{0,63}(\.[a-z][a-z0-9_]{0,63}){0,2}$/', $clause['field'])) {
                throw new \InvalidArgumentException("Field '{$fieldName}' has a behaviour clause without a valid 'field'.");
            }
            // An already-normalized clause ({field, op,
            // value}) passes through — normalization is
            // idempotent, so generated manifests carrying
            // normalized groups re-validate cleanly.
            if (isset($clause['op']) && in_array($clause['op'], self::OPERATORS, true) && array_key_exists('value', $clause)) {
                $normalized = ['field' => $clause['field'], 'op' => $clause['op'], 'value' => $clause['value']];
                if (isset($clause['value_from']) && is_string($clause['value_from'])) {
                    $normalized['value_from'] = $clause['value_from'];
                }
                $clauses[] = $normalized;
                continue;
            }
            // The '_from' sugar: an operator key ending in
            // '_from' takes the name of a sibling field as
            // its operand (field-to-field comparison).
            $valueFrom = null;
            foreach (array_keys($clause) as $key) {
                if (is_string($key) && str_ends_with($key, '_from')
                    && in_array(substr($key, 0, -5), self::OPERATORS, true)) {
                    $valueFrom = (string) $clause[$key];
                    $clause[substr($key, 0, -5)] = true;
                    unset($clause[$key]);
                    break;
                }
            }
            $ops = array_values(array_intersect(self::OPERATORS, array_keys($clause)));
            if (count($ops) !== 1) {
                throw new \InvalidArgumentException("Field '{$fieldName}' behaviour clause for '{$clause['field']}' must carry exactly one operator (".implode(', ', self::OPERATORS).').');
            }
            $op = $ops[0];
            $value = $clause[$op];
            if ($valueFrom !== null) {
                if (! preg_match('/^[a-z][a-z0-9_]{0,63}(\.[a-z][a-z0-9_]{0,63}){0,2}$/', $valueFrom)) {
                    throw new \InvalidArgumentException("Field '{$fieldName}' behaviour clause '{$op}_from' expects a sibling field name.");
                }
                $clauses[] = ['field' => $clause['field'], 'op' => $op, 'value' => null, 'value_from' => $valueFrom];
                continue;
            }
            if (in_array($op, ['in', 'not_in'], true) && ! is_array($value)) {
                throw new \InvalidArgumentException("Field '{$fieldName}' behaviour clause '{$op}' expects a list of values.");
            }
            if (in_array($op, ['empty', 'not_empty'], true) && $value !== true) {
                throw new \InvalidArgumentException("Field '{$fieldName}' behaviour clause '{$op}' expects true.");
            }
            if (in_array($op, ['gt', 'gte', 'lt', 'lte'], true) && ! self::isOrderedOperand($value)) {
                throw new \InvalidArgumentException("Field '{$fieldName}' behaviour clause '{$op}' expects a number, an ISO date, or the @today / @now tokens.");
            }
            $clauses[] = ['field' => $clause['field'], 'op' => $op, 'value' => $value];
        }

        return $clauses;
    }

    /**
     * Evaluate a normalized clause list against a value
     * map (field handle => submitted/stored value).
     */
    public static function evaluate(?array $clauses, array $values): bool
    {
        if ($clauses === null || $clauses === []) {
            return true;
        }
        foreach ($clauses as $clause) {
            $actual = $values[$clause['field']] ?? null;
            $expected = isset($clause['value_from'])
                ? ($values[(string) $clause['value_from']] ?? null)
                : self::resolveToken($clause['value'] ?? null);
            $ok = match ($clause['op']) {
                'is' => self::looseEquals($actual, $expected),
                'is_not' => ! self::looseEquals($actual, $expected),
                'in' => is_array($expected) && self::looseIn($actual, $expected),
                'not_in' => ! (is_array($expected) && self::looseIn($actual, $expected)),
                // Emptiness follows the source systems'
                // truthiness: an unchecked Check (false / 0)
                // is empty, exactly like null / '' / []. A
                // submitted string '0' is a real choice and
                // stays non-empty. The JS mirror in
                // blatui-core.js implements the same table.
                'empty' => $actual === null || $actual === '' || $actual === [] || $actual === false || $actual === 0 || $actual === 0.0,
                'not_empty' => ! ($actual === null || $actual === '' || $actual === [] || $actual === false || $actual === 0 || $actual === 0.0),
                'gt' => self::compareOrdered($actual, $expected) === 1,
                'gte' => in_array(self::compareOrdered($actual, $expected), [0, 1], true),
                'lt' => self::compareOrdered($actual, $expected) === -1,
                'lte' => in_array(self::compareOrdered($actual, $expected), [-1, 0], true),
                default => false,
            };
            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize a declaration into GROUPS of clauses:
     * a plain clause / clause list becomes one group;
     * {any: [...]} becomes one group per entry. The
     * condition holds when ANY group holds.
     *
     * @return array<int, array<int, array{field: string, op: string, value: mixed}>>|null
     */
    public static function normalizeGroups(mixed $declaration, string $fieldName = ''): ?array
    {
        if ($declaration === null) {
            return null;
        }
        if (is_array($declaration) && isset($declaration['any']) && is_array($declaration['any'])) {
            if ($declaration['any'] === []) {
                throw new \InvalidArgumentException("Field '{$fieldName}' has an empty 'any' behaviour condition.");
            }
            $groups = [];
            foreach ($declaration['any'] as $entry) {
                $groups[] = self::normalize($entry, $fieldName) ?? [];
            }

            return $groups;
        }
        $single = self::normalize($declaration, $fieldName);

        return $single === null ? null : [$single];
    }

    /** Evaluate normalized groups: true when any group fully holds. */
    public static function evaluateGroups(?array $groups, array $values): bool
    {
        if ($groups === null || $groups === []) {
            return true;
        }
        foreach ($groups as $clauses) {
            if (self::evaluate($clauses, $values)) {
                return true;
            }
        }

        return false;
    }

    /** An ordered-comparison operand: a number, an ISO date, or a clock token. */
    private static function isOrderedOperand(mixed $value): bool
    {
        return is_numeric($value)
            || $value === '@today' || $value === '@now'
            || (is_string($value) && self::isDateOperand($value));
    }

    private static function isDateOperand(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', $value);
    }

    /** Resolve the clock tokens a declaration may use as an operand. */
    private static function resolveToken(mixed $value): mixed
    {
        return match ($value) {
            '@today' => date('Y-m-d'),
            '@now' => date('Y-m-d H:i:s'),
            default => $value,
        };
    }

    /**
     * Three-way ordered comparison over numbers and ISO
     * dates (lexicographic order IS chronological order
     * for the ISO shape). Null when the pair is not
     * comparable (a missing date never orders).
     */
    private static function compareOrdered(mixed $actual, mixed $expected): ?int
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return (float) $actual <=> (float) $expected;
        }
        if (is_string($actual) && is_string($expected)
            && self::isDateOperand($actual) && self::isDateOperand($expected)) {
            return strcmp($actual, $expected) <=> 0;
        }

        return null;
    }

    /** Form values arrive as strings; declarations may hold typed scalars. */
    private static function looseEquals(mixed $actual, mixed $expected): bool
    {
        if (is_bool($expected)) {
            return (bool) $actual === $expected;
        }
        if ($actual === null || $expected === null) {
            return $actual === $expected;
        }

        return (string) $actual === (string) $expected;
    }

    private static function looseIn(mixed $actual, array $expected): bool
    {
        foreach ($expected as $candidate) {
            if (self::looseEquals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
