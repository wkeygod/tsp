<?php

namespace App\Http\Controllers;

use App\Http\Requests\KioskLaReleveConsumeRequest;
use App\Http\Requests\KioskLaReleveSearchRequest;
use App\Http\Requests\KioskLaReleveToggleRequest;
use App\Models\LaReleveEntry;
use App\Models\MealLog;
use App\Models\MealRule;
use App\Services\LaReleveMealAccessService;
use App\Services\MealValidationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KioskController extends Controller
{
    public function __construct(
        private readonly MealValidationService $mealValidationService,
        private readonly LaReleveMealAccessService $laReleveMealAccessService
    )
    {
    }

    public function index()
    {
        $mealRules = MealRule::where('is_active', true)->orderBy('name')->get();
        $lastLogs = MealLog::query()->with(['employee', 'card', 'mealRule'])->latest('logged_at')->limit(10)->get();
        $selectedMealType = session('kiosk_selected_meal_type');
        $selectedRule = $mealRules->first(fn ($rule) => (string) $rule->id === (string) $selectedMealType);

        // Si la selection en session est absente/invalide, ou si son horaire est deja
        // termine, on bascule automatiquement sur le repas dont la plage horaire
        // (definie dans les reglages de repas) contient l'heure actuelle.
        $now = now()->format('H:i:s');
        $stillWithinSelection = $selectedRule
            && MealRule::isTimeWithinRange((string) $selectedRule->start_time, (string) $selectedRule->end_time, $now);

        if (!$stillWithinSelection) {
            $activeRule = MealRule::currentlyActive($mealRules);
            $selectedMealType = (string) ($activeRule?->id ?? $mealRules->first()?->id ?? '');
        } else {
            $selectedMealType = (string) $selectedRule->id;
        }

        // Evite que le navigateur restaure cette page depuis son cache/back-forward-cache
        // (tablette qui se met en veille puis se reveille) avec un ancien jeton CSRF et
        // une selection de repas perimee : on force un rechargement frais a chaque fois.
        return response()
            ->view('kiosk.index', [
                'mealRules' => $mealRules,
                'lastLogs' => $lastLogs,
                'selectedMealType' => $selectedMealType,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function laRelevePage(Request $request)
    {
        $filterMode = trim((string) $request->string('filter_mode'));
        $filterMode = in_array($filterMode, ['day', 'range'], true) ? $filterMode : 'day';

        $selectedDate = trim((string) $request->string('list_date'));
        $selectedDate = $selectedDate !== '' ? CarbonImmutable::parse($selectedDate)->toDateString() : now()->toDateString();
        $selectedDateFrom = trim((string) $request->string('date_from'));
        $selectedDateFrom = $selectedDateFrom !== '' ? CarbonImmutable::parse($selectedDateFrom)->toDateString() : $selectedDate;
        $selectedDateTo = trim((string) $request->string('date_to'));
        $selectedDateTo = $selectedDateTo !== '' ? CarbonImmutable::parse($selectedDateTo)->toDateString() : $selectedDate;

        if ($selectedDateFrom > $selectedDateTo) {
            [$selectedDateFrom, $selectedDateTo] = [$selectedDateTo, $selectedDateFrom];
        }

        $searchQuery = trim((string) $request->string('q'));

        $entriesQuery = LaReleveEntry::query()
            ->forLaReleve()
            ->search($searchQuery);
        if ($filterMode === 'range') {
            $entriesQuery->where(function ($query) use ($selectedDateFrom, $selectedDateTo) {
                $query->where(function ($q) use ($selectedDateFrom, $selectedDateTo) {
                    $q->whereNotNull('end_date')
                      ->whereDate('entry_date', '<=', $selectedDateTo)
                      ->whereDate('end_date', '>=', $selectedDateFrom);
                })
                ->orWhere(function ($q) use ($selectedDateFrom, $selectedDateTo) {
                    $q->whereNull('end_date')
                      ->whereBetween('entry_date', [$selectedDateFrom, $selectedDateTo]);
                });
            });
        } else {
            $entriesQuery->forDate($selectedDate);
        }

        $entries = $entriesQuery
            ->with(['mealLogs' => function ($query) use ($selectedDateFrom, $selectedDateTo): void {
                $query
                    ->whereBetween('consumed_at', [$selectedDateFrom . ' 00:00:00', $selectedDateTo . ' 23:59:59'])
                    ->select('id', 'la_releve_entry_id', 'meal_type', 'consumed_at', 'meal_rule_id');
            }])
            ->withCount([
                'mealLogs as consumed_meals_count' => function ($query) use ($filterMode, $selectedDate, $selectedDateFrom, $selectedDateTo): void {
                    if ($filterMode === 'range') {
                        $query->whereBetween('consumed_at', [$selectedDateFrom . ' 00:00:00', $selectedDateTo . ' 23:59:59']);
                    } else {
                        $query->whereDate('consumed_at', $selectedDate);
                    }
                },
            ])
            ->orderBy('full_name')
            ->paginate(20)
            ->withQueryString();

        $mealRules = MealRule::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('kiosk.la-releve', [
            'entries' => $entries,
            'filterMode' => $filterMode,
            'selectedDate' => $selectedDate,
            'selectedDateFrom' => $selectedDateFrom,
            'selectedDateTo' => $selectedDateTo,
            'searchQuery' => $searchQuery,
            'mealRules' => $mealRules,
            'laReleveSearch' => session('kiosk_la_releve_search'),
            'laReleveResult' => session('kiosk_la_releve_result'),
        ]);
    }

    public function extraPage(Request $request)
    {
        $selectedDate = trim((string) $request->string('list_date'));
        $selectedDate = $selectedDate !== '' ? CarbonImmutable::parse($selectedDate)->toDateString() : now()->toDateString();
        $searchQuery = trim((string) $request->string('q'));

        $mealRules = MealRule::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        $entries = LaReleveEntry::query()
            ->where('source', 'extra')
            ->forDate($selectedDate)
            ->search($searchQuery)
            ->with(['mealLogs' => function ($query) use ($selectedDate): void {
                $query
                    ->whereBetween('consumed_at', [$selectedDate . ' 00:00:00', $selectedDate . ' 23:59:59'])
                    ->select('id', 'la_releve_entry_id', 'meal_type', 'consumed_at', 'meal_rule_id', 'quantity');
            }])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('kiosk.extra', [
            'entries' => $entries,
            'selectedDate' => $selectedDate,
            'searchQuery' => $searchQuery,
            'mealRules' => $mealRules,
            'extraResult' => session('kiosk_extra_result') ?? session('kiosk_la_releve_result'),
        ]);
    }

    public function storeExtra(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company' => ['required', 'string', 'max:120'],
            'rig' => ['required', 'string', 'max:120'],
            'room_number' => ['nullable', 'string', 'max:50'],
            'selected_date' => ['nullable', 'date'],
        ]);

        $selectedDate = trim((string) ($validated['selected_date'] ?? ''));
        $date = $selectedDate !== '' ? CarbonImmutable::parse($selectedDate)->toDateString() : now()->toDateString();

        $entry = LaReleveEntry::query()->create([
            'full_name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
            'external_id' => 'EXTRA-' . strtoupper(Str::random(10)),
            'position' => $validated['rig'],
            'rig_company' => $validated['company'],
            'room_number' => $validated['room_number'] ?? null,
            'entry_date' => $date,
            'notes' => 'Entree rapide kiosque Extra (sans consommation immediate)',
            'allowed_meal_types' => MealRule::query()->where('is_active', true)->pluck('id')->all(),
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
            'source' => 'extra',
        ]);

        $result = [
            'status' => 'success',
            'message' => 'Personne Extra enregistree. Choisissez maintenant le repas via les boutons de la ligne.',
            'entry_id' => $entry->id,
        ];

        return redirect()
            ->route('kiosk.extra.page', ['list_date' => $date])
            ->with('kiosk_extra_result', $result);
    }

    public function toggleExtra(Request $request)
    {
        $validated = $request->validate([
            'entry_id' => ['required', 'integer', 'exists:la_releve_entries,id'],
            'quantity' => ['required', 'integer', 'min:0', 'max:999'],
            'meal_rule_id' => ['required', 'integer', 'exists:meal_rules,id'],
            'selected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = LaReleveEntry::query()->find((int) $validated['entry_id']);
        if (!$entry || $entry->source !== 'extra') {
            return back()->with('kiosk_extra_result', [
                'status' => 'error',
                'message' => 'Entree Extra introuvable.',
            ]);
        }

        $result = $this->laReleveMealAccessService->setExtraMealQuantity(
            (int) $validated['entry_id'],
            (int) $validated['meal_rule_id'],
            (int) $validated['quantity'],
            $validated['selected_date'] ?? null,
            $request->user()?->id,
            $validated['notes'] ?? null,
        );

        return back()->with('kiosk_extra_result', $result);
    }

    public function suggestExtraNames(Request $request)
    {
        $term = trim((string) $request->string('q'));

        return response()->json([
            'suggestions' => $this->laReleveMealAccessService->suggestExtraNames($term),
        ]);
    }

    public function scan(Request $request)
    {
        $request->merge([
            'card_uid' => \App\Models\Card::normalizeUid((string) $request->input('card_uid')),
        ]);

        $activeMealRules = MealRule::where('is_active', true)->get();
        $activeMealRuleIds = $activeMealRules->map(fn ($rule) => (string) $rule->id)->all();
        $fallbackMealType = $request->session()->get('kiosk_selected_meal_type');
        if (!$fallbackMealType || !in_array((string) $fallbackMealType, $activeMealRuleIds, true)) {
            $fallbackMealType = (string) (MealRule::currentlyActive($activeMealRules)?->id ?? $activeMealRuleIds[0] ?? '');
            $fallbackMealType = $fallbackMealType !== '' ? $fallbackMealType : null;
        }

        $validated = $request->validate([
            'card_uid' => ['required', 'string', 'max:100'],
            'meal_type' => ['nullable', 'string'],
        ]);

        $selectedMealType = $validated['meal_type'] ?? null;
        if (!$selectedMealType || !in_array((string) $selectedMealType, $activeMealRuleIds, true)) {
            $selectedMealType = $fallbackMealType;
        }

        if ($selectedMealType === null) {
            return back()->with('kiosk_result', [
                'status' => 'error',
                'message' => 'Aucune règle de repas active. Veuillez activer au moins un repas avant de scanner.',
            ]);
        }

        $request->session()->put('kiosk_selected_meal_type', $selectedMealType);

        $result = $this->mealValidationService->validateAndAuthorize(
            $validated['card_uid'],
            mealType: $selectedMealType
        );

        return back()->with('kiosk_result', $result);
    }

    public function searchLaReleve(KioskLaReleveSearchRequest $request)
    {
        $validated = $request->validated();

        $search = $this->laReleveMealAccessService->search(
            $validated['search_term'],
            $validated['selected_date'] ?? null
        );

        return back()
            ->with('kiosk_la_releve_search', $search)
            ->with('kiosk_la_releve_result', null)
            ->withInput();
    }

    public function consumeLaReleve(KioskLaReleveConsumeRequest $request)
    {
        $validated = $request->validated();

        $entry = LaReleveEntry::query()->find((int) $validated['entry_id']);
        if (!$entry || $entry->source === 'extra') {
            return back()->with('kiosk_la_releve_result', [
                'status' => 'error',
                'message' => 'Entree La Releve introuvable.',
            ]);
        }

        $result = $this->laReleveMealAccessService->consumeWithMealRule(
            (int) $validated['entry_id'],
            (int) $validated['meal_rule_id'],
            $validated['selected_date'] ?? null,
            $request->user()?->id,
            $validated['notes'] ?? null,
        );

        return back()
            ->with('kiosk_la_releve_result', $result)
            ->with('kiosk_la_releve_search', $result['search'] ?? null);
    }

    public function toggleLaReleve(KioskLaReleveToggleRequest $request)
    {
        $validated = $request->validated();

        $entry = LaReleveEntry::query()->find((int) $validated['entry_id']);
        if (!$entry || $entry->source === 'extra') {
            return back()->with('kiosk_la_releve_result', [
                'status' => 'error',
                'message' => 'Entree La Releve introuvable.',
            ]);
        }

        $result = $this->laReleveMealAccessService->toggleConsumptionWithMealRule(
            (int) $validated['entry_id'],
            (bool) $validated['is_checked'],
            (int) $validated['meal_rule_id'],
            $validated['selected_date'] ?? null,
            $request->user()?->id,
            $validated['notes'] ?? null,
        );

        return back()->with('kiosk_la_releve_result', $result);
    }

}
