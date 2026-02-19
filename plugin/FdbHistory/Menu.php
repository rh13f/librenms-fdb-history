<?php

/**
 * FdbHistory Plugin — Menu entry
 *
 * Adds "FDB History" to the LibreNMS plugins menu.
 * Visible to any user with at least global-read access.
 */

namespace App\Plugins\FdbHistory;

use App\Plugins\Hooks\MenuEntryHook;

class Menu extends MenuEntryHook
{
    public function authorize(\Illuminate\Contracts\Auth\Authenticatable $user, array $settings = []): bool
    {
        return $user->can('global-read');
    }

    public function data(array $settings = []): array
    {
        return [];
    }
}
