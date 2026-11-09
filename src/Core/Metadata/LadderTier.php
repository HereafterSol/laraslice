<?php

declare(strict_types=1);

namespace LaraSlice\Core\Metadata;

/**
 * The discovery ladder's per-tier shape (Proposals #1–#3).
 *
 * The tiers are declared in config (`laraslice.ai.ladder`) —
 * the framework's one AI configuration home — because harness
 * fit is per model (compilation §3.17: on identical models the
 * best harness flips with the model, and cost per solved task
 * spreads ~11.7×). Each tier therefore carries its own ladder
 * depth: how the fleet index renders into a consumer's context,
 * the page size when it renders as rows, how many describe
 * payloads one conversation turn may attach, and whether the
 * UI tier ever rides along (it does not, by default — roughly
 * half a Heart is UI material no data question needs).
 *
 * The in-app consumers (the Copilot's provider path, the MCP
 * tools) run at the configured tier — 'free' by default,
 * because the Copilot's configured providers are free-API
 * class (OpenCode free, Gemini Flash-Lite). The built-in
 * responder spends zero model tokens and is not tier-bound.
 */
final class LadderTier
{
    /** Built-in defaults, identical to config/laraslice.php. */
    private const DEFAULTS = [
        'frontier' => ['context_tokens' => 200000, 'index' => 'rows', 'per_page' => 250, 'max_describes' => 4, 'describe_ui' => false],
        'free' => ['context_tokens' => 32000, 'index' => 'names', 'per_page' => 60, 'max_describes' => 2, 'describe_ui' => false],
        'webgpu' => ['context_tokens' => 8192, 'index' => 'domain_names', 'per_page' => 24, 'max_describes' => 1, 'describe_ui' => false],
        'on_device' => ['context_tokens' => 4096, 'index' => 'summary', 'per_page' => 12, 'max_describes' => 1, 'describe_ui' => false],
    ];

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::tiers());
    }

    /**
     * One tier's shape, with its name folded in. An unknown
     * name falls back to 'free' — the tier the in-app
     * consumers are measured at — never to the largest tier.
     *
     * @return array<string, mixed>
     */
    public static function for(?string $name): array
    {
        $tiers = self::tiers();
        $name = is_string($name) && isset($tiers[$name]) ? $name : 'free';

        return array_merge($tiers[$name], ['name' => $name]);
    }

    /** @return array<string, mixed> */
    public static function current(): array
    {
        $configured = config('laraslice.ai.ladder.tier');

        return self::for(is_string($configured) ? $configured : null);
    }

    /** @return array<string, array<string, mixed>> */
    private static function tiers(): array
    {
        $configured = config('laraslice.ai.ladder.tiers');
        $tiers = self::DEFAULTS;
        if (is_array($configured)) {
            foreach ($configured as $name => $shape) {
                if (is_string($name) && is_array($shape)) {
                    $tiers[$name] = array_merge($tiers[$name] ?? [], $shape);
                }
            }
        }

        return $tiers;
    }
}
