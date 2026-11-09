<?php

declare(strict_types=1);

namespace LaraSlice\Core\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LaraSlice\Core\Metadata\MetadataIssues;

/**
 * Proposal #4's state machine: two-phase confirmation for
 * AI-initiated writes.
 *
 *   PROPOSE   a gated surface describes a write (surface +
 *             operation + exact arguments) and receives a
 *             confirmation token — it does NOT execute.
 *   CONFIRM   a human approves the proposal as presented
 *             (rendered from an ApprovalSpec).
 *   EXECUTE   the surface repeats the call with the token;
 *             this service verifies it and only then may the
 *             surface perform exactly the proposed write.
 *
 * The token is a compact HMAC-SHA256-signed claim set
 * (header.payload.signature, base64url — the ServiceStack
 * MCP reference design's shape, adapted): it binds the
 * surface, the operation, a SHA-256 hash of the canonicalised
 * exact arguments, and the proposing user (sub; null on the
 * user-less stdio channel, where a token is bound to that
 * channel instead). TTL is server-side configuration
 * (`laraslice.ai.confirmation.ttl_seconds`, default 300 —
 * the reference design's value), never a model choice.
 *
 * Single-use is the one piece of server-side state: a
 * verified token's jti is consumed into the cache (atomic
 * add) immediately before execution, so a token executes at
 * most once even if the write itself then fails — a failed
 * write starts a fresh proposal, never a token retry.
 *
 * Every rejection is the ladder's per-issue envelope
 * (MetadataIssues): which field, what the problem is, what
 * was expected — the caller can correct and retry.
 */
final class WriteConfirmer
{
    public const SURFACE_MCP = 'mcp';

    public const SURFACE_EXPLORER = 'explorer';

    public function ttlSeconds(): int
    {
        $ttl = config('laraslice.ai.confirmation.ttl_seconds');

        // Existing installs: a published config predating
        // Phase C has no confirmation section (Laravel merges
        // config shallowly) — fall back to the shipped default
        // rather than to no expiry.
        return is_numeric($ttl) ? max(0, (int) $ttl) : 300;
    }

