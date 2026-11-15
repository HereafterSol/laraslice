<?php

declare(strict_types=1);

namespace LaraSlice\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Benchmark gate (owner-approved 13 Nov 2026; Craft
 * Anything lessons): a port surface is graded against a
 * spec written from the SOURCE system, never against
 * taste. The spec names elements per surface; evidence
 * is walkthrough DOM captures (HTML files named
 * `<surface>-<width>.html`). Scores split into SURFACE
 * columns (one per surface) and a BEHAVIOUR column
 * (declarations present in the slice Heart) — the two
 * are graded separately because a visually perfect port
 * can still carry zero behaviour.
 *
 * Honesty rules, enforced by the scorer:
 * - a surface with no captures is NOT-CHECKED, and the
 *   gate verdict is FAIL (no passing on no evidence);
 * - an element is checked only against visible text
 *   (scripts/styles stripped), so markup noise cannot
 *   fake a pass;
 * - behaviour elements are declaration-presence checks
 *   (does the Heart declare it) — live proofs remain a
 *   separate gold standard and the scorecard says so.
 */
class BenchmarkGate
{
    /**
     * @return array{slice:string,threshold:float,surfaces:array<string,array<int,array>>,behaviour:array<int,array>}
     */
    public static function loadSpec(string $path): array
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException("Benchmark spec not found: {$path}");
        }
        $spec = Yaml::parseFile($path);
        if (! is_array($spec) || empty($spec['slice']) || ! is_array($spec['surfaces'] ?? null)) {
            throw new \InvalidArgumentException("Benchmark spec {$path} must declare 'slice' and 'surfaces'.");
        }
        $surfaces = [];
        foreach ($spec['surfaces'] as $surface => $def) {
            $elements = [];
            foreach ((array) ($def['elements'] ?? []) as $i => $el) {
                if (! is_array($el) || empty($el['label']) || (empty($el['find']) && empty($el['find_all']) && empty($el['find_absent']))) {
                    throw new \InvalidArgumentException("Spec surface '{$surface}' element #{$i} needs a label and a find / find_all / find_absent.");
                }
                $elements[] = [
                    'label' => (string) $el['label'],
                    'find' => isset($el['find']) ? (string) $el['find'] : null,
                    'find_all' => array_values(array_map('strval', (array) ($el['find_all'] ?? []))),
                    'find_absent' => isset($el['find_absent']) ? (string) $el['find_absent'] : null,
                    'weight' => max(1, (int) ($el['weight'] ?? 1)),
                ];
            }
            $surfaces[(string) $surface] = $elements;
        }
        $behaviour = [];
        foreach ((array) ($spec['behaviour'] ?? []) as $i => $el) {
            if (! is_array($el) || empty($el['label']) || empty($el['declaration'])) {
                throw new \InvalidArgumentException("Spec behaviour element #{$i} needs a label and a declaration path.");
            }
            $behaviour[] = [
                'label' => (string) $el['label'],
                'declaration' => (string) $el['declaration'],
                'weight' => max(1, (int) ($el['weight'] ?? 1)),
            ];
        }

        return [
            'slice' => (string) $spec['slice'],
            'threshold' => (float) ($spec['threshold'] ?? 90),
            'surfaces' => $surfaces,
            'behaviour' => $behaviour,
        ];
    }

    /**
     * Score a spec against an evidence directory and,
     * for the behaviour column, a slice Heart file.
     *
     * @return array<string,mixed>
     */
    public static function score(array $spec, string $evidenceDir, ?string $heartPath = null): array
    {
        $columns = [];
        $notChecked = [];
        $totalWeight = 0;
        $totalEarned = 0.0;

        foreach ($spec['surfaces'] as $surface => $elements) {
            $captures = glob(rtrim($evidenceDir, '/').'/'.$surface.'-*.html') ?: [];
            if ($captures === []) {
                $notChecked[] = "surface '{$surface}' (no captures in {$evidenceDir})";
                $columns[$surface] = ['score' => null, 'widths' => [], 'elements' => [], 'not_checked' => true];
                foreach ($elements as $el) {
                    $totalWeight += $el['weight'];
                }
                continue;
            }
            $widths = [];
            $text = '';
            foreach ($captures as $file) {
                if (preg_match('/-(\d+)\.html$/', $file, $m)) {
                    $widths[] = (int) $m[1];
                }
                $text .= "\n".self::visibleText((string) file_get_contents($file));
            }
            sort($widths);
            [$earned, $weight, $results] = self::scoreElements($elements, static fn (array $el): bool => self::foundIn($el, $text));
            $columns[$surface] = ['score' => $weight > 0 ? round($earned / $weight * 100, 1) : null, 'widths' => $widths, 'elements' => $results, 'not_checked' => false];
            $totalWeight += $weight;
            $totalEarned += $earned;
        }

        if ($spec['behaviour'] !== []) {
            if ($heartPath === null || ! is_file($heartPath)) {
                $notChecked[] = "behaviour column (no Heart file given)";
                $columns['behaviour'] = ['score' => null, 'elements' => [], 'not_checked' => true];
                foreach ($spec['behaviour'] as $el) {
                    $totalWeight += $el['weight'];
                }
            } else {
                $heart = Yaml::parseFile($heartPath);
                [$earned, $weight, $results] = self::scoreElements($spec['behaviour'], static fn (array $el): bool => self::declarationPresent(is_array($heart) ? $heart : [], $el['declaration']));
                $columns['behaviour'] = ['score' => $weight > 0 ? round($earned / $weight * 100, 1) : null, 'elements' => $results, 'not_checked' => false];
                $totalWeight += $weight;
                $totalEarned += $earned;
            }
        }

        $overall = $totalWeight > 0 ? round($totalEarned / $totalWeight * 100, 1) : 0.0;
        $pass = $notChecked === [] && $overall >= $spec['threshold'];

        return [
            'slice' => $spec['slice'],
            'threshold' => $spec['threshold'],
            'overall' => $overall,
            'verdict' => $pass ? 'PASS' : 'FAIL',
            'columns' => $columns,
            'not_checked' => $notChecked,
        ];
    }

    /**
     * Visible text of an HTML capture: script/style/template
     * subtrees removed with a real parser, then the document's
     * text with whitespace collapsed. (The previous regex
     * script-strip mispaired <script> tags appearing inside JS
     * string payloads — drawer-presentation pages embed the
     * form HTML as a JS string — leaving live Alpine JS in the
     * "text", whose '<' comparisons then made strip_tags eat
     * real page regions, e.g. the record workplace rail.)
     */
    public static function visibleText(string $html): string
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($prev);
        foreach (['script', 'style', 'template', 'noscript'] as $tag) {
            $nodes = $doc->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node !== null && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }
        $text = html_entity_decode($doc->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function foundIn(array $element, string $text): bool
    {
        if (($element['find_absent'] ?? null) !== null && $element['find_absent'] !== '') {
            return ! str_contains($text, $element['find_absent']);
        }
        if ($element['find'] !== null && $element['find'] !== '') {
            return str_contains($text, $element['find']);
        }
        foreach ($element['find_all'] as $needle) {
            if (! str_contains($text, $needle)) {
                return false;
            }
        }

        return $element['find_all'] !== [];
    }

    /** Resolve a declaration path like fields.lead_name.computed or slice.autoname against the Heart. */
    private static function declarationPresent(array $heart, string $path): bool
    {
        $segments = explode('.', $path);
        if (($segments[0] ?? null) === 'slice') {
            array_shift($segments);
            $node = $heart;
        } elseif (($segments[0] ?? null) === 'fields' && count($segments) >= 3) {
            $field = $segments[1];
            $key = $segments[2];
            $containers = [
                $heart['fields'][$field] ?? null,
            ];
            foreach ((array) ($heart['models'] ?? []) as $model) {
                foreach ((array) ($model['fields'] ?? []) as $f) {
                    if (($f['handle'] ?? null) === $field) {
                        $containers[] = $f;
                    }
                }
            }
            foreach ($containers as $container) {
                if (is_array($container) && ! empty($container[$key])) {
                    return true;
                }
            }

            return false;
        } else {
            $node = $heart;
        }
        foreach ($segments as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }

        return ! empty($node);
    }

    /**
     * @return array{0:float,1:int,2:array<int,array{label:string,pass:bool,weight:int}>}
     */
    private static function scoreElements(array $elements, callable $check): array
    {
        $earned = 0.0;
        $weight = 0;
        $results = [];
        foreach ($elements as $el) {
            $ok = (bool) $check($el);
            $weight += $el['weight'];
            if ($ok) {
                $earned += $el['weight'];
            }
            $results[] = ['label' => $el['label'], 'pass' => $ok, 'weight' => $el['weight']];
        }

        return [$earned, $weight, $results];
    }
}
