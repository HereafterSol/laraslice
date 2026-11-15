<?php

declare(strict_types=1);

namespace LaraSlice\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LaraSlice\Support\RecordConverter;

/**
 * Seat 3 — saved list views: store the directory's
 * current query state under a name, recall it from the
 * List View switcher (a redirect back to the slice's
 * index carrying the stored params), or remove it.
 * Views are per-user and per-slice.
 */
class ListViewController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'slice' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string'],
        ]);
        if (! Schema::hasTable('laraslice_list_views')) {
            return back()->with('error', 'List views are not available yet (migration pending).');
        }
        $state = json_decode($data['state'], true);
        if (! is_array($state)) {
            return back()->with('error', 'That view state could not be read.');
        }
        // Keep only directory-state keys, never plumbing.
        $state = array_filter($state, fn ($key) => ! in_array($key, ['_token', '_method', 'page'], true), ARRAY_FILTER_USE_KEY);
        DB::table('laraslice_list_views')->updateOrInsert(
            ['user_id' => $request->user()?->id, 'slice' => $data['slice'], 'name' => trim($data['name'])],
            ['state' => json_encode($state), 'created_at' => now(), 'updated_at' => now()]
        );

        return back()->with('success', 'List view saved.');
    }

    public function apply(Request $request, string $view): RedirectResponse
    {
        $row = Schema::hasTable('laraslice_list_views')
            ? DB::table('laraslice_list_views')->where('id', $view)->where('user_id', $request->user()?->id)->first()
            : null;
        if ($row === null) {
            abort(404);
        }
        $prefix = RecordConverter::targetRoutePrefix((string) $row->slice);
        if ($prefix === null || ! Route::has($prefix.'index')) {
            abort(404);
        }
        $state = json_decode((string) $row->state, true) ?: [];

        return redirect()->route($prefix.'index', $state);
    }

    public function destroy(Request $request, string $view): RedirectResponse
    {
        if (Schema::hasTable('laraslice_list_views')) {
            DB::table('laraslice_list_views')->where('id', $view)->where('user_id', $request->user()?->id)->delete();
        }

        return back()->with('success', 'List view removed.');
    }
}
