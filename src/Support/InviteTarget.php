<?php

namespace LaraSlice\Support;

use Illuminate\Support\Facades\Route;

/**
 * Invite member (G1 residue): the source puts "Invite
 * member to {app}" in the user menu. The port's invite
 * surface is the P14 slice declaration (a slice declaring
 * ui.invite gets {prefix}.invite + {prefix}.invite_store
 * routes) — so the menu item is declaration-driven like
 * QuickCreate: it appears only when some slice actually
 * declares an invite, and links to that invite page.
 */
class InviteTarget
{
    /**
     * The invite page URL of the first slice declaring an
     * invite (route names sorted for determinism), or
     * null when no slice declares one.
     */
    public static function url(): ?string
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            $name = (string) ($route->getName() ?? '');
            if (str_ends_with($name, '.invite') && in_array('GET', $route->methods(), true)) {
                $names[] = $name;
            }
        }
        if ($names === []) {
            return null;
        }
        sort($names);

        return route($names[0]);
    }
}
