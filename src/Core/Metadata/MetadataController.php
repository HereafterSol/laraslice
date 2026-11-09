<?php

declare(strict_types=1);

namespace LaraSlice\Core\Metadata;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP surface for the runtime metadata projection
 * (Proposal #1). Read-only, behind the Studio's existing
 * `studio.access` gate:
 *
 *   GET /laraslice/studio/metadata            — the index
 *   GET /laraslice/studio/metadata/{slice}    — describe one
 *   GET /laraslice/studio/metadata/{slice}/schema
 *                                             — its schema
 *                                               contract (#2)
 *
 * These are the first two rungs of the discovery ladder: the
 * index is compact enough to sit in a small model's context;
 * describe is per-slice only — there is deliberately no fleet
 * dump endpoint. Responses carry an ETag (the hash of the
 * exact body served) and honour If-None-Match with a 304, so
 * a client re-describing in a loop pays headers, not payloads.
 */
final class MetadataController
{
    public function index(Request $request, MetadataProjector $projector): Response
    {
        $payload = $projector->index([
            'domain' => $request->query('domain'),
            'page' => $request->query('page', 1),
            'per_page' => $request->query('per_page', 250),
            'format' => $request->query('format', 'json'),
            'live' => $request->boolean('live'),
        ]);

        return $this->json($request, $payload);
    }

    public function describe(Request $request, string $slice, MetadataProjector $projector): Response
    {
        $payload = $projector->describe($slice, $request->boolean('ui'));
        if ($payload === null) {
            return $this->json($request, [
                'success' => false,
                'message' => "No slice matches '{$slice}'.",
            ], 404, etag: false);
        }

        return $this->json($request, $payload);
    }

    /**
     * The per-slice schema contract (Proposal #2): the describe
     * core, versioned, declaration-only, with a draft-07
     * `json_schema` for the primary table. Unknown slices get
     * the Phase B per-issue error shape (field / problem /
     * expected) so a caller can correct and retry.
     */
    public function schema(Request $request, string $slice, MetadataProjector $projector): Response
    {
        $payload = $projector->schema($slice, $request->boolean('ui'));
        if ($payload === null) {
            return $this->json($request, MetadataIssues::unknownSlice($slice), 404, etag: false);
        }

        return $this->json($request, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function json(Request $request, array $payload, int $status = 200, bool $etag = true): Response
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, must-revalidate',
        ];

        if ($etag) {
            $tag = '"'.sha1($body).'"';
            $headers['ETag'] = $tag;
            $ifNoneMatch = (string) $request->header('If-None-Match', '');
            if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $tag)) {
                return new Response('', 304, $headers);
            }
        }

        return new Response($body, $status, $headers);
    }

    private function etagMatches(string $ifNoneMatch, string $tag): bool
    {
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $tag || $candidate === 'W/'.$tag) {
                return true;
            }
        }

        return false;
    }
}
