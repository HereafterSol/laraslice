<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

/**
 * Design lint (P3): deterministic checks in the spirit of a
 * design anti-pattern linter — the values in DESIGN.md are
 * the law, and generated markup must not route around them.
 *
 *  - Contrast: the kit's declared text pairs must clear
 *    WCAG AA (4.5:1). Computed from the OKLCH tokens via
 *    OKLab → linear sRGB → relative luminance.
 *  - Hardcoded colour in slice blades (hex utilities or
 *    literals) is an error: colour comes from tokens only.
 *  - Inline style attributes and arbitrary pixel utilities
 *    are warnings: spacing comes from the declared scale.
 *
 * Findings use the shared kind/severity/message shape.
 */
class DesignLint
{
    /** Text pairs that must clear AA on their ground. */
    private const TEXT_PAIRS = [
        ['foreground', 'background'],
        ['muted-foreground', 'background'],
        ['muted-foreground', 'muted'],
        ['primary-foreground', 'primary'],
        ['success', 'background'],
        ['warning', 'background'],
        ['info', 'background'],
        ['destructive', 'background'],
    ];

    /**
     * @param  array<string, string>  $tokens  color name => oklch string
     * @return list<array{kind: string, severity: string, message: string}>
     */
    public static function contrastFindings(array $tokens): array
    {
        $findings = [];
        foreach (self::TEXT_PAIRS as [$fg, $bg]) {
            if (! isset($tokens[$fg], $tokens[$bg])) {
                continue;
            }
            $ratio = self::contrastRatio($tokens[$fg], $tokens[$bg]);
            if ($ratio === null) {
                continue;
            }
            if ($ratio < 4.5) {
                $findings[] = [
                    'kind' => 'lint.contrast',
                    'severity' => 'error',
                    'message' => sprintf(
                        'DESIGN.md pair %s on %s contrasts %.2f:1 — below WCAG AA 4.5:1 for text. Retune the token (everywhere, or not at all).',
                        $fg, $bg, $ratio
                    ),
                ];
            }
        }

        return $findings;
    }

    /** Contrast ratio between two oklch() colours, or null if unparsable. */
    public static function contrastRatio(string $a, string $b): ?float
    {
        $ya = self::luminance($a);
        $yb = self::luminance($b);
        if ($ya === null || $yb === null) {
            return null;
        }
        [$hi, $lo] = $ya >= $yb ? [$ya, $yb] : [$yb, $ya];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** Relative luminance of an oklch() colour (WCAG formula). */
    public static function luminance(string $oklch): ?float
    {
        if (! preg_match('/oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)/', $oklch, $m)) {
            return null;
        }
        $l = (float) $m[1];
        $c = (float) $m[2];
        $h = deg2rad((float) $m[3]);
        $oa = $c * cos($h);
        $ob = $c * sin($h);

        $lms = [
            $l + 0.3963377774 * $oa + 0.2158037573 * $ob,
            $l - 0.1055613458 * $oa - 0.0638541728 * $ob,
            $l - 0.0894841775 * $oa - 1.2914855480 * $ob,
        ];
        [$lr, $mr, $sr] = array_map(fn ($v) => $v ** 3, $lms);
        $r = max(0.0, 4.0767416621 * $lr - 3.3077115913 * $mr + 0.2309699292 * $sr);
        $g = max(0.0, -1.2684380046 * $lr + 2.6097574011 * $mr - 0.3413193965 * $sr);
        $bch = max(0.0, -0.0041960863 * $lr - 0.7034186147 * $mr + 1.7076147010 * $sr);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $bch;
    }

    /**
     * @return list<array{kind: string, severity: string, message: string}>
     */
    public static function bladeFindings(string $blade, string $label): array
    {
        $findings = [];
        if (preg_match('/(?:bg|text|border|ring|fill|stroke)-\[#[0-9a-fA-F]{3,8}\]/', $blade)
            || preg_match('/style="[^"]*#[0-9a-fA-F]{6}/', $blade)) {
            $findings[] = [
                'kind' => 'lint.hardcoded-colour',
                'severity' => 'error',
                'message' => "{$label}: hardcoded hex colour in markup — colour comes from DESIGN.md tokens only.",
            ];
        }
        $inlineStyles = substr_count($blade, 'style="');
        if ($inlineStyles > 0) {
            $findings[] = [
                'kind' => 'lint.inline-style',
                'severity' => 'warning',
                'message' => "{$label}: {$inlineStyles} inline style attribute(s) — presentation belongs to the kit, not the page.",
            ];
        }
        $arbitrary = preg_match_all('/(?:p|m|gap|w|h|text|leading)-\[\d+(?:px|rem)\]/', $blade);
        if ($arbitrary > 0) {
            $findings[] = [
                'kind' => 'lint.arbitrary-spacing',
                'severity' => 'warning',
                'message' => "{$label}: {$arbitrary} arbitrary spacing/size value(s) — choose from the declared scale.",
            ];
        }

        return $findings;
    }

    /**
     * Form presentations: the Heart's declared values against
     * the captured source evidence (Tier-5 P2 — the design
     * capture in porting/ui-spec/source.design.yaml). A
     * declared value that contradicts the capture is an
     * error: history's one declaration (leaves: drawer) was
     * exactly this, and the kit faithfully rendered the wrong
     * thing. A captured value with NO declaration is a
     * warning: the runtime default decides, and nothing can
     * drift-check an undeclared decision. 'unknown' captures
     * carry no evidence and stay silent.
     *
     * @param  array<string, string>  $captured  verb => page|drawer|modal|unknown
     * @param  array<string, string>  $declared  verb => page|drawer|modal
     * @return list<array{kind: string, severity: string, message: string}>
     */
    public static function presentationFindings(string $slug, array $captured, array $declared): array
    {
        $findings = [];
        foreach ($captured as $verb => $sourceValue) {
            if (! in_array($sourceValue, ['page', 'drawer', 'modal'], true)) {
                continue;
            }
            $declaredValue = $declared[$verb] ?? null;
            if ($declaredValue === null) {
                $findings[] = [
                    'kind' => 'lint.presentation-undeclared',
                    'severity' => 'warning',
                    'message' => "{$slug}: no presentation declared for {$verb} — the source renders a {$sourceValue}; declare the captured value so drift is checkable.",
                    'verb' => $verb, 'source' => $sourceValue, 'port' => null,
                ];
            } elseif ($declaredValue !== $sourceValue) {
                $findings[] = [
                    'kind' => 'lint.presentation-mismatch',
                    'severity' => 'error',
                    'message' => "{$slug}: {$verb} declares '{$declaredValue}' but the source renders a {$sourceValue} — the declaration, not the kit, is wrong.",
                    'verb' => $verb, 'source' => $sourceValue, 'port' => $declaredValue,
                ];
            }
        }

        return $findings;
    }

    /**
     * Parse the colors table out of a DESIGN.md (YAML front
     * matter, Google format).
     *
     * @return array<string, string>
     */
    public static function parseTokens(string $designMd): array
    {
        $tokens = [];
        if (preg_match('/^colors:\r?\n((?:  .+\r?\n?)+)/m', $designMd, $block)) {
            foreach (explode("\n", str_replace("\r", '', $block[1])) as $line) {
                if (preg_match('/^\s{2}([a-z-]+):\s*"(oklch\([^"]+\))"/', $line, $m)) {
                    $tokens[$m[1]] = $m[2];
                }
            }
        }

        return $tokens;
    }
}