    /**
     * Phase 1 for an MCP write tool: build the proposal
     * payload (nothing executed). The payload carries the
     * token, a human-readable summary, the ApprovalSpec for
     * rendering the confirmation, and the instruction that
     * teaches the protocol to the calling client.
     *
     * @param  array<string, mixed>  $args  the tool arguments, WITHOUT any confirmation_token
     * @return array<string, mixed>
     */
    public function proposeMcp(string $tool, array $args, ?int $userId, bool $destructive): array
    {
        $ttl = $this->ttlSeconds();
        $expiresAt = time() + $ttl;

        $spec = ApprovalSpec::forMcpTool($tool, $args, $destructive, $expiresAt, $ttl);
        $specIssues = ApprovalSpec::validate($spec);
        if ($specIssues !== []) {
            // Fail closed: never issue a token behind a spec
            // no renderer could present faithfully.
            return ['success' => false, 'issues' => $specIssues];
        }

        $token = $this->issue([
            'surface' => self::SURFACE_MCP,
            'tool' => $tool,
            'args_hash' => self::argsHash($args),
        ], $userId, $expiresAt);

        $pairs = [];
        foreach ($args as $name => $value) {
            $pairs[] = $name.'='.ApprovalSpec::displayValue($value);
        }
        $summary = $tool.'('.implode(', ', $pairs).')';

        return [
            'success' => false,
            'status' => 'requires_confirmation',
            'message' => 'Not executed: this write requires human confirmation first.',
            'summary' => $summary,
            'instruction' => 'Show this proposal (the spec) to the user. Only if the user approves, call '
                .$tool.' again with exactly the same arguments plus "confirmation_token" set to the token '
                .'below. The token expires in '.$ttl.' seconds, is bound to these arguments and to the '
                .'proposing user, and can be used once.',
            'confirmation' => [
                'token' => $token,
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expiresAt),
                'ttl_seconds' => $ttl,
            ],
            'proposal' => ['tool' => $tool, 'arguments' => $args],
            'spec' => $spec,
        ];
    }

    /**
     * Phase 1 for an Explorer write operation: issue the token
     * binding the presented operation + arguments. The
     * controller builds (and validates) the ApprovalSpec.
     *
     * @param  array{slice: string, operation: string, method: string, uri: string, kind: string}  $operation
     * @param  array<string, mixed>  $args
     * @return array{token: string, expires_at: int, ttl_seconds: int}
     */
    public function issueExplorerToken(array $operation, array $args, ?int $userId): array
    {
        $ttl = $this->ttlSeconds();
        $expiresAt = time() + $ttl;

        $token = $this->issue([
            'surface' => self::SURFACE_EXPLORER,
            'slice' => $operation['slice'],
            'operation' => $operation['operation'],
            'method' => $operation['method'],
            'uri' => $operation['uri'],
            'kind' => $operation['kind'],
            'args_hash' => self::argsHash($args),
        ], $userId, $expiresAt);

        return ['token' => $token, 'expires_at' => $expiresAt, 'ttl_seconds' => $ttl];
    }

    /**
     * Phase 3 for MCP: verify a presented token against the
     * tool + arguments + user of THIS call. On success the
     * token is consumed (single-use) and the caller executes.
     *
     * @param  array<string, mixed>  $args  the tool arguments, WITHOUT any confirmation_token
     * @return array{ok: bool, result?: array<string, mixed>}
     */
    public function verifyMcp(string $token, string $tool, array $args, ?int $userId): array
    {
        $claims = $this->authenticatedClaims($token);
        if (($claims['ok'] ?? true) === false) {
            return $claims; // already the failure verdict
        }

        if (($claims['surface'] ?? null) !== self::SURFACE_MCP) {
            return $this->fail('confirmation_token', 'This confirmation token was not issued for the MCP surface.', 'A token from an MCP write proposal (call the tool once without a token to receive one).');
        }
        if (($claims['tool'] ?? null) !== $tool) {
            return $this->fail('tool', "This confirmation token was issued for a different tool ('".(string) ($claims['tool'] ?? '')."').", "A token proposed for {$tool} — approvals never transfer between operations.");
        }

        return $this->verifyBinding($claims, $args, $userId);
    }

    /**
     * Phase 3 for the Explorer: verify a presented token
     * against the operation + arguments + user of THIS call.
     * Method/kind are re-derived from the slice's schema by
     * the controller (application-side facts, never taken from
     * the request), and are compared against the claims too.
     *
     * @param  array{slice: string, operation: string, uri: string}  $presented
     * @param  array{method: string, kind: string}  $derived
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, result?: array<string, mixed>}
     */
    public function verifyExplorer(string $token, array $presented, array $derived, array $args, ?int $userId): array
    {
        $claims = $this->authenticatedClaims($token);
        if (($claims['ok'] ?? true) === false) {
            return $claims;
        }

        if (($claims['surface'] ?? null) !== self::SURFACE_EXPLORER) {
            return $this->fail('confirmation_token', 'This confirmation token was not issued for the Explorer surface.', 'A token from an Explorer write proposal on this page.');
        }
        foreach (['slice' => 'slice', 'operation' => 'operation', 'uri' => 'uri'] as $claim => $field) {
            if (($claims[$claim] ?? null) !== ($presented[$claim] ?? null)) {
                return $this->fail($field, "This confirmation token was issued for a different {$claim} ('".(string) ($claims[$claim] ?? '')."').", 'Approve the proposal exactly as presented, or start a new proposal for this operation.');
            }
        }
        if (($claims['method'] ?? null) !== $derived['method'] || ($claims['kind'] ?? null) !== $derived['kind']) {
            return $this->fail('operation', 'The operation behind this token no longer matches the slice schema.', 'Start a new proposal — the schema changed since this token was issued.');
        }

        return $this->verifyBinding($claims, $args, $userId);
    }

    /**
     * The checks both surfaces share once the token is
     * authenticated and the operation binding holds: user,
     * expiry, exact arguments, single-use consumption (in
     * that order — a token is consumed only when everything
     * else passed, immediately before execution).
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $args
     * @return array{ok: bool, result?: array<string, mixed>}
     */
    private function verifyBinding(array $claims, array $args, ?int $userId): array
    {
        if (($claims['sub'] ?? null) !== $userId) {
            return $this->fail('user', 'This confirmation token was issued to a different user.', 'The user who received the proposal must be the user who executes it.');
        }

        if (time() >= (int) ($claims['exp'] ?? 0)) {
            return $this->fail('confirmation_token', 'This confirmation token has expired.', 'A fresh proposal — tokens live '.$this->ttlSeconds().' seconds so an approval is always a current decision.');
        }

        if (! hash_equals((string) ($claims['args_hash'] ?? ''), self::argsHash($args))) {
            return $this->fail('arguments', 'The arguments differ from the approved proposal.', 'Repeat the call with exactly the proposed arguments, or start a new proposal for the changed arguments.');
        }

        $consumed = Cache::add('laraslice:write-confirmation:used:'.(string) ($claims['jti'] ?? ''), true, max(1, (int) $claims['exp'] - time()));
        if (! $consumed) {
            return $this->fail('confirmation_token', 'This confirmation token has already been used.', 'A fresh proposal — tokens are single-use, so a completed or attempted execution cannot be replayed.');
        }

        return ['ok' => true];
    }

    /**
     * Parse + authenticate a token. Returns the claims array,
     * or a failure verdict when the token is malformed or its
     * signature does not verify (a tampered token and a
     * garbage token are the same answer on purpose).
     *
     * @return array<string, mixed>
     */
    private function authenticatedClaims(string $token): array
    {
        $invalid = $this->fail('confirmation_token', 'This confirmation token is malformed or its signature does not verify.', 'The exact token issued with the proposal, unmodified.');

        if ($this->key() === '') {
            // Fail closed: with no APP_KEY there is no secret
            // to verify against — an empty-key HMAC would be
            // forgeable by anyone, so nothing authenticates.
            return $invalid;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return $invalid;
        }
        [$head64, $body64, $sig64] = $parts;

        $header = json_decode((string) self::b64d($head64), true);
        $claims = json_decode((string) self::b64d($body64), true);
        $signature = self::b64d($sig64);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256'
            || ! is_array($claims) || ($claims['v'] ?? null) !== 1
            || ! is_string($claims['jti'] ?? null) || $signature === null) {
            return $invalid;
        }

        $expected = hash_hmac('sha256', $head64.'.'.$body64, $this->key(), true);
        if (! hash_equals($expected, $signature)) {
            return $invalid;
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $binding
     */
    private function issue(array $binding, ?int $userId, int $expiresAt): string
    {
        if ($this->key() === '') {
            // Fail closed (see authenticatedClaims): never
            // mint a token whose signature anyone can forge.
            throw new \RuntimeException('Cannot issue a write-confirmation token: the application has no APP_KEY configured.');
        }

        $claims = array_merge($binding, [
            'v' => 1,
            'jti' => (string) Str::uuid(),
            'sub' => $userId,
            'iat' => time(),
            'exp' => $expiresAt,
        ]);

        $head64 = self::b64e((string) json_encode(['alg' => 'HS256', 'typ' => 'LSCT']));
        $body64 = self::b64e((string) json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $sig64 = self::b64e(hash_hmac('sha256', $head64.'.'.$body64, $this->key(), true));

        return $head64.'.'.$body64.'.'.$sig64;
    }

    /**
     * The token's integrity inherits the application's key —
     * the same secret Laravel's encrypter and signed URLs
     * trust (APP_KEY management is the host's standing duty).
     */
    private function key(): string
    {
        $key = (string) config('app.key', '');
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $key;
    }

    /**
     * The canonical form two calls must agree on for the
     * args-hash to match: associative arrays are key-sorted
     * recursively (JSON object key order is not meaningful),
     * lists keep their order, scalars pass through. JSON
     * decoding produces the same PHP shapes at propose and at
     * execute, so identical arguments hash identically — and
     * any change (value, type, added or removed key) changes
     * the hash.
     */
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $v): mixed => self::canonicalize($v), $value);
        }

        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = self::canonicalize($v);
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @param array<string, mixed> $args */
    public static function argsHash(array $args): string
    {
        return hash('sha256', (string) json_encode(self::canonicalize($args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return array{ok: false, result: array<string, mixed>} */
    private function fail(string $field, string $problem, string $expected): array
    {
        return ['ok' => false, 'result' => MetadataIssues::confirmation($field, $problem, $expected)];
    }

    private static function b64e(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64d(string $text): ?string
    {
        $b64 = strtr($text, '-_', '+/');
        $remainder = strlen($b64) % 4;
        if ($remainder > 0) {
            $b64 .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($b64, true);

        return $decoded === false ? null : $decoded;
    }
}
