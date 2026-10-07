<?php

namespace App\Http\Controllers;

use App\Models\Card;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LaReleveMealLog;
use App\Models\MealLog;
use App\Models\MealRule;
use Carbon\CarbonImmutable;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $approvedStatuses = ['approved', 'authorized'];
        $rejectedStatuses = ['rejected', 'denied'];
        $today = CarbonImmutable::now();
        $currentMonthStart = $today->startOfMonth()->toDateString();
        $currentMonthEnd = $today->endOfMonth()->toDateString();

        $filterMode = in_array((string) $request->string('mode'), ['day', 'range'], true)
            ? (string) $request->string('mode')
            : 'range';
        $sourceFilter = in_array((string) $request->string('source'), ['all', 'employee', 'la_releve', 'extra'], true)
            ? (string) $request->string('source')
            : 'all';

        $selectedDate = $this->safeDate((string) $request->string('date')) ?? $today->toDateString();
        $selectedDateFrom = $this->safeDate((string) $request->string('date_from'));
        $selectedDateTo = $this->safeDate((string) $request->string('date_to'));

        if ($filterMode === 'day') {
            $selectedDateFrom = $selectedDate;
            $selectedDateTo = $selectedDate;
        } else {
            if ($selectedDateFrom === null && $selectedDateTo === null) {
                $selectedDateFrom = $currentMonthStart;
                $selectedDateTo = $currentMonthEnd;
            } elseif ($selectedDateFrom === null) {
                $selectedDateFrom = $selectedDateTo;
            } elseif ($selectedDateTo === null) {
                $selectedDateTo = $selectedDateFrom;
            }
        }

        if ($selectedDateFrom !== null && $selectedDateTo !== null && $selectedDateFrom > $selectedDateTo) {
            [$selectedDateFrom, $selectedDateTo] = [$selectedDateTo, $selectedDateFrom];
        }

        $periodStart = CarbonImmutable::parse((string) $selectedDateFrom)->startOfDay();
        $periodEnd = CarbonImmutable::parse((string) $selectedDateTo)->endOfDay();

        $selectedMealRuleId = (int) $request->integer('meal_rule_id', 0);
        $selectedDepartmentId = (int) $request->integer('department_id', 0);

        $mealRules = MealRule::query()
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get(['id', 'name', 'cost', 'is_active']);

        if ($selectedMealRuleId > 0 && !$mealRules->contains(fn (MealRule $rule) => (int) $rule->id === $selectedMealRuleId)) {
            $selectedMealRuleId = 0;
        }

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($selectedDepartmentId > 0 && !$departments->contains(fn (Department $department) => (int) $department->id === $selectedDepartmentId)) {
            $selectedDepartmentId = 0;
        }

        $includeEmployee = in_array($sourceFilter, ['all', 'employee'], true);
        $includeLaReleve = in_array($sourceFilter, ['all', 'la_releve', 'extra'], true);

        $employeeLogsBaseQuery = MealLog::query()
            ->with(['employee.departmentModel', 'mealRule', 'card'])
            ->whereBetween('logged_at', [$periodStart, $periodEnd])
            ->when($selectedMealRuleId > 0, fn ($query) => $query->where('meal_rule_id', $selectedMealRuleId))
            ->when($selectedDepartmentId > 0, function ($query) use ($selectedDepartmentId): void {
                $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('department_id', $selectedDepartmentId));
            });

        if (!$includeEmployee) {
            $employeeLogsBaseQuery->whereRaw('1 = 0');
        }

        $employeeApprovedLogs = (clone $employeeLogsBaseQuery)
            ->whereIn('status', $approvedStatuses)
            ->get();

        $employeeDeniedLogs = (clone $employeeLogsBaseQuery)
            ->whereIn('status', $rejectedStatuses)
            ->get();

        $laReleveLogsBaseQuery = LaReleveMealLog::query()
            ->with(['entry', 'mealRule'])
            ->whereBetween('consumed_at', [$periodStart, $periodEnd])
            ->when($selectedMealRuleId > 0, fn ($query) => $query->where('meal_rule_id', $selectedMealRuleId));

        if (!$includeLaReleve) {
            $laReleveLogsBaseQuery->whereRaw('1 = 0');
        } elseif ($sourceFilter === 'extra') {
            $laReleveLogsBaseQuery->whereHas('entry', fn ($query) => $query->where('source', 'extra'));
        } elseif ($sourceFilter === 'la_releve') {
            $laReleveLogsBaseQuery->whereHas('entry', function ($query): void {
                $query->where(function ($subQuery): void {
                    $subQuery
                        ->where('source', 'la_releve')
                        ->orWhereNull('source');
                });
            });
        }

        $laReleveLogs = $laReleveLogsBaseQuery->get();

        $authorizedCount = $employeeApprovedLogs->count() + (int) $laReleveLogs->sum('quantity');
        $deniedCount = $employeeDeniedLogs->count();
        $totalActions = $authorizedCount + $deniedCount;
        $authorizationRate = $totalActions > 0 ? round(($authorizedCount / $totalActions) * 100, 1) : 0;

        $hourLabels = collect(range(0, 23))
            ->map(fn (int $h) => str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':00')
            ->values();

        $employeeApprovedByHour = $employeeApprovedLogs
            ->groupBy(fn (MealLog $log) => Carbon::parse($log->logged_at)->format('H'))
            ->map(fn ($group) => $group->count());

        $laReleveByHour = $laReleveLogs
            ->groupBy(fn (LaReleveMealLog $log) => Carbon::parse($log->consumed_at)->format('H'))
            ->map(fn ($group) => (int) $group->sum('quantity'));

        $mealsByHour = collect(range(0, 23))->map(function (int $hour) use ($employeeApprovedByHour, $laReleveByHour): int {
            $key = str_pad((string) $hour, 2, '0', STR_PAD_LEFT);

            return (int) ($employeeApprovedByHour[$key] ?? 0) + (int) ($laReleveByHour[$key] ?? 0);
        })->values();

        $days = collect();
        $cursor = $periodStart->startOfDay();
        while ($cursor->lessThanOrEqualTo($periodEnd->startOfDay())) {
            $days->push($cursor);
            $cursor = $cursor->addDay();
        }

        $authorizedEmployeeByDay = $employeeApprovedLogs
            ->groupBy(fn (MealLog $log) => Carbon::parse($log->logged_at)->toDateString())
            ->map(fn ($group) => $group->count());

        $authorizedLaReleveByDay = $laReleveLogs
            ->groupBy(fn (LaReleveMealLog $log) => Carbon::parse($log->consumed_at)->toDateString())
            ->map(fn ($group) => (int) $group->sum('quantity'));

        $deniedByDayCollection = $employeeDeniedLogs
            ->groupBy(fn (MealLog $log) => Carbon::parse($log->logged_at)->toDateString())
            ->map(fn ($group) => $group->count());

        $dayLabels = [];
        $authorizedByDay = [];
        $deniedByDay = [];

        foreach ($days as $day) {
            $dayKey = $day->toDateString();
            $dayLabels[] = $day->format('d/m');
            $authorizedByDay[] = (int) ($authorizedEmployeeByDay[$dayKey] ?? 0) + (int) ($authorizedLaReleveByDay[$dayKey] ?? 0);
            $deniedByDay[] = (int) ($deniedByDayCollection[$dayKey] ?? 0);
        }

        $departmentCounts = $employeeApprovedLogs
            ->groupBy(function (MealLog $log): string {
                $employee = $log->employee;

                if (!$employee) {
                    return 'Non attribue';
                }

                return $employee->departmentModel?->name
                    ?? ($employee->department ?: 'Non attribue');
            })
            ->map(fn ($group) => $group->count())
            ->sortDesc();

        $denialReasonCounts = $employeeDeniedLogs
            ->groupBy(fn (MealLog $log): string => trim((string) $log->reason) !== '' ? (string) $log->reason : 'Sans motif')
            ->map(fn ($group) => $group->count())
            ->sortDesc();

        $mealAggregation = [];
        $totalFinance = 0.0;

        foreach ($employeeApprovedLogs as $log) {
            $mealName = $log->mealRule?->name ?: ((string) $log->meal_type !== '' ? (string) $log->meal_type : 'Repas non defini');
            if (!array_key_exists($mealName, $mealAggregation)) {
                $mealAggregation[$mealName] = ['count' => 0, 'amount' => 0.0];
            }

            $amount = (float) ($log->amount_charged ?? $log->mealRule?->cost ?? 0);
            $mealAggregation[$mealName]['count']++;
            $mealAggregation[$mealName]['amount'] += $amount;
            $totalFinance += $amount;
        }

        foreach ($laReleveLogs as $log) {
            $mealName = $log->mealRule?->name ?: ((string) $log->meal_type !== '' ? (string) $log->meal_type : 'Repas non defini');
            if (!array_key_exists($mealName, $mealAggregation)) {
                $mealAggregation[$mealName] = ['count' => 0, 'amount' => 0.0];
            }

            $amount = (float) ($log->mealRule?->cost ?? 0);
            $mealAggregation[$mealName]['count'] += (int) $log->quantity;
            $mealAggregation[$mealName]['amount'] += $amount;
            $totalFinance += $amount;
        }

        $topMealsByCount = collect($mealAggregation)
            ->map(fn (array $row, string $label): array => [
                'label' => $label,
                'count' => (int) $row['count'],
                'amount' => (float) $row['amount'],
            ])
            ->sortByDesc('count')
            ->values();

        $topMealsByFinance = collect($mealAggregation)
            ->map(fn (array $row, string $label): array => [
                'label' => $label,
                'count' => (int) $row['count'],
                'amount' => (float) $row['amount'],
            ])
            ->sortByDesc('amount')
            ->values();

        $laReleveOnlyCount = (int) $laReleveLogs->filter(function (LaReleveMealLog $log): bool {
            return ($log->entry?->source ?? 'la_releve') !== 'extra';
        })->sum('quantity');
        $extraCount = (int) $laReleveLogs->filter(fn (LaReleveMealLog $log): bool => ($log->entry?->source ?? '') === 'extra')->sum('quantity');

        $latestMealLogs = (clone $employeeLogsBaseQuery)
            ->latest('logged_at')
            ->limit(10)
            ->get();

        $latestDeniedScans = $employeeDeniedLogs
            ->sortByDesc(fn (MealLog $log) => $log->logged_at)
            ->take(10)
            ->values();

        $latestLaReleveLogs = (clone $laReleveLogsBaseQuery)
            ->latest('consumed_at')
            ->limit(10)
            ->get();

        $recentCombinedActivity = collect()
            ->merge($latestMealLogs->map(function (MealLog $log): array {
                return [
                    'at' => optional($log->logged_at)->toDateTimeString(),
                    'source' => 'employe',
                    'person' => trim((string) (($log->employee?->first_name ?? '') . ' ' . ($log->employee?->last_name ?? ''))),
                    'meal' => (string) ($log->mealRule?->name ?? $log->meal_type ?? '-'),
                    'status' => (string) $log->status,
                    'amount' => (float) ($log->amount_charged ?? $log->mealRule?->cost ?? 0),
                ];
            }))
            ->merge($latestLaReleveLogs->map(function (LaReleveMealLog $log): array {
                $source = ($log->entry?->source ?? 'la_releve') === 'extra' ? 'extra' : 'la_releve';

                return [
                    'at' => optional($log->consumed_at)->toDateTimeString(),
                    'source' => $source,
                    'person' => (string) ($log->entry?->full_name ?? '-'),
                    'meal' => (string) ($log->mealRule?->name ?? $log->meal_type ?? '-'),
                    'status' => 'approved',
                    'amount' => (float) ($log->mealRule?->cost ?? 0),
                ];
            }))
            ->sortByDesc('at')
            ->take(12)
            ->values();

        $blockedCards = Card::query()
            ->with('employee')
            ->where(function ($query): void {
                $query
                    ->where('status', Card::STATUS_BLOCKED)
                    ->orWhere(function ($fallback): void {
                        $fallback->whereNull('status')->where('is_active', false);
                    });
            })
            ->latest('updated_at')
            ->limit(10)
            ->get();


        $kpis = [
            'meals_served_total' => $authorizedCount,
            'employee_meals' => $employeeApprovedLogs->count(),
            'la_releve_meals' => $laReleveOnlyCount,
            'extra_meals' => $extraCount,
            'denied_scans_total' => $deniedCount,
            'active_employees' => Employee::query()->where('is_active', true)->count(),
            'blocked_cards' => Card::query()
                ->where(function ($query): void {
                    $query
                        ->where('status', Card::STATUS_BLOCKED)
                        ->orWhere(function ($fallback): void {
                            $fallback->whereNull('status')->where('is_active', false);
                        });
                })
                ->count(),
            'authorization_rate_today' => $authorizationRate,
            'finance_total' => round($totalFinance, 2),
            'average_ticket' => $authorizedCount > 0 ? round($totalFinance / $authorizedCount, 2) : 0,
        ];

        return view('dashboard.index', [
            'kpis' => $kpis,
            'filters' => [
                'mode' => $filterMode,
                'date' => $selectedDate,
                'date_from' => $selectedDateFrom,
                'date_to' => $selectedDateTo,
                'source' => $sourceFilter,
                'meal_rule_id' => $selectedMealRuleId,
                'department_id' => $selectedDepartmentId,
            ],
            'mealRules' => $mealRules,
            'departments' => $departments,
            'chartMealsByHour' => [
                'labels' => $hourLabels->values()->all(),
                'data' => $mealsByHour->values()->all(),
            ],
            'chartAuthorizedDenied7d' => [
                'labels' => $dayLabels,
                'authorized' => $authorizedByDay,
                'denied' => $deniedByDay,
            ],
            'chartMealsByDepartment' => [
                'labels' => $departmentCounts->keys()->values()->all(),
                'data' => $departmentCounts->values()->all(),
            ],
            'chartDenialReasons' => [
                'labels' => $denialReasonCounts->keys()->values()->all(),
                'data' => $denialReasonCounts->values()->all(),
            ],
            'chartTopMeals' => [
                'labels' => $topMealsByCount->pluck('label')->values()->all(),
                'data' => $topMealsByCount->pluck('count')->values()->all(),
            ],
            'chartFinanceByMeal' => [
                'labels' => $topMealsByFinance->pluck('label')->values()->all(),
                'data' => $topMealsByFinance->pluck('amount')->map(fn (float $v) => round($v, 2))->values()->all(),
            ],
            'latestMealLogs' => $latestMealLogs,
            'latestDeniedScans' => $latestDeniedScans,
            'latestLaReleveLogs' => $latestLaReleveLogs,
            'recentCombinedActivity' => $recentCombinedActivity,
            'blockedCards' => $blockedCards,
        ]);
    }

    private function safeDate(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($normalized)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
