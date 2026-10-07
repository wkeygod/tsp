<?php

namespace App\Services;

use App\Models\LaReleveEntry;
use App\Models\LaReleveMealLog;
use App\Models\MealRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class LaReleveMealAccessService
{
    public const MEAL_TYPES = ['breakfast', 'lunch', 'dinner'];

    private function activeMealRules(): Collection
    {
        return MealRule::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return array<int, int|string>
     */
    private function normalizeAllowedMealTypes(LaReleveEntry $entry): array
    {
        return collect($entry->allowed_meal_types ?? [])
            ->map(function ($value) {
                if (is_numeric($value)) {
                    return (int) $value;
                }

                return trim((string) $value);
            })
            ->filter(fn ($value) => $value !== '')
            ->values()
            ->all();
    }

    private function mealRuleConsumed(LaReleveEntry $entry, string $date, MealRule $mealRule): bool
    {
        return $entry->mealLogs()
            ->whereDate('consumed_at', $date)
            ->where(function ($query) use ($mealRule): void {
                $query
                    ->where('meal_rule_id', $mealRule->id)
                    ->orWhere('meal_type', $mealRule->name);
            })
            ->exists();
    }

    private function mealRuleAllowed(LaReleveEntry $entry, MealRule $mealRule): bool
    {
        $allowedTypes = $this->normalizeAllowedMealTypes($entry);

        if (empty($allowedTypes)) {
            return true;
        }

        $normalizedAllowedStrings = collect($allowedTypes)
            ->filter(fn ($value) => is_string($value))
            ->map(fn (string $value) => $this->normalizeText($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $normalizedMealRuleName = $this->normalizeText($mealRule->name);

        if (in_array($normalizedMealRuleName, $normalizedAllowedStrings, true)) {
            return true;
        }

        $allowedLegacyTypes = collect($allowedTypes)
            ->map(fn ($value) => is_string($value) ? $this->normalizeLegacyMealType($value) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $mealRuleLegacyType = $this->resolveLegacyMealTypeFromRule($mealRule);
        if ($mealRuleLegacyType !== null && in_array($mealRuleLegacyType, $allowedLegacyTypes, true)) {
            return true;
        }

        return in_array($mealRule->id, $allowedTypes, true)
            || in_array((string) $mealRule->id, $allowedTypes, true)
            || in_array($mealRule->name, $allowedTypes, true);
    }

    private function normalizeText(string $value): string
    {
        return trim((string) Str::of($value)->lower()->ascii());
    }

    private function normalizeLegacyMealType(string $value): ?string
    {
        $normalized = $this->normalizeText($value);

        if ($normalized === '') {
            return null;
        }

        if (Str::contains($normalized, ['breakfast', 'petit'])) {
            return 'breakfast';
        }

        if (Str::contains($normalized, ['lunch', 'dejeuner'])) {
            return 'lunch';
        }

        if (Str::contains($normalized, ['dinner', 'diner'])) {
            return 'dinner';
        }

        return null;
    }

    private function resolveLegacyMealTypeFromRule(MealRule $mealRule): ?string
    {
        $typeFromName = $this->normalizeLegacyMealType((string) $mealRule->name);
        if ($typeFromName !== null) {
            return $typeFromName;
        }

        if (!$mealRule->start_time) {
            return null;
        }

        $hour = (int) substr((string) $mealRule->start_time, 0, 2);
        if ($hour < 11) {
            return 'breakfast';
        }

        if ($hour < 17) {
            return 'lunch';
        }

        return 'dinner';
    }

    /**
     * @return array<string, mixed>
     */
    public function search(string $term, ?string $selectedDate = null): array
    {
        $normalizedTerm = trim($term);

        if ($normalizedTerm === '') {
            return [
                'found' => false,
                'message' => 'Saisissez un nom, un poste ou une entreprise.',
            ];
        }

        $date = $this->resolveDate($selectedDate);
        $entry = $this->findEntryForDate($normalizedTerm, $date);

        if (!$entry) {
            return [
                'found' => false,
                'date' => $date,
                'search_term' => $normalizedTerm,
                'message' => 'Aucune entrée La Releve trouvée pour cette date.',
            ];
        }

        return $this->buildPayload($entry, $date, $normalizedTerm);
    }

    /**
     * @return array<string, mixed>
     */
    public function consume(
        int $entryId,
        string $mealType,
        ?string $selectedDate,
        ?int $mealRuleId,
        ?int $processedByUserId,
        ?string $notes = null
    ): array {
        $date = $this->resolveDate($selectedDate);

        $entry = LaReleveEntry::query()
            ->whereKey($entryId)
            ->forDate($date)
            ->first();

        if (!$entry) {
            return [
                'status' => 'error',
                'message' => 'Entrée La Releve introuvable pour la date sélectionnée.',
            ];
        }

        $mealTypeCheck = $this->validateMealType($entry, $mealType, $date);
        if ($mealTypeCheck !== null) {
            return $mealTypeCheck;
        }

        $authorization = $this->evaluateAuthorization($entry, $date);
        if (!$authorization['can_eat_now']) {
            return [
                'status' => 'error',
                'message' => 'Refusé: quota de repas atteint pour cette date.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        DB::transaction(function () use ($entry, $mealType, $mealRuleId, $processedByUserId, $notes, $date): void {
            LaReleveMealLog::query()->create([
                'la_releve_entry_id' => $entry->id,
                'meal_type' => $mealType,
                'consumed_at' => $this->buildConsumedAt($date),
                'meal_rule_id' => $mealRuleId,
                'processed_by_user_id' => $processedByUserId,
                'notes' => $notes,
            ]);
        });

        $freshEntry = $entry->fresh();

        return [
            'status' => 'success',
            'message' => 'Repas enregistré avec succès.',
            'search' => $this->buildPayload($freshEntry, $date),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function consumeWithMealRule(
        int $entryId,
        int $mealRuleId,
        ?string $selectedDate,
        ?int $processedByUserId,
        ?string $notes = null
    ): array {
        $date = $this->resolveDate($selectedDate);

        $entry = LaReleveEntry::query()
            ->whereKey($entryId)
            ->forDate($date)
            ->first();

        if (!$entry) {
            return [
                'status' => 'error',
                'message' => 'Entrée La Releve introuvable pour la date sélectionnée.',
            ];
        }

        $mealRule = MealRule::query()
            ->whereKey($mealRuleId)
            ->where('is_active', true)
            ->first();

        if (!$mealRule) {
            return [
                'status' => 'error',
                'message' => 'Règle repas invalide ou inactive.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        if (!$this->mealRuleAllowed($entry, $mealRule)) {
            return [
                'status' => 'error',
                'message' => 'Ce repas n\'est pas autorisé pour cette personne.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        if ($this->mealRuleConsumed($entry, $date, $mealRule)) {
            return [
                'status' => 'error',
                'message' => 'Ce repas est déjà enregistré pour cette date.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        $authorization = $this->evaluateAuthorization($entry, $date);
        if (!$authorization['can_eat_now']) {
            return [
                'status' => 'error',
                'message' => 'Refusé: quota de repas atteint pour cette date.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        DB::transaction(function () use ($entry, $mealRule, $processedByUserId, $notes, $date): void {
            LaReleveMealLog::query()->create([
                'la_releve_entry_id' => $entry->id,
                'meal_type' => $mealRule->name,
                'consumed_at' => $this->buildConsumedAt($date),
                'meal_rule_id' => $mealRule->id,
                'processed_by_user_id' => $processedByUserId,
                'notes' => $notes,
            ]);
        });

        $freshEntry = $entry->fresh();

        return [
            'status' => 'success',
            'message' => 'Repas enregistré avec succès.',
            'search' => $this->buildPayload($freshEntry, $date),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleConsumption(
        int $entryId,
        bool $isChecked,
        string $mealType,
        ?string $selectedDate,
        ?int $processedByUserId,
        ?string $notes = null
    ): array {
        if ($isChecked) {
            return $this->consume($entryId, $mealType, $selectedDate, null, $processedByUserId, $notes);
        }

        return $this->revokeLatestConsumption($entryId, $mealType, $selectedDate);
    }

    /**
     * @return array<string, mixed>
     */
    public function toggleConsumptionWithMealRule(
        int $entryId,
        bool $isChecked,
        int $mealRuleId,
        ?string $selectedDate,
        ?int $processedByUserId,
        ?string $notes = null
    ): array {
        if ($isChecked) {
            return $this->consumeWithMealRule($entryId, $mealRuleId, $selectedDate, $processedByUserId, $notes);
        }

        return $this->revokeLatestConsumptionByMealRule($entryId, $mealRuleId, $selectedDate);
    }

    /**
     * Sets (creates, edits in place, or removes when 0) the meal quantity for
     * an Extra kiosk entry. Dedicated to the Extra flow: unlike
     * toggleConsumptionWithMealRule (used by La Relève), this allows editing
     * an already-recorded quantity instead of rejecting a second click.
     *
     * @return array<string, mixed>
     */
    public function setExtraMealQuantity(
        int $entryId,
        int $mealRuleId,
        int $quantity,
        ?string $selectedDate,
        ?int $processedByUserId,
        ?string $notes = null
    ): array {
        $date = $this->resolveDate($selectedDate);
        $quantity = max(0, $quantity);

        $entry = LaReleveEntry::query()
            ->whereKey($entryId)
            ->forDate($date)
            ->first();

        if (!$entry) {
            return [
                'status' => 'error',
                'message' => 'Entrée Extra introuvable pour la date sélectionnée.',
            ];
        }

        $mealRule = MealRule::query()
            ->whereKey($mealRuleId)
            ->where('is_active', true)
            ->first();

        if (!$mealRule) {
            return [
                'status' => 'error',
                'message' => 'Règle repas invalide ou inactive.',
            ];
        }

        if (!$this->mealRuleAllowed($entry, $mealRule)) {
            return [
                'status' => 'error',
                'message' => 'Ce repas n\'est pas autorisé pour cette personne.',
            ];
        }

        $existingLog = $this->findMealLog($entry, $mealRule, $date);

        if ($quantity === 0) {
            if ($existingLog) {
                $existingLog->delete();
            }

            return [
                'status' => 'success',
                'message' => 'Repas retiré.',
            ];
        }

        DB::transaction(function () use ($existingLog, $entry, $mealRule, $quantity, $processedByUserId, $notes, $date): void {
            if ($existingLog) {
                $existingLog->update([
                    'quantity' => $quantity,
                    'processed_by_user_id' => $processedByUserId,
                    'notes' => $notes,
                ]);

                return;
            }

            LaReleveMealLog::query()->create([
                'la_releve_entry_id' => $entry->id,
                'meal_type' => $mealRule->name,
                'consumed_at' => $this->buildConsumedAt($date),
                'meal_rule_id' => $mealRule->id,
                'quantity' => $quantity,
                'processed_by_user_id' => $processedByUserId,
                'notes' => $notes,
            ]);
        });

        return [
            'status' => 'success',
            'message' => 'Quantité enregistrée.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function suggestExtraNames(string $term, int $limit = 10): array
    {
        $normalizedTerm = $this->normalizeText(trim($term));

        if ($normalizedTerm === '') {
            return [];
        }

        return LaReleveEntry::query()
            ->where('source', 'extra')
            ->latest('id')
            ->limit(300)
            ->pluck('full_name')
            ->unique()
            ->filter(fn (string $name): bool => Str::contains($this->normalizeText($name), $normalizedTerm))
            ->take($limit)
            ->values()
            ->all();
    }

    private function findMealLog(LaReleveEntry $entry, MealRule $mealRule, string $date): ?LaReleveMealLog
    {
        return LaReleveMealLog::query()
            ->where('la_releve_entry_id', $entry->id)
            ->where(function ($query) use ($mealRule): void {
                $query
                    ->where('meal_rule_id', $mealRule->id)
                    ->orWhere('meal_type', $mealRule->name);
            })
            ->whereDate('consumed_at', $date)
            ->orderByDesc('consumed_at')
            ->orderByDesc('id')
            ->first();
    }

    private function resolveDate(?string $selectedDate): string
    {
        $candidate = trim((string) $selectedDate);

        if ($candidate === '') {
            return now()->toDateString();
        }

        return CarbonImmutable::parse($candidate)->toDateString();
    }

    private function findEntryForDate(string $term, string $date): ?LaReleveEntry
    {
        return LaReleveEntry::query()
            ->forLaReleve()
            ->forDate($date)
            ->where(function ($query) use ($term): void {
                $query
                    ->where('full_name', 'like', '%' . $term . '%')
                    ->orWhere('position', 'like', '%' . $term . '%')
                    ->orWhere('rig_company', 'like', '%' . $term . '%');
            })
            ->orderBy('full_name')
            ->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function validateMealType(LaReleveEntry $entry, string $mealType, string $date): ?array
    {
        if (!in_array($mealType, self::MEAL_TYPES, true)) {
            return [
                'status' => 'error',
                'message' => 'Type de repas invalide.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        $allowedTypes = collect($entry->allowed_meal_types ?? [])
            ->filter(fn($type) => in_array($type, self::MEAL_TYPES, true))
            ->values()
            ->all();
        if (!empty($allowedTypes) && !in_array($mealType, $allowedTypes, true)) {
            return [
                'status' => 'error',
                'message' => 'Ce type de repas n\'est pas autorisé pour cette personne.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        $alreadyConsumedThisType = $entry->mealLogs()
            ->whereDate('consumed_at', $date)
            ->where('meal_type', $mealType)
            ->exists();

        if ($alreadyConsumedThisType) {
            return [
                'status' => 'error',
                'message' => 'Ce type de repas est deja enregistre pour cette date.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        return null;
    }

    /**
     * @return array{already_ate:bool,consumed_count:int,remaining_count:int,can_eat_now:bool}
     */
    private function evaluateAuthorization(LaReleveEntry $entry, string $date): array
    {
        $mealRules = $this->activeMealRules();

        $mealTypeStatus = $mealRules->mapWithKeys(function (MealRule $mealRule) use ($entry, $date): array {
            $hasConsumed = $this->mealRuleConsumed($entry, $date, $mealRule);

            return [(string) $mealRule->id => $hasConsumed ? 1 : 0];
        })->all();

        $consumedCount = array_sum($mealTypeStatus);

        $allowedCount = count(array_values(array_unique($this->normalizeAllowedMealTypes($entry))));
        if ($allowedCount <= 0) {
            $allowedCount = $mealRules->count();
        }
        $remainingCount = max(0, $allowedCount - $consumedCount);

        return [
            'already_ate' => $consumedCount > 0,
            'consumed_count' => $consumedCount,
            'remaining_count' => $remainingCount,
            'can_eat_now' => $remainingCount > 0,
            'meal_type_status' => $mealTypeStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revokeLatestConsumptionByMealRule(int $entryId, int $mealRuleId, ?string $selectedDate): array
    {
        $date = $this->resolveDate($selectedDate);

        $entry = LaReleveEntry::query()
            ->whereKey($entryId)
            ->forDate($date)
            ->first();

        if (!$entry) {
            return [
                'status' => 'error',
                'message' => 'Entrée La Releve introuvable pour la date sélectionnée.',
            ];
        }

        $mealRule = MealRule::query()->whereKey($mealRuleId)->first();
        if (!$mealRule) {
            return [
                'status' => 'error',
                'message' => 'Règle repas invalide.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        $latestLog = LaReleveMealLog::query()
            ->where('la_releve_entry_id', $entry->id)
            ->where(function ($query) use ($mealRule): void {
                $query
                    ->where('meal_rule_id', $mealRule->id)
                    ->orWhere('meal_type', $mealRule->name);
            })
            ->whereDate('consumed_at', $date)
            ->orderByDesc('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (!$latestLog) {
            return [
                'status' => 'error',
                'message' => 'Aucun repas de ce type à annuler pour cette date.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        DB::transaction(function () use ($latestLog): void {
            $latestLog->delete();
        });

        $freshEntry = $entry->fresh();

        return [
            'status' => 'success',
            'message' => 'Dernier repas annulé avec succès.',
            'search' => $this->buildPayload($freshEntry, $date),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(LaReleveEntry $entry, string $date, ?string $searchTerm = null): array
    {
        $status = $this->evaluateAuthorization($entry, $date);

        return [
            'found' => true,
            'date' => $date,
            'search_term' => $searchTerm,
            'entry' => [
                'id' => $entry->id,
                'full_name' => $entry->full_name,
                'position' => $entry->position,
                'rig_company' => $entry->rig_company,
            ],
            'status' => $status,
            'message' => $status['can_eat_now']
                ? 'Autorisé: repas possible maintenant.'
                : 'Refusé: quota atteint.',
        ];
    }

    private function buildConsumedAt(string $date): CarbonImmutable
    {
        $currentTime = now()->format('H:i:s');

        return CarbonImmutable::parse($date . ' ' . $currentTime);
    }

    /**
     * @return array<string, mixed>
     */
    private function revokeLatestConsumption(int $entryId, string $mealType, ?string $selectedDate): array
    {
        $date = $this->resolveDate($selectedDate);

        $entry = LaReleveEntry::query()
            ->whereKey($entryId)
            ->forDate($date)
            ->first();

        if (!$entry) {
            return [
                'status' => 'error',
                'message' => 'Entrée La Releve introuvable pour la date sélectionnée.',
            ];
        }

        $latestLog = LaReleveMealLog::query()
            ->where('la_releve_entry_id', $entry->id)
            ->where('meal_type', $mealType)
            ->whereDate('consumed_at', $date)
            ->orderByDesc('consumed_at')
            ->orderByDesc('id')
            ->first();

        if (!$latestLog) {
            return [
                'status' => 'error',
                'message' => 'Aucun repas de ce type à annuler pour cette date.',
                'search' => $this->buildPayload($entry, $date),
            ];
        }

        DB::transaction(function () use ($latestLog): void {
            $latestLog->delete();
        });

        $freshEntry = $entry->fresh();

        return [
            'status' => 'success',
            'message' => 'Dernier repas annulé avec succès.',
            'search' => $this->buildPayload($freshEntry, $date),
        ];
    }
}
