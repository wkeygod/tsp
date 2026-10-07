<?php

namespace App\Http\Controllers;

use App\Models\MealRule;
use App\Services\MealRuleEmployeeSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MealRuleController extends Controller
{
    public function __construct(private readonly MealRuleEmployeeSyncService $syncService)
    {
    }

    public function index()
    {
        $mealRules = MealRule::query()->latest()->paginate(12);

        return view('meal-rules.index', compact('mealRules'));
    }

    public function create()
    {
        return view('meal-rules.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'cost' => ['required', 'numeric', 'min:0'],
            'max_meals_per_day' => ['required', 'integer', 'min:1'],
            'max_meals_per_week' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'authorize_for_all_employees' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['authorize_for_all_employees'] = $request->boolean('authorize_for_all_employees', true);
        $validated['created_by'] = Auth::id();

        $mealRule = MealRule::create($validated);
        $this->syncService->addMealRuleToAllEmployees($mealRule);

        return redirect()->route('meal-rules.index')->with('success', 'Regle repas creee.');
    }

    public function show(MealRule $mealRule)
    {
        return redirect()->route('meal-rules.edit', $mealRule);
    }

    public function edit(MealRule $mealRule)
    {
        return view('meal-rules.edit', compact('mealRule'));
    }

    public function update(Request $request, MealRule $mealRule)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['integer', 'between:1,7'],
            'cost' => ['required', 'numeric', 'min:0'],
            'max_meals_per_day' => ['required', 'integer', 'min:1'],
            'max_meals_per_week' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'authorize_for_all_employees' => ['nullable', 'boolean'],
        ]);

        $validated['is_active'] = $request->boolean('is_active');
        $validated['authorize_for_all_employees'] = $request->boolean('authorize_for_all_employees', true);

        $mealRule->update($validated);
        $this->syncService->addMealRuleToAllEmployees($mealRule);

        return redirect()->route('meal-rules.index')->with('success', 'Regle repas mise a jour.');
    }

    public function destroy(MealRule $mealRule)
    {
        $this->syncService->removeMealRuleFromAllEmployees($mealRule->id);
        $mealRule->delete();

        return redirect()->route('meal-rules.index')->with('success', 'Regle repas supprimee.');
    }
}
