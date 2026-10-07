<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MealValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CardScanController extends Controller
{
    public function __construct(private readonly MealValidationService $mealValidationService)
    {
    }

    public function validateScan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'card_uid' => ['required', 'string', 'max:100'],
            'meal_rule_id' => ['nullable', 'exists:meal_rules,id'],
        ]);

        $result = $this->mealValidationService->validateAndAuthorize(
            $validated['card_uid'],
            $validated['meal_rule_id'] ?? null
        );

        return response()->json($result, $result['status'] === 'success' ? 200 : 422);
    }
}
