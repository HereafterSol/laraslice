<?php

declare(strict_types=1);

namespace LaraSlice\Core\Metadata;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Explorer (Proposal #2): a Studio page over one slice's
 * schema contract where its declared operations can be
 * inspected and invoked through a form generated from the
 * schema (see ExplorerForm for which operations are invocable
 * and why the rest are inspect-only).
 *
 * Invocation is the browser calling the slice's OWN routes
 * with the signed-in session — the app's normal routing,
 * authorization and validation apply exactly as they would to
 * any browser request; a 422 comes back with the app's
 * per-field errors and the form shows them beside the fields
 * (the self-repair loop). This controller only renders the
 * page: it proxies nothing and executes nothing itself.
 *
 * Phase boundary: the signed two-phase write confirmation is
 * Phase C (Proposal #4). Writes from here go through the
 * app's existing endpoints and permissions, with no added
 * confirmation mechanism.
 */
final class ExplorerController
{
    public function show(Request $request, string $slice, MetadataProjector $projector): View|Response
    {
        $schema = $projector->schema($slice);
        if ($schema === null) {
            return response()->view('laraslice::explorer', [
                'requested' => $slice,
                'schema' => null,
                'forms' => [],
                'issues' => MetadataIssues::unknownSlice($slice)['issues'],
            ], 404);
        }

        return view('laraslice::explorer', [
            'requested' => $slice,
            'schema' => $schema,
            'forms' => ExplorerForm::operations($schema),
            'issues' => [],
        ]);
    }
}
