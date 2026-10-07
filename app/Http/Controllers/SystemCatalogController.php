<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Employee;
use App\Models\LaReleveEntry;
use App\Models\MealLog;
use App\Models\MealRule;
use App\Models\Department;
use Illuminate\View\View;

class SystemCatalogController extends Controller
{
    public function index(): View
    {
        // Récupérer les statistiques du système
        $stats = [
            'employees' => Employee::where('is_active', true)->count(),
            'inactive_employees' => Employee::where('is_active', false)->count(),
            'la_releve_entries' => LaReleveEntry::count(),
            'cards' => Card::count(),
            'active_cards' => Card::where('status', 'active')->count(),
            'meal_rules' => MealRule::count(),
            'active_meal_rules' => MealRule::where('is_active', true)->count(),
            'total_meal_logs' => MealLog::count(),
            'departments' => Department::count(),
        ];

        // Détails des règles de repas
        $mealRules = MealRule::with(['creator', 'mealLogs'])
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('dashboard.system-catalog', compact('stats', 'mealRules'));
    }
}
