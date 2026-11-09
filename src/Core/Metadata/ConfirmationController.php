<?php

declare(strict_types=1);

namespace LaraSlice\Core\Metadata;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LaraSlice\Core\Ai\ApprovalSpec;
use LaraSlice\Core\Ai\WriteConfirmer;

/**
 * Proposal #4's Explorer seat: the two server endpoints that
 * turn the Explorer's write cards into propose → confirm →
 * execute (see WriteConfirmer for the state machine).
 *
 * This controller is the interposition point Phase B did not
 * have: the page no longer fetches a write route directly.
 * PROPOSE describes the write (slice + operation + exact
 * arguments, validated against the slice's schema contract)
 * and returns a signed confirmation token plus the
 * ApprovalSpec the page renders for the human's decision.
 * EXECUTE verifies the token and only then dispatches the
 * write — as an internal sub-request to the slice's own
 * route, built exactly like the browser request Phase B sent
 * (JSON body, Accept: application/json, the session's CSRF
 * token, the session cookie), so the slice route's own
 * middleware, authorization and validation apply. (Dispatch
 * goes through the router, not the HTTP kernel: route
 * middleware runs; kernel-global middleware does not.) Read operations are untouched: they keep
 * Phase B's direct fetch (they cannot change anything).
 *
 * Rejections are the ladder's per-issue envelope:
 * unknown slice 404 (the Phase A/B convention); everything
 * else 422 — not invocable, URI mismatch, and every token
 * verification failure (bad signature, expired, replayed,
 * arguments altered, wrong user).
 */
final class ConfirmationController
{
    private const WRITE_KINDS = ['create', 'update', 'delete'];

    public function propose(Request $request, MetadataProjector $projector, WriteConfirmer $confirmer): JsonResponse
    {
        $resolved = $this->resolveWriteOperation($request, $projector);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$schema, $form, $uri, $args] = $resolved;

        $operation = [
            'slice' => (string) $schema['id'],
            'operation' => (string) $form['operation']['name'],
            'method' => strtoupper((string) $form['operation']['method']),
            'uri' => $uri,
            'kind' => (string) $form['kind'],
        ];
        $issued = $confirmer->issueExplorerToken($operation, $args, $this->userId());

        $spec = ApprovalSpec::forExplorerOperation(
            (string) ($schema['title'] ?? $schema['name']),
            ['name' => $operation['operation'], 'method' => $operation['method'], 'uri' => $uri],
            $operation['kind'],
            (array) ($form['fields'] ?? []),
            $args,
            $issued['expires_at'],
            $issued['ttl_seconds'],
        );
        $specIssues = ApprovalSpec::validate($spec);
        if ($specIssues !== []) {
            // Fail closed: no token leaves behind a spec no
            // renderer could present faithfully.
            return response()->json(['success' => false, 'issues' => $specIssues], 422);
        }

