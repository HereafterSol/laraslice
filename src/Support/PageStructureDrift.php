<?php

declare(strict_types=1);

namespace LaraSlice\Support;

/**
 * Page Structure Contract (Body v4) drift audit: compares a
 * generated list blade (and the app's published data-table)
 * against the declarations that are supposed to govern it.
 *
 * The anti-drift law: generated pages emit ONLY declared
 * regions/controls. This audit is the enforcement half — it
 * flags the historical offenders (unguarded Seed chrome,
 * placement declarations that cannot take effect because the
 * blade predates dual emission, invalid placement values the
 * resolver would silently swallow) so structure drift is
 * caught by a command, not by a user's eyes.
 *
 * Findings use the SystemDrift shape: kind / severity / message.
 */
class PageStructureDrift
{
    /**
     * @param  array<string, mixed>  $ui  The slice manifest's `ui` array.
     * @return list<array{kind: string, severity: string, message: string}>
     */
    public static function auditBlade(string $blade, array $ui, string $label): array
    {
        $findings = [];

        // Invalid placement: the resolver falls back silently;
        // the audit must not.
        $declared = $ui['pages']['list']['placement'] ?? $ui['directory']['placement'] ?? null;
        if ($declared !== null && ! in_array($declared, DirectoryUi::LIST_PLACEMENTS, true)) {
            $findings[] = self::finding('structure.invalid-placement', 'error',
                "{$label}: placement '{$declared}' is outside the Body v4 vocabulary ("
                .implode(', ', DirectoryUi::LIST_PLACEMENTS).'); the page silently renders below-facets-left.');
        }

        // Unguarded Seed chrome in the actions cluster: Seed is
        // declaration-gated (ui.directory.demo_seed, default
        // false) and band-only. A Seed Demo control whose markup
        // is not guarded by $demoSeed renders undeclared chrome.
        if (str_contains($blade, 'Seed Demo') && ($ui['directory']['demo_seed'] ?? false) !== true) {
            $unguarded = false;
            $offset = 0;
            while (($pos = strpos($blade, 'Seed Demo', $offset)) !== false) {
                // Guarded = the nearest preceding @if mentions
                // $demoSeed in its condition and has not been
                // closed by an @endif before this occurrence.
                // (A seed guarded only by $can['seed'] renders by
                // default — that IS the violation being caught.)
                $before = substr($blade, 0, $pos);
                $ifPos = strrpos($before, '@if');
                $guarded = false;
                if ($ifPos !== false) {
                    $span = substr($blade, $ifPos, $pos - $ifPos);
                    $guarded = str_contains($span, '$demoSeed') && ! str_contains($span, '@endif');
                }
                if (! $guarded) {
                    $unguarded = true;
                    break;
                }
                $offset = $pos + 1;
            }
            if ($unguarded) {
                $findings[] = self::finding('structure.undeclared-seed', 'error',
                    "{$label}: a Seed Demo control renders without the demo_seed declaration (ui.directory.demo_seed) — undeclared chrome in the actions cluster.");
            }
        }

        // Version pill in the page header: the title region
        // holds the title (and breadcrumb) only — a version
        // badge is kit/demo chrome, never a declaration.
        if (preg_match('/<x-ui\.badge[^>]*>\s*v\d+\.\d+\.\d+\s*<\/x-ui\.badge/', $blade)) {
            $findings[] = self::finding('structure.version-pill', 'error',
                "{$label}: a version badge renders in the page header — the title region holds the title only (Body v4). Remove it or declare the region that owns it.");
        }

        // Placement readiness of the blade itself.
        if (str_contains($blade, '<x-slot:below-toolbar>')) {
            if (! str_contains($blade, "=== 'header-right'")) {
                $findings[] = self::finding('structure.placement-not-dual', 'warning',
                    "{$label}: index blade predates placement dual emission — declared placements cannot take effect. Run slice:repair (rule ag).");
            } elseif (! str_contains($blade, "=== 'command-bar'")) {
                $findings[] = self::finding('structure.placement-not-quad', 'warning',
                    "{$label}: index blade is dual-emitted only — command-bar and toolbar-left placements degrade to the band. Run slice:repair (rule ah).");
            }
        }

        return $findings;
    }

    /**
     * Detail-page audit (Body v4, detail regions): a Heart that
     * declares relation tabs — directly in ui.show.tabs or
     * implicitly via models[0] hasMany relations — promises a
     * hub; a show blade without the related-tab loop renders
     * none of them. The hub is hollow: declared structure that
     * can never appear. (Projects + Work/Tasks shipped this
     * way until P4; this check is the class guard.)
     *
     * @param  array<string, mixed>  $manifest  The full slice manifest.
     * @return list<array{kind: string, severity: string, message: string}>
     */
    public static function auditShowBlade(string $blade, array $manifest, string $label): array
    {
        $relations = [];
        foreach (($manifest['ui']['show']['tabs'] ?? []) as $tab) {
            if (is_array($tab) && ($tab['type'] ?? '') === 'relation') {
                $relations[] = (string) ($tab['relation'] ?? $tab['handle'] ?? '');
            }
        }
        $root = $manifest['models'][0] ?? null;
        if (is_array($root)) {
            foreach (($root['relations'] ?? []) as $rel) {
                if (is_array($rel) && ($rel['type'] ?? '') === 'hasMany') {
                    $relations[] = (string) ($rel['name'] ?? '');
                }
            }
        }
        $relations = array_values(array_unique(array_filter($relations)));
        if ($relations === [] || str_contains($blade, 'relatedTabs')) {
            return [];
        }

        return [self::finding('structure.hollow-hub', 'error',
            "{$label}: the Heart promises ".count($relations).' relation tab(s) ('.implode(', ', $relations)
            .') but the show blade never renders the related-tab loop — the declared detail tabs are invisible. Regenerate or upgrade the show blade to the hub standard.')];
    }

    /**
     * The published data-table must not render an empty actions
     * band (it gates the band on the slot being non-empty).
     *
     * @return list<array{kind: string, severity: string, message: string}>
     */
    public static function auditComponent(string $componentBlade): array
    {
        if (! str_contains($componentBlade, '$belowToolbar->isNotEmpty()')) {
            return [self::finding('structure.empty-band-risk', 'warning',
                'data-table: the below-toolbar band renders on @isset alone — a page whose placement moves its actions elsewhere would show an empty band strip. Publish the current component (slice:repair --publish-ui).')];
        }

        return [];
    }

    /** @return array{kind: string, severity: string, message: string} */
    private static function finding(string $kind, string $severity, string $message): array
    {
        return ['kind' => $kind, 'severity' => $severity, 'message' => $message];
    }
}
