<?php

declare(strict_types=1);

namespace LaraSlice\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaraSlice\Support\NavHtml;
use LaraSlice\Support\NavStructure;

/**
 * Lazy navigation endpoints (Stage C-1).
 *
 * The shell used to render the full fleet's sidebar items
 * and the command palette's whole slice index into every
 * page (~178KB of the 437KB dashboard on the 174-slice
 * fleet). These two endpoints serve what the page no longer
 * carries:
 *
 *  - index(): the palette's slice entries as JSON, for the
 *    signed-in user (the route sits in the web+auth group,
 *    and the entries come from getNavigableSlices(), which
 *    is already permission- and declaration-filtered);
 *  - group(): one sidebar group's rendered items, fetched
 *    when the user first expands that group in the shell.
 */
final class NavController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'items' => NavStructure::paletteItems(),
        ]);
    }

    public function group(Request $request): Response
    {
        $title = (string) $request->query('g', '');
        $data = NavStructure::build();
        foreach ($data['main'] as $group) {
            if ((string) ($group['title'] ?? '') === $title) {
                return response(NavHtml::renderGroupItems($group), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }
        }

        return response('', 404);
    }
}
