<?php

namespace LaraSlice\Http\Controllers;

use Illuminate\Http\Request;
use LaraSlice\Support\RecordActions;

/**
 * Executes a slice's native bulk actions (L4) — one
 * endpoint for every directory, resolved by route prefix.
 */
final class BulkActionController
{
    public function __invoke(Request $request, string $prefix, string $action)
    {
        $ids = (array) $request->input('ids', []);
        [$status, $message, $affected] = RecordActions::executeBulk($prefix, $action, $ids, $request->user());
        if ($status !== 200) {
            abort($status, $message);
        }

        return back()->with('success', $message);
    }
}
