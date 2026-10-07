<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPagePermission
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$pageNames): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect('/login');
        }

        // Admins have access to all pages
        if ($user->is_admin) {
            return $next($request);
        }

        // If no page names are specified, allow access (backward compatibility)
        if (empty($pageNames)) {
            return $next($request);
        }

        // Check if user has permission for any of the specified pages
        foreach ($pageNames as $pageName) {
            if ($user->hasPermission($pageName)) {
                return $next($request);
            }
        }

        $accessiblePages = $user->getAccessiblePages();
        $pageRoutes = [
            'dashboard.index' => 'dashboard.index',
            'employees.index' => 'employees.index',
            'employee-history.index' => 'employee-history.index',
            'meal-logs.index' => 'meal-logs.index',
            'la-releve.index' => 'la-releve.index',
            'extra.index' => 'extra.index',
            'reports.index' => 'reports.index',
            'meal-rules.index' => 'meal-rules.index',
            'system-catalog.index' => 'system-catalog.index',
            'departments.index' => 'departments.index',
            'devices.index' => 'devices.index',
            'roles.index' => 'roles.index',
            'kiosk.index' => 'kiosk.index',
        ];

        $fallbackSlug = collect(array_keys($pageRoutes))
            ->first(fn (string $slug): bool => in_array($slug, $accessiblePages, true));

        if ($fallbackSlug !== null) {
            $fallbackRoute = $pageRoutes[$fallbackSlug];

            if (!$request->routeIs($fallbackRoute)) {
                return redirect()
                    ->route($fallbackRoute)
                    ->with('error', 'Vous n\'avez pas accès à cette page. Redirection vers votre espace autorisé.');
            }
        }

        abort(403, 'Vous n\'avez pas accès à cette page.');
    }
}
