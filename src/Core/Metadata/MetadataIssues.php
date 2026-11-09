<?php

declare(strict_types=1);

namespace LaraSlice\Core\Metadata;

/**
 * The ladder surfaces' error shape (Phase B consumer contract,
 * from compilation §3.11): a rejected call returns the specific
 * issues in readable form — which field, what the problem is,
 * what was expected — so the caller, human or small model, can
 * correct and retry. No flat rejections on the schema,
 * explorer or MCP describe surfaces.
 *
 * (The Phase A describe endpoint keeps its documented
 * `{success: false, message}` 404 body — a verified contract;
 * the surfaces born in Phase B standardise on `issues`.)
 */
final class MetadataIssues
{
    /**
     * @return array{success: false, issues: array<int, array{field: string, problem: string, expected: string}>}
     */
    public static function unknownSlice(string $id): array
    {
        return [
            'success' => false,
            'issues' => [[
                'field' => 'slice',
                'problem' => "No slice matches '{$id}'.",
                'expected' => 'A slice id, name or handle listed by the metadata index (the ladder\'s first rung).',
            ]],
        ];
    }

    /**
     * One confirmation-gate rejection (Proposal #4): every
     * refusal on the two-phase write surfaces — bad signature,
     * expired, replayed, arguments altered, wrong user,
     * unknown proposal — is the same per-issue shape, so the
     * caller can correct and retry (start a fresh proposal).
     *
     * @return array{success: false, issues: array<int, array{field: string, problem: string, expected: string}>}
     */
    public static function confirmation(string $field, string $problem, string $expected): array
    {
        return [
            'success' => false,
            'issues' => [[
                'field' => $field,
                'problem' => $problem,
                'expected' => $expected,
            ]],
        ];
    }

    /**
     * A binding-layer rejection envelope (Proposal #6): the
     * issues were built where the failure was detected (the
     * form object's strict binding, or the service's replace
     * completeness check); this producer only wraps them in
     * the shared envelope so every write surface returns the
     * identical shape.
     *
     * @param  array<int, array{field: string, problem: string, expected: string}>  $issues
     * @return array{success: false, issues: array<int, array{field: string, problem: string, expected: string}>}
     */
    public static function binding(array $issues): array
    {
        return [
            'success' => false,
            'issues' => array_values($issues),
        ];
    }

    /**
     * @return array{success: false, issues: array<int, array{field: string, problem: string, expected: string}>}
     */
    public static function missingArgument(string $field, string $expected): array
    {
        return [
            'success' => false,
            'issues' => [[
                'field' => $field,
                'problem' => "The '{$field}' argument is required and was not given.",
                'expected' => $expected,
            ]],
        ];
    }
}
