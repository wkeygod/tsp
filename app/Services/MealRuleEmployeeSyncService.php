<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\MealRule;

class MealRuleEmployeeSyncService
{
    /**
     * Ajoute une nouvelle MealRule à tous les employés existants
     */
    public function addMealRuleToAllEmployees(MealRule $mealRule): void
    {
        if (!$mealRule->authorize_for_all_employees) {
            return;
        }

        Employee::query()->each(function (Employee $employee) use ($mealRule): void {
            $allowedMealTypes = $employee->allowed_meal_types ?? [];

            if (!in_array($mealRule->id, $allowedMealTypes, true)) {
                $allowedMealTypes[] = $mealRule->id;
                $employee->update(['allowed_meal_types' => $allowedMealTypes]);
            }
        });
    }

    /**
     * Supprime une MealRule de tous les employés
     */
    public function removeMealRuleFromAllEmployees(int $mealRuleId): void
    {
        Employee::query()->each(function (Employee $employee) use ($mealRuleId): void {
            $allowedMealTypes = $employee->allowed_meal_types ?? [];

            if (in_array($mealRuleId, $allowedMealTypes, true)) {
                $allowedMealTypes = array_values(
                    array_filter($allowedMealTypes, fn($id) => $id !== $mealRuleId)
                );
                $employee->update(['allowed_meal_types' => $allowedMealTypes]);
            }
        });
    }
}
