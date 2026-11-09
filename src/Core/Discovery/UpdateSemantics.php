<?php

declare(strict_types=1);

namespace LaraSlice\Core\Discovery;

/**
 * Update semantics (Proposal #6, ServiceStack R&D): how an
 * update write treats a partial request body.
 *
 * A slice's Heart declares the mode with a top-level key:
 *
 *     update: replace   # or: patch
 *
 *  - patch (the framework DEFAULT, and the behaviour every
 *    LaraSlice update has always had): an update writes only
 *    the fields the request actually provided; omitted fields
 *    keep their stored values.
 *  - replace: the request body IS the record's new state.
 *    An update that omits a writable declared field is
 *    rejected with a per-issue error naming what is missing
 *    (see BaseSliceService::assertReplaceComplete).
 *
 * Undeclared, empty or unrecognised values resolve to the
 * default — an existing install's behaviour never changes
 * because a new key exists. `declared` is true only when the
 * value IS one of the two modes: a typo'd or structured value
 * resolves to the default and is reported as UNDECLARED, so
 * the projection never dresses a non-declaration as one.
 * One resolver serves every consumer (the write path in
 * BaseSliceService and the metadata projection in
 * MetadataProjector) so the declared contract and the
 * enforced behaviour cannot drift apart.
 */
final class UpdateSemantics
{
    public const DEFAULT_MODE = 'patch';

    /**
     * @param  array<string, mixed>  $manifest  the slice's parsed Heart
     * @return array{mode: 'patch'|'replace', declared: bool}
     */
    public static function fromManifest(array $manifest): array
    {
        $raw = $manifest['update'] ?? null;
        $normalized = is_string($raw) ? strtolower(trim($raw)) : '';
        $declared = in_array($normalized, ['replace', 'patch'], true);
        $mode = $normalized === 'replace' ? 'replace' : 'patch';

        return ['mode' => $mode, 'declared' => $declared];
    }
}
