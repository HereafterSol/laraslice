<?php

namespace LaraSlice\Slices\Settings\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use LaraSlice\Core\Security\Access;
use LaraSlice\Slices\Settings\Models\Setting;
use LaraSlice\Support\SettingsUi;

class ThemeWebController extends Controller
{
    /**
     * Render the interactive Theme Studio.
     */
    public function index(): View
    {
        return view('settings::theme');
    }

    /**
     * Persist the customizer theme server-side (Stage C-3).
     *
     * Before this, the Theme Studio wrote localStorage only:
     * the choice lived in one browser, and an admin could not
     * set a company-wide brand. The ten brand dimensions (see
     * SettingsUi::THEME_DIMENSIONS) now save to the Setting
     * store under `theme.*`; the shell seeds them into every
     * page and the theme store prefers them over localStorage.
     * `mode` (light/dark) is not saved — it stays personal.
     * A `reset: true` payload clears the saved theme instead.
     */
    public function save(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        if (! Access::allows($user, ['settings.theme.edit', 'settings.edit'])) {
            abort(403, 'Access Denied: you do not have permission to manage system settings.');
        }

        if ($request->boolean('reset')) {
            Setting::query()->whereIn('key', array_map(fn ($d) => 'theme.'.$d, SettingsUi::THEME_DIMENSIONS))->delete();

            return response()->json(['ok' => true, 'reset' => true]);
        }

        $rules = [];
        foreach (SettingsUi::THEME_DIMENSIONS as $dimension) {
            $rules[$dimension] = 'sometimes|string|max:40';
        }
        $validated = $request->validate($rules);

        $saved = [];
        foreach ($validated as $dimension => $value) {
            Setting::set('theme.'.$dimension, (string) $value, 'theme');
            $saved[] = $dimension;
        }

        return response()->json(['ok' => true, 'saved' => $saved]);
    }
}
