<?php

namespace App\Traits;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

trait ChecksPagePermission
{
    protected function checkPagePermission(Request $request, string $pageSlug): bool|RedirectResponse
    {
        $user = $request->user();

        if (!$user) {
            return redirect('/login');
        }

        // Admins have access to everything
        if ($user->is_admin) {
            return true;
        }

        // Check if user has permission for this page
        if ($user->hasPermission($pageSlug)) {
            return true;
        }

        abort(403, 'Vous n\'avez pas accès à cette page.');
    }

    protected function getAccessiblePages(Request $request): array
    {
        $user = $request->user();

        if (!$user) {
            return [];
        }

        return $user->getAccessiblePages();
    }
}
