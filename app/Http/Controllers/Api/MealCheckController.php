<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MealValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MealCheckController extends Controller
{
    public function __construct(private readonly MealValidationService $mealValidationService)
    {
    }

    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'card_uid' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->mealValidationService->validateAndAuthorize(
            $validated['card_uid'],
            null,
            $request->user()?->id
        );

        $isAuthorized = $result['status'] === 'success';

        return response()->json([
            'status' => $isAuthorized ? 'authorized' : 'denied',
            'message' => $result['message'],
            'employee_name' => $result['employee_name'],
            'log_id' => $result['log_id'],
        ], $isAuthorized ? 200 : 422);
    }
}
