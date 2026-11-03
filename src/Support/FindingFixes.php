<?php

namespace LaraSlice\Support;

/**
 * Fix hints for gate findings (R&D P1: gate output as agent
 * food). Every drift/conformance finding kind maps to the
 * concrete reconciliation an agent should attempt — always
 * at the declaration layer (Mind/Body/Heart), never by
 * editing generated output. Unknown kinds fall back to the
 * layer rule itself.
 */
final class FindingFixes
{
    private const BY_KIND = [
        'club-grant-missing' => 'Grant the missing permission to the role (slice:drift --fix reconciles club promises), or remove the promise from the Mind club if the role should not hold it.',
        'club-ungranted' => 'The club pattern promises a grant the role lacks: grant it (slice:drift --fix) or narrow the club pattern in the Mind.',
        'club-unmapped' => 'Map the club to real permission slugs in the Mind — the club vocabulary names nothing in the registry.',
        'club-pattern-unmatched' => 'Fix the club pattern in the Mind so it matches registry slugs, or rename the permissions it intends to cover.',
        'body-access-ungranted' => 'The Body declares access the role is not granted: grant the permission or relax the Body access declaration for that slice.',
        'body-permission-undeclared' => 'Declare the permission in the slice Heart (permissions list) so the grant has a declared home, then re-run slice:cache.',
        'role-empty' => 'The role holds no grants: carry or grant its permissions (a data port re-run restores source grants by name).',
        'scope-declared' => 'Reconcile the declared access.own_scope with the grants: either declare the ownership clauses on the slice or widen the grant scope.',
        'repeater-foreign-key' => 'Remove the bogus relation from the slice Heart (the child table has no foreign key back to the parent), regenerate the form with slice:regen-form, and excise the repeater from the generated service.',
        'visual' => 'Re-capture the port design probe, or fix the token at the design layer (kit/Soul) — never per-page CSS.',
        'presentation' => 'Align the Heart presentation declaration (create/edit/show modes) with the captured source behaviour.',
    ];

    private const FALLBACK = 'Reconcile at the declaration layer (Mind/Body/Heart) against the source of truth named in the message — never by editing generated output — then re-run the gate.';

    public static function forKind(?string $kind): string
    {
        if ($kind !== null && isset(self::BY_KIND[$kind])) {
            return self::BY_KIND[$kind];
        }
        if ($kind !== null) {
            foreach (self::BY_KIND as $prefix => $hint) {
                if (str_starts_with($kind, $prefix)) {
                    return $hint;
                }
            }
        }

        return self::FALLBACK;
    }

    /**
     * Hint for a prose gate line (conformance errors/opens are
     * "id: message" strings): visual and presentation ids get
     * their layer hints, everything else the register rule.
     */
    public static function forLine(string $line): string
    {
        if (preg_match('/^[a-z0-9-]+\.(visual|presentation):/', $line, $m)) {
            return self::BY_KIND[$m[1]];
        }
        if (str_contains($line, 'deviation')) {
            return 'Fix the page at the declaration layer, or record the deviation in porting/deviations.yaml with the owner\'s approval — unrecorded deviations fail the gate.';
        }

        return self::FALLBACK;
    }
}