        return response()->json([
            'success' => true,
            'status' => 'requires_confirmation',
            'message' => 'Not executed: this write requires confirmation. Review the proposal, then execute with the token.',
            'confirmation' => [
                'token' => $issued['token'],
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $issued['expires_at']),
                'ttl_seconds' => $issued['ttl_seconds'],
            ],
            'proposal' => $operation + ['arguments' => $args],
            'spec' => $spec,
        ]);
    }

    public function execute(Request $request, MetadataProjector $projector, WriteConfirmer $confirmer): JsonResponse
    {
        $token = $request->input('confirmation_token');
        if (! is_string($token) || $token === '') {
            return response()->json(MetadataIssues::missingArgument('confirmation_token', 'The token issued with the proposal (propose first, approve, then execute).'), 422);
        }

        $resolved = $this->resolveWriteOperation($request, $projector);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$schema, $form, $uri, $args] = $resolved;

        $verdict = $confirmer->verifyExplorer(
            $token,
            ['slice' => (string) $schema['id'], 'operation' => (string) $form['operation']['name'], 'uri' => $uri],
            ['method' => strtoupper((string) $form['operation']['method']), 'kind' => (string) $form['kind']],
            $args,
            $this->userId(),
        );
        if (! ($verdict['ok'] ?? false)) {
            return response()->json($verdict['result'], 422);
        }

        // Verified. The token is already consumed (single-use);
        // dispatch exactly the proposed write. The sub-request
        // is bound into the container for the dispatch: route
        // closures and controllers resolve their Request from
        // the container, so without the binding they would see
        // THIS request (whose input has no field values) while
        // the router matched the sub-request's URI.
        $method = strtoupper((string) $form['operation']['method']);
        $sub = $this->subRequest($request, $uri, $method, $args);
        $outer = app('request');
        app()->instance('request', $sub);
        try {
            $response = app('router')->dispatch($sub);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(MetadataIssues::confirmation(
                'operation',
                'The confirmed write threw an exception and did not complete.',
                'Fix the cause and start a new proposal — the token was consumed, by design, so it cannot be retried.'
            ), 422);
        } finally {
            app()->instance('request', $outer);
        }

        $body = (string) $response->getContent();
        $decoded = json_decode($body, true);

        return response()->json([
            'success' => $response->isSuccessful(),
            'executed' => true,
            'status' => $response->getStatusCode(),
            'response' => json_last_error() === JSON_ERROR_NONE ? $decoded : $body,
        ]);
    }

    /**
     * Shared front half of propose/execute: resolve the slice,
     * find the named operation in its schema, require an
     * invocable WRITE operation, and require the presented URI
     * to be that operation's URI (with its {param} filled by
     * exactly one segment). The method and kind used for
     * binding and dispatch come from here — from the schema —
     * never from the request.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string, 3: array<string, mixed>}|JsonResponse
     */
    private function resolveWriteOperation(Request $request, MetadataProjector $projector): array|JsonResponse
    {
        $sliceId = trim((string) $request->input('slice', ''));
        if ($sliceId === '') {
            return response()->json(MetadataIssues::missingArgument('slice', 'A slice id, name or handle listed by the metadata index (see list_slices).'), 422);
        }
        $schema = $projector->schema($sliceId);
        if ($schema === null) {
            return response()->json(MetadataIssues::unknownSlice($sliceId), 404);
        }

        $opName = trim((string) $request->input('operation', ''));
        $form = null;
        foreach (ExplorerForm::operations($schema) as $candidate) {
            if (($candidate['operation']['name'] ?? null) === $opName && $opName !== '') {
                $form = $candidate;
                break;
            }
        }
        if ($form === null) {
            return response()->json(MetadataIssues::confirmation('operation', "Operation '{$opName}' is not among this slice's projected operations.", 'One of the operation route names in the slice schema (see the Explorer page for this slice).'), 422);
        }
        if (empty($form['invocable']) || ! in_array($form['kind'], self::WRITE_KINDS, true)) {
            return response()->json(MetadataIssues::confirmation('operation', "Operation '{$opName}' is not an invocable write operation (kind: {$form['kind']}).", 'A create, update or delete operation from the slice schema. Reads execute directly; inspect-only operations cannot be invoked from the Explorer at all.'), 422);
        }

        $args = $request->input('args', []);
        if (! is_array($args)) {
            return response()->json(MetadataIssues::confirmation('arguments', 'The arguments are not an object.', 'A JSON object of field name → value, exactly as the form produced it.'), 422);
        }

        $uri = (string) $request->input('uri', '');
        $template = (string) $form['operation']['uri'];
        if (! $this->uriMatchesTemplate($uri, $template, $form['id_param'] ?? null)) {
            return response()->json(MetadataIssues::confirmation('uri', "The URI '{$uri}' does not match the operation's URI '{$template}'.", 'The operation URI from the schema, with its {parameter} filled by the target record id.'), 422);
        }

        return [$schema, $form, $uri, $args];
    }

    private function uriMatchesTemplate(string $uri, string $template, ?string $idParam): bool
    {
        if ($idParam === null) {
            return $uri === $template;
        }
        $quotedParam = preg_quote('{'.$idParam.'}', '#');
        $pattern = '#^'.str_replace($quotedParam, '[^/]+', preg_quote($template, '#')).'$#';

        return preg_match($pattern, $uri) === 1;
    }

    /**
     * The internal dispatch, shaped exactly like the browser
     * request Phase B's page sent: JSON body, JSON accept, the
     * session's CSRF token as a header, and the session cookie
     * forwarded so the slice route's own session/auth/CSRF
     * middleware see the signed-in user.
     *
     * @param  array<string, mixed>  $args
     */
    private function subRequest(Request $request, string $uri, string $method, array $args): Request
    {
        $content = $method === 'DELETE'
            ? null
            : (string) json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $sub = Request::create($uri, $method, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $content);

        if ($request->hasSession()) {
            $sub->headers->set('X-CSRF-TOKEN', (string) $request->session()->token());
            $sessionName = $request->session()->getName();
            if ($request->cookies->has($sessionName)) {
                $sub->cookies->set($sessionName, $request->cookies->get($sessionName));
            }
        }
        $sub->setUserResolver(static fn () => $request->user());

        return $sub;
    }

    private function userId(): ?int
    {
        return auth()->id() !== null ? (int) auth()->id() : null;
    }
}
