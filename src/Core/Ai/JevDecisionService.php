<?php

namespace LaraSlice\Core\Ai;

/**
 * Rule-based decision helpers for the blueprint studio: archetype classification,
 * migration risk scoring and component assignment.
 *
 * These are keyword and weight heuristics ("Choice", "Noul", "Score" primitives), not model
 * calls; confidence values are relative weights, not calibrated probabilities.
 */
class JevDecisionService
{
    /**
     * Jev Choice Primitive: Classify a feature/module description into a vertical slice archetype.
     *
     * @param  string  $description  Natural language domain requirements
     * @return array{archetype: string, confidence: float, distribution: array<string, float>, rationale: string}
     */
    public function classifyArchetype(string $description): array
    {
        $desc = strtolower($description);

        $scores = [
            'parent_child_aggregate' => 0.10,
            'independent_crud' => 0.10,
            'lookup_catalog' => 0.10,
            'workflow_ledger' => 0.10,
        ];

        // Semantic heuristics calibrated against domain patterns
        if (preg_match('/(ticket|item|child|line|task|comment|request|detail|has many|order|invoice)/i', $desc)) {
            $scores['parent_child_aggregate'] += 0.65;
        }
        if (preg_match('/(approval|workflow|transition|stage|state|step|ledger|audit|history)/i', $desc)) {
            $scores['workflow_ledger'] += 0.55;
        }
        if (preg_match('/(catalog|type|tag|category|lookup|status|reference|enum)/i', $desc)) {
            $scores['lookup_catalog'] += 0.50;
        }
        if (preg_match('/(simple|profile|single|standalone|flat)/i', $desc)) {
            $scores['independent_crud'] += 0.45;
        }

        // Normalize to probability distribution sum = 1.0
        $sum = array_sum($scores);
        $distribution = array_map(fn ($v) => round($v / $sum, 3), $scores);
        arsort($distribution);

        $chosen = array_key_first($distribution);
        $confidence = $distribution[$chosen];

        $rationale = match ($chosen) {
            'parent_child_aggregate' => 'Domain contains sub-entities and relational work items (e.g. parent-scoped child CRUD).',
            'workflow_ledger' => 'Domain requires multi-state transitions, audit history, and step-based lifecycles.',
            'lookup_catalog' => 'Domain functions as a reference taxonomy or configuration catalog.',
            default => 'Domain is an autonomous, standalone CRUD vertical slice.',
        };

        return [
            'archetype' => $chosen,
            'confidence' => $confidence,
            'distribution' => $distribution,
            'rationale' => $rationale,
        ];
    }

    /**
     * Jev Noul Primitive: Evaluate probability of data loss for a schema alteration.
     *
     * @param  array  $existingTables  Currently deployed database tables
     * @param  array  $proposedOperations  Planned database migration operations
     * @return array{data_loss_risk: bool, probability: float, warnings: array<string>}
     */
    public function evaluateMigrationRisk(array $existingTables, array $proposedOperations): array
    {
        $warnings = [];
        $riskScore = 0.0;

        foreach ($proposedOperations as $op) {
            $table = $op['table'] ?? '';
            $kind = $op['kind'] ?? '';

            if ($kind === 'drop_table' || $kind === 'drop_column') {
                $riskScore += 0.85;
                $warnings[] = "Operation will permanently destroy data in '{$table}'.";
            }

            if ($kind === 'create_table' && in_array($table, $existingTables, true)) {
                $riskScore += 0.50;
                $warnings[] = "Table '{$table}' already exists in the database. Generating without diff will fail or conflict.";
            }

            if ($kind === 'modify_column') {
                $riskScore += 0.35;
                $warnings[] = "Column modification in '{$table}' may truncate or cast incompatible types.";
            }
        }

        $probability = min(1.0, round($riskScore, 2));
        $dataLossRisk = $probability >= 0.20;

        return [
            'data_loss_risk' => $dataLossRisk,
            'probability' => $probability,
            'warnings' => $warnings,
        ];
    }

    /**
     * Jev Score Primitive: Recommend optimal BlatUI form component based on field characteristics.
     *
     * @param  string  $type  Field database/schema type
     * @param  array  $field  Field attributes (nullable, options, length, etc.)
     * @return array{component: string, score: int, rationale: string}
     */
    public function recommendComponent(string $type, array $field = []): array
    {
        $type = strtolower($type);
        $name = strtolower($field['name'] ?? $field['handle'] ?? '');

        if ($type === 'relation' || str_ends_with($name, '_id')) {
            return [
                'component' => 'x-ui.combobox-relationship',
                'score' => 6,
                'rationale' => 'Searchable asynchronous select with quick-add parent modal.',
            ];
        }

        if ($type === 'enum' || ! empty($field['options'])) {
            return [
                'component' => 'select',
                'score' => 4,
                'rationale' => 'Discrete options dropdown with selected state binding.',
            ];
        }

        if (in_array($type, ['text', 'mediumtext', 'longtext'], true)) {
            return [
                'component' => 'x-ui.textarea',
                'score' => 5,
                'rationale' => 'Multi-line flexible text area.',
            ];
        }

        if (in_array($type, ['boolean', 'bool'], true)) {
            return [
                'component' => 'x-ui.toggle',
                'score' => 3,
                'rationale' => 'Accessible inline toggle switch with hidden fallback.',
            ];
        }

        if (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
            return [
                'component' => 'x-ui.datepicker',
                'score' => 4,
                'rationale' => 'Native ISO date/datetime input control.',
            ];
        }

        return [
            'component' => 'x-ui.input',
            'score' => 2,
            'rationale' => 'Standard styled text input with validation binding.',
        ];
    }
}
