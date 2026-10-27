<?php

namespace LaraSlice\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LaraSlice\Support\RecordActions;

/**
 * Executes declared record actions (see Support\RecordActions):
 * POST /laraslice/record-actions/{prefix}/{id}/{action}.
 * One framework endpoint for every slice — adoption is by
 * Heart declaration alone, no per-slice route regeneration.
 */
final class RecordActionController
{
    public function __invoke(Request $request, string $prefix, string $id, string $action): RedirectResponse
    {
        $result = RecordActions::execute($prefix, $id, $action, $request->user());
        if ($result['status'] !== 200) {
            abort($result['status'], $result['message']);
        }

        return back()->with('success', $result['message']);
    }
}
