<?php

declare(strict_types=1);

namespace LaraSlice\Support;

/**
 * The App Flow artefact, GENERATED from declarations — never drawn
 * by hand: journey lanes come from the Mind (system.yaml journeys),
 * the navigation map from the slices' own navigation declarations
 * (the Body). A journey step is linked to a slice when its text
 * names the slice's label (normalized containment, either way) —
 * a heuristic, labelled as such on the artefact itself; unlinked
 * steps render as plain declared steps.
 */
class AppFlow
{
    /**
     * @param  array<string, mixed>|null  $mind
     * @param  list<array{label: string, url: ?string, section: string}>  $pages
     * @return array{system: string, sections: array<string, list<array{label: string, url: ?string}>>, journeys: list<array{name: string, actor: string, outcome: string, steps: list<array{text: string, slice: ?string}>}>}
     */
    /**
     * The navigation page map from slice manifests — the Body
     * side of the flow. Shared by slice:flow and the Studio's
     * Plan impact view so both read the same surface.
     *
     * @param  iterable<object>  $slices  SliceManifest-like (raw, name)
     * @return list<array{label: string, url: ?string, section: string}>
     */
    public static function pagesFromSlices(iterable $slices): array
    {
        $pages = [];
        foreach ($slices as $slice) {
            $nav = (array) ($slice->raw['navigation'] ?? []);
            if (($nav['visible'] ?? true) === false) {
                continue;
            }
            $label = (string) ($nav['label'] ?? $slice->raw['title'] ?? $slice->name);
            $url = isset($nav['url']) ? (string) $nav['url'] : null;
            $section = (string) ($nav['section'] ?? $nav['group'] ?? '');
            if ($section === '') {
                $segments = array_values(array_filter(explode('/', (string) $url)));
                $section = count($segments) >= 2
                    ? ucwords(str_replace(['-', '_'], ' ', $segments[count($segments) - 2]))
                    : 'General';
            }
            $pages[] = ['label' => $label, 'url' => $url, 'section' => $section];
        }

        return $pages;
    }

    public static function build(?array $mind, array $pages): array
    {
        $sections = [];
        foreach ($pages as $page) {
            $sections[$page['section']][] = ['label' => $page['label'], 'url' => $page['url'] ?? null];
        }
        foreach ($sections as &$list) {
            usort($list, fn ($a, $b) => strcmp($a['label'], $b['label']));
        }
        unset($list);
        ksort($sections);

        $labels = array_map(fn ($p) => $p['label'], $pages);
        $journeys = [];
        foreach ((array) ($mind['journeys'] ?? []) as $journey) {
            if (! is_array($journey)) {
                continue;
            }
            $steps = [];
            foreach ((array) ($journey['steps'] ?? []) as $step) {
                $text = (string) $step;
                $steps[] = ['text' => $text, 'slice' => self::matchSlice($text, $labels)];
            }
            $journeys[] = [
                'name' => (string) ($journey['name'] ?? 'Journey'),
                'actor' => (string) ($journey['actor'] ?? ''),
                'outcome' => (string) ($journey['outcome'] ?? ''),
                'steps' => $steps,
            ];
        }

        return [
            'system' => self::systemName($mind),
            'sections' => $sections,
            'journeys' => $journeys,
        ];
    }

    /** @param array<string, mixed>|null $mind */
    private static function systemName(?array $mind): string
    {
        if (! empty($mind['system']['name'])) {
            return (string) $mind['system']['name'];
        }
        try {
            return (string) (config('app.name') ?: 'System');
        } catch (\Throwable) {
            return 'System'; // no container (unit context) — the Mind names the system anyway
        }
    }

    /** @param list<string> $labels */
    private static function matchSlice(string $step, array $labels): ?string
    {
        $needle = self::normalize($step);
        if ($needle === '') {
            return null;
        }
        foreach ($labels as $label) {
            $hay = self::normalize($label);
            if ($hay !== '' && (str_contains($needle, $hay) || str_contains($hay, $needle))) {
                return $label;
            }
        }

        return null;
    }

