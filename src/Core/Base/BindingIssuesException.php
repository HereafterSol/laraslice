<?php

declare(strict_types=1);

namespace LaraSlice\Core\Base;

use RuntimeException;

/**
 * A slice write was rejected at the binding layer
 * (Proposal #6): the request carried values the form object
 * could not bind to the declared property types, or — under
 * a Heart's declared `update: replace` semantics — the
 * update body omitted writable fields.
 *
 * The exception carries the per-issue list in the shared
 * issue shape ({field, problem, expected} — the
 * MetadataIssues envelope), so every surface renders the
 * same self-repair errors: JSON callers receive the envelope
 * verbatim; HTML form posts receive the same issues as
 * field-keyed validation messages.
 */
class BindingIssuesException extends RuntimeException
{
    /**
     * @param  array<int, array{field: string, problem: string, expected: string}>  $issues
     */
    public function __construct(private readonly array $issues)
    {
        $first = $issues[0]['problem'] ?? 'The submitted data could not be bound.';

        parent::__construct($first.' ('.count($issues).' binding issue(s))');
    }

    /**
     * @return array<int, array{field: string, problem: string, expected: string}>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * The same issues as a Laravel field => messages map, for
     * surfaces whose native error rendering is the validation
     * error bag (classic HTML form posts).
     *
     * @return array<string, array<int, string>>
     */
    public function validationMessages(): array
    {
        $messages = [];
        foreach ($this->issues as $issue) {
            $messages[$issue['field']][] = $issue['problem'].' Expected: '.$issue['expected'];
        }

        return $messages;
    }
}
