<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AdminAuthController extends Controller
{
    public function showLogin(): Response
    {
        // Evite que le navigateur (Safari iOS notamment) restaure cette page depuis
        // son cache/back-forward-cache avec un ancien jeton CSRF apres mise en veille
        // de l'onglet, ce qui provoquait des erreurs 419 aleatoires a la connexion.
        return response()
            ->view('auth.login')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors([
                'email' => 'Identifiants invalides.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        $user = $request->user();

        // Allow both admins and regular users with permissions
        if (!$user || (!$user->is_admin && $user->getAccessiblePages() === [])) {
            Auth::logout();

            return back()->withErrors([
                'email' => 'Compte non autorise pour l\'administration.',
            ])->onlyInput('email');
        }

        // If non-admin user has access to only one page, redirect directly to it
        if (!$user->is_admin) {
            $accessiblePages = $user->getAccessiblePages();
            if (count($accessiblePages) === 1) {
                $pageName = $accessiblePages[0];
                // Map page slugs to route names
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

                if (isset($pageRoutes[$pageName])) {
                    return redirect()->intended(route($pageRoutes[$pageName]));
                }
            }
        }

        return redirect()->intended(route('dashboard.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