    private static function normalize(string $value): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
    }

    /** @param array<string, mixed> $flow */
    public static function renderMermaid(array $flow): string
    {
        $lines = ['flowchart LR'];
        $id = 0;
        foreach ($flow['journeys'] as $journey) {
            $prev = null;
            foreach ($journey['steps'] as $step) {
                $node = 'n'.(++$id);
                $label = str_replace('"', "'", $step['text']);
                $lines[] = "    {$node}[\"{$label}\"]";
                if ($prev !== null) {
                    $lines[] = "    {$prev} --> {$node}";
                }
                $prev = $node;
            }
        }
        foreach ($flow['sections'] as $section => $pages) {
            $secId = 's'.(++$id);
            $lines[] = "    {$secId}[\"".str_replace('"', "'", $section).'"]';
            foreach ($pages as $page) {
                $node = 'n'.(++$id);
                $lines[] = "    {$node}[\"".str_replace('"', "'", $page['label'])."\"]";
                $lines[] = "    {$secId} --> {$node}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @param array<string, mixed> $flow */
    public static function renderHtml(array $flow): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $system = $e($flow['system']);

        $journeyHtml = '';
        foreach ($flow['journeys'] as $journey) {
            $chips = '';
            foreach ($journey['steps'] as $i => $step) {
                if ($i > 0) {
                    $chips .= '<span class="arrow">→</span>';
                }
                $cls = $step['slice'] !== null ? 'step linked' : 'step';
                $title = $step['slice'] !== null ? ' title="Opens: '.$e($step['slice']).'"' : '';
                $chips .= '<span class="'.$cls.'"'.$title.'>'.$e($step['text']).'</span>';
            }
            $actor = $journey['actor'] !== '' ? '<span class="actor">'.$e($journey['actor']).'</span>' : '';
            $outcome = $journey['outcome'] !== '' ? '<div class="outcome">Outcome: '.$e($journey['outcome']).'</div>' : '';
            $journeyHtml .= '<section class="lane"><h3>'.$e($journey['name']).' '.$actor.'</h3><div class="chips">'.$chips.'</div>'.$outcome.'</section>';
        }
        if ($journeyHtml === '') {
            $journeyHtml = '<p class="empty">No journeys declared in the Mind yet — draft them in Plan mode.</p>';
        }

        $mapHtml = '';
        foreach ($flow['sections'] as $section => $pages) {
            $items = '';
            foreach ($pages as $page) {
                $items .= '<li>'.$e($page['label']).'</li>';
            }
            $mapHtml .= '<section class="card"><h3>'.$e($section).'</h3><ul>'.$items.'</ul></section>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>App Flow — {$system}</title>
<style>
body{font-family:ui-sans-serif,system-ui,sans-serif;margin:2rem auto;max-width:72rem;padding:0 1rem;color:#111827;background:#f9fafb}
h1{font-size:1.5rem}h2{margin-top:2rem;font-size:1.1rem;text-transform:uppercase;letter-spacing:.05em;color:#374151}
.lane,.card{background:#fff;border:1px solid #e5e7eb;border-radius:.75rem;padding:1rem 1.25rem;margin:.75rem 0}
.chips{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-top:.5rem}
.step{border:1px solid #d1d5db;border-radius:999px;padding:.3rem .8rem;font-size:.85rem;background:#f3f4f6}
.step.linked{border-color:#6366f1;background:#eef2ff}
.arrow{color:#9ca3af}.actor{font-size:.75rem;background:#111827;color:#fff;border-radius:999px;padding:.15rem .6rem;vertical-align:middle}
.outcome{margin-top:.6rem;font-size:.85rem;color:#065f46}.empty{color:#6b7280}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:.75rem}
.card ul{margin:.5rem 0 0;padding-left:1.1rem;font-size:.9rem}
footer{margin-top:2rem;font-size:.75rem;color:#6b7280}
</style></head><body>
<h1>App Flow — {$system}</h1>
<p>Generated by <code>slice:flow</code> from the Mind's journeys and the slices' navigation declarations. Highlighted steps matched a slice label by name; the rest are declared steps awaiting a surface.</p>
<h2>Journeys</h2>
{$journeyHtml}
<h2>Navigation map</h2>
<div class="grid">{$mapHtml}</div>
<footer>Source of truth: system.yaml (Mind) + slice.yaml navigation (Body). Regenerate after either changes; do not edit this file by hand.</footer>
</body></html>
HTML;
    }
}
