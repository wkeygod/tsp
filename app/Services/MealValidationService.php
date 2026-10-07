<?php

namespace App\Services;

use App\Models\Card;
use App\Models\MealLog;
use App\Models\MealRule;
use Illuminate\Support\Facades\DB;

class MealValidationService
{
    /**
     * @return array{status:string,message:string,employee_name:?string,reason:string,log_id:?int,allowed_meal_types:?array}
     */
    public function validateAndAuthorize(string $cardUid, ?int $mealRuleId = null, ?string $mealType = null, ?int $processedByUserId = null): array
    {
        $normalizedUid = Card::normalizeUid($cardUid);

        $card = Card::query()->with('employee')->where('uid', $normalizedUid)->first();

        if (!$card) {
            return $this->rejected('card_not_found', 'Carte introuvable.');
        }

        if (!$card->is_active || (Card::hasStatusColumn() && $card->status !== Card::STATUS_ACTIVE)) {
            return $this->rejected('card_inactive', 'Carte inactive.');
        }

        if (!$card->employee || !$card->employee->is_active || !$card->employee_id) {
            return $this->rejected('employee_inactive', 'Employe inactif.');
        }

        // Get allowed meal types for this employee (normalize to int IDs)
        $allowedMealTypesRaw = $card->employee->allowed_meal_types ?? [];
        if (is_string($allowedMealTypesRaw)) {
            $decoded = json_decode($allowedMealTypesRaw, true);
            $allowedMealTypesRaw = is_array($decoded) ? $decoded : [];
        }

        $allowedMealTypes = array_values(array_filter(array_map(
            static fn ($value) => is_numeric($value) ? (int) $value : null,
            is_array($allowedMealTypesRaw) ? $allowedMealTypesRaw : []
        ), static fn ($value) => is_int($value)));

        $employeeName = trim($card->employee->first_name . ' ' . $card->employee->last_name);

        // If no meal type specified, return info to select one
        if (!$mealRuleId && !$mealType) {
            return [
                'status' => 'pending_meal_selection',
                'message' => 'Selectionnez le type de repas.',
                'employee_name' => $employeeName,
                'allowed_meal_types' => $allowedMealTypes,
                'card_uid' => $normalizedUid,
            ];
        }

        // Use mealRuleId if provided, otherwise use mealType for backward compatibility
        $selectedMealRuleId = $mealRuleId;

        // Convert numeric mealType to int if needed
        if (is_numeric($mealType) && !$selectedMealRuleId) {
            $selectedMealRuleId = (int) $mealType;
        }

        // Optional legacy support: resolve by rule name
        if (!$selectedMealRuleId && is_string($mealType) && trim($mealType) !== '') {
            $selectedMealRuleId = MealRule::query()
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($mealType))])
                ->value('id');
        }

        if (!$selectedMealRuleId) {
            return $this->rejected('meal_type_not_found', 'Type de repas introuvable. Veuillez sélectionner un repas actif avant de scanner.', $employeeName);
        }

        // Validate that this meal type is allowed for the employee
        if (!in_array($selectedMealRuleId, $allowedMealTypes, true)) {
            return $this->rejected('meal_type_not_allowed', 'Ce type de repas n\'est pas autorisé pour cet employé.', $employeeName);
        }

        $mealRule = MealRule::query()->where('is_active', true)->find($selectedMealRuleId);

        if (!$mealRule) {
            return $this->rejected('meal_rule_not_found', 'Regle repas non trouvee.', $employeeName);
        }

        $now = now();
        $currentTime = $now->format('H:i:s');

        if (!$this->isTimeAllowed($mealRule->start_time, $mealRule->end_time, $currentTime)) {
            return $this->rejected('outside_allowed_time', 'Hors plage horaire autorisee.', $employeeName);
        }

        // Check if already authorized for this meal rule today
        $alreadyAuthorizedToday = MealLog::query()
            ->where('employee_id', $card->employee_id)
            ->where('meal_type', $selectedMealRuleId)
            ->where('status', 'approved')
            ->whereBetween('logged_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])
            ->exists();

        if ($alreadyAuthorizedToday) {
            return $this->rejected('already_authorized_today', 'Ce repas a deja ete autorise aujourd\'hui.', $employeeName);
        }

        $mealLog = DB::transaction(function () use ($card, $mealRule, $normalizedUid, $now, $selectedMealRuleId, $processedByUserId): MealLog {
            return MealLog::query()->create([
                'employee_id' => $card->employee_id,
                'card_id' => $card->id,
                'meal_rule_id' => $mealRule->id,
                'processed_by_user_id' => $processedByUserId,
                'card_uid' => $normalizedUid,
                'logged_at' => $now,
                'meal_type' => $selectedMealRuleId,
                'status' => 'approved',
                'amount_charged' => (float) $mealRule->cost,
                'reason' => 'Repas autorise.',
            ]);
        });

        return [
            'status' => 'success',
            'message' => 'Repas autorise.',
            'employee_name' => $employeeName,
            'reason' => 'authorized',
            'log_id' => $mealLog->id,
            'meal_type' => $selectedMealRuleId,
        ];
    }

    private function rejected(string $reason, string $message, ?string $employeeName = null): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'employee_name' => $employeeName,
            'reason' => $reason,
            'log_id' => null,
        ];
    }

    private function isTimeAllowed(string $start, string $end, string $current): bool
    {
        return MealRule::isTimeWithinRange($start, $end, $current);
    }
}
