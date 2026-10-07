<?php

namespace App\Http\Controllers;

use App\Models\LaReleveEntry;
use App\Models\LaReleveMealLog;
use App\Models\MealRule;
use App\Services\BrandedExcelExportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class ExtraDashboardController extends Controller
{
    public function index(Request $request): View
    {
        [$selectedDateFrom, $selectedDateTo, $search, $company, $rig] = $this->resolveFilters($request);
        $mealRuleNamesById = MealRule::query()->pluck('name', 'id')->all();

        $filteredEntriesQuery = $this->buildFilteredEntriesQuery(
            $selectedDateFrom,
            $selectedDateTo,
            $search,
            $company,
            $rig
        );

        $entries = (clone $filteredEntriesQuery)
            ->with(['mealLogs' => function ($query) use ($selectedDateFrom, $selectedDateTo): void {
                $query
                    ->when($selectedDateFrom !== null && $selectedDateTo !== null, function ($mealLogQuery) use ($selectedDateFrom, $selectedDateTo): void {
                        $mealLogQuery->whereBetween('consumed_at', [$selectedDateFrom . ' 00:00:00', $selectedDateTo . ' 23:59:59']);
                    })
                    ->select('id', 'la_releve_entry_id', 'meal_type', 'meal_rule_id', 'consumed_at', 'quantity');
            }])
            ->withSum([
                'mealLogs as consumed_meals_count' => function ($query): void {
                    $query->whereRaw('date(consumed_at) = date(la_releve_entries.entry_date)');
                },
            ], 'quantity')
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        $entries->setCollection(
            $entries->getCollection()->map(function (LaReleveEntry $entry) use ($mealRuleNamesById): LaReleveEntry {
                $entry->setAttribute('consumed_meals_count', (int) $entry->consumed_meals_count);

                $entryDate = optional($entry->entry_date)->format('Y-m-d');
                $flags = [
                    'breakfast' => 0,
                    'lunch' => 0,
                    'dinner' => 0,
                ];

                foreach ($entry->mealLogs as $log) {
                    if (optional($log->consumed_at)->format('Y-m-d') !== $entryDate) {
                        continue;
                    }

                    $bucket = $this->resolveMealBucket((string) $log->meal_type, $log->meal_rule_id, $mealRuleNamesById);
                    if ($bucket !== null) {
                        $flags[$bucket] += (int) $log->quantity;
                    }
                }

                $entry->setAttribute('meal_flags', $flags);

                return $entry;
            })
        );

        $entriesForSummary = (clone $filteredEntriesQuery)
            ->withSum([
                'mealLogs as consumed_meals_count' => function ($query): void {
                    $query->whereRaw('date(consumed_at) = date(la_releve_entries.entry_date)');
                },
            ], 'quantity')
            ->get(['id', 'rig_company', 'position'])
            ->each(function (LaReleveEntry $entry): void {
                $entry->setAttribute('consumed_meals_count', (int) $entry->consumed_meals_count);
            });

        $entryIds = $entriesForSummary->pluck('id');

        $logsQuery = LaReleveMealLog::query()
            ->when(
                $entryIds->isNotEmpty(),
                fn ($query) => $query->whereIn('la_releve_entry_id', $entryIds->all()),
                fn ($query) => $query->whereRaw('1 = 0')
            );

        $mealTypeStats = $this->buildMealTypeStats((clone $logsQuery)->get(['meal_type', 'meal_rule_id', 'quantity']), $mealRuleNamesById);

        $dailyStats = $entriesForSummary
            ->groupBy(function ($entry): string {
                return optional($entry->entry_date)->format('Y-m-d') ?: 'Sans date';
            })
            ->map(function (Collection $rows, string $label): array {
                return [
                    'label' => $label,
                    'entries' => $rows->count(),
                    'meals' => (int) $rows->sum('consumed_meals_count'),
                ];
            })
            ->sortByDesc('label')
            ->take(12)
            ->values();

        $mealTypeGraph = [
            ['label' => 'Petit-dejeuner', 'value' => $mealTypeStats['breakfast']],
            ['label' => 'Dejeuner', 'value' => $mealTypeStats['lunch']],
            ['label' => 'Diner', 'value' => $mealTypeStats['dinner']],
        ];

        $dailyGraphMax = max(1, (int) max($dailyStats->max('entries') ?? 0, $dailyStats->max('meals') ?? 0));
        $mealGraphMax = max(1, (int) collect($mealTypeGraph)->max('value'));

        $dailyGraphRows = $dailyStats->map(function (array $row) use ($dailyGraphMax): array {
            return [
                'label' => $row['label'],
                'entries' => $row['entries'],
                'meals' => $row['meals'],
                'entry_pct' => (int) round(($row['entries'] / $dailyGraphMax) * 100),
                'meal_pct' => (int) round(($row['meals'] / $dailyGraphMax) * 100),
                'entry_bar' => str_repeat('█', max(1, (int) round(($row['entries'] / $dailyGraphMax) * 18))),
                'meal_bar' => str_repeat('█', max(1, (int) round(($row['meals'] / $dailyGraphMax) * 18))),
            ];
        })->values();

        $mealGraphRows = collect($mealTypeGraph)->map(function (array $item) use ($mealGraphMax): array {
            return [
                'label' => $item['label'],
                'value' => $item['value'],
                'pct' => (int) round(($item['value'] / $mealGraphMax) * 100),
                'bar' => str_repeat('█', max(1, (int) round(($item['value'] / $mealGraphMax) * 20))),
            ];
        })->values();

        $summary = [
            'total_entries' => $entriesForSummary->count(),
            'with_meal' => $entriesForSummary->where('consumed_meals_count', '>', 0)->count(),
            'without_meal' => $entriesForSummary->where('consumed_meals_count', '=', 0)->count(),
            'total_meals' => (int) (clone $logsQuery)->sum('quantity'),
            'breakfast' => $mealTypeStats['breakfast'],
            'lunch' => $mealTypeStats['lunch'],
            'dinner' => $mealTypeStats['dinner'],
        ];

        $topCompanies = $this->buildTopStats($entriesForSummary, 'rig_company');
        $topRigs = $this->buildTopStats($entriesForSummary, 'position');

        return view('extra.index', [
            'entries' => $entries,
            'summary' => $summary,
            'selectedDateFrom' => $selectedDateFrom,
            'selectedDateTo' => $selectedDateTo,
            'search' => $search,
            'company' => $company,
            'rig' => $rig,
            'topCompanies' => $topCompanies,
            'topRigs' => $topRigs,
            'dailyGraphRows' => $dailyGraphRows,
            'mealGraphRows' => $mealGraphRows,
        ]);
    }

    public function export(Request $request)
    {
        [$selectedDateFrom, $selectedDateTo, $search, $company, $rig] = $this->resolveFilters($request);
        $format = trim((string) $request->string('format'));
        $format = in_array($format, ['csv', 'excel'], true) ? $format : 'csv';

        $entries = $this->buildFilteredEntriesQuery(
            $selectedDateFrom,
            $selectedDateTo,
            $search,
            $company,
            $rig
        )
            ->with(['mealLogs' => function ($query) use ($selectedDateFrom, $selectedDateTo): void {
                $query
                    ->when($selectedDateFrom !== null && $selectedDateTo !== null, function ($mealLogQuery) use ($selectedDateFrom, $selectedDateTo): void {
                        $mealLogQuery->whereBetween('consumed_at', [$selectedDateFrom . ' 00:00:00', $selectedDateTo . ' 23:59:59']);
                    })
                    ->select('id', 'la_releve_entry_id', 'meal_type', 'meal_rule_id', 'consumed_at', 'quantity');
            }])
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->get(['id', 'entry_date', 'created_at', 'external_id', 'full_name', 'rig_company', 'position']);

        if ($entries->isEmpty()) {
            return response()->json([
                'error' => 'Impossible d\'exporter : aucune donnée disponible avec ces filtres.',
            ], 422);
        }

        if ($format === 'excel') {
            $mealRuleNamesById = MealRule::query()->pluck('name', 'id')->all();

            $rows = [];
            $companySummary = collect();
            $rigSummary = collect();

            foreach ($entries as $entry) {
                $entryDate = optional($entry->entry_date)->format('Y-m-d');
                $consumedForDate = $entry->mealLogs->filter(
                    fn ($log) => optional($log->consumed_at)->format('Y-m-d') === $entryDate
                );
                $companySummary->push(trim((string) ($entry->rig_company ?? '')) !== '' ? (string) $entry->rig_company : 'Non defini');
                $rigSummary->push(trim((string) ($entry->position ?? '')) !== '' ? (string) $entry->position : 'Non defini');

                $breakfast = 0;
                $lunch = 0;
                $dinner = 0;

                foreach ($consumedForDate as $log) {
                    $bucket = $this->resolveMealBucket((string) $log->meal_type, $log->meal_rule_id, $mealRuleNamesById);
                    if ($bucket === 'breakfast') {
                        $breakfast += (int) $log->quantity;
                    } elseif ($bucket === 'lunch') {
                        $lunch += (int) $log->quantity;
                    } elseif ($bucket === 'dinner') {
                        $dinner += (int) $log->quantity;
                    }
                }

                $rows[] = [
                    $entryDate,
                    optional($entry->created_at)->format('H:i:s') ?: '',
                    (string) ($entry->external_id ?? ''),
                    (string) ($entry->full_name ?? ''),
                    (string) ($entry->rig_company ?? ''),
                    (string) ($entry->position ?? ''),
                    $breakfast,
                    $lunch,
                    $dinner,
                    $breakfast + $lunch + $dinner,
                ];
            }

            $excel = app(BrandedExcelExportService::class)->build(
                'extra-' . now()->format('Ymd-His'),
                'RAPPORT - EXTRA',
                [
                    'Date',
                    'Heure creation',
                    'Identifiant',
                    'Nom complet',
                    'Compagnie',
                    'Rig',
                    'Petit-dejeuner (qte)',
                    'Dejeuner (qte)',
                    'Diner (qte)',
                    'Total repas',
                ],
                $rows,
                [
                    'Date debut' => $selectedDateFrom ?? '-',
                    'Date fin' => $selectedDateTo ?? '-',
                    'Recherche' => $search !== '' ? $search : '-',
                    'Compagnie' => $company !== '' ? $company : '-',
                    'Rig' => $rig !== '' ? $rig : '-',
                ],
                [
                    [
                        'title' => 'SYNTHESE PAR COMPAGNIE',
                        'headers' => ['Compagnie', 'Total entrees'],
                        'rows' => $companySummary->countBy()->sortDesc()->map(fn (int $count, string $companyLabel): array => [(string) $companyLabel, $count])->values()->all(),
                    ],
                    [
                        'title' => 'SYNTHESE PAR RIG',
                        'headers' => ['Rig', 'Total entrees'],
                        'rows' => $rigSummary->countBy()->sortDesc()->map(fn (int $count, string $rigLabel): array => [(string) $rigLabel, $count])->values()->all(),
                    ],
                ]
            );

            return response()->download($excel['path'], $excel['filename'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $delimiter = ',';
        $filename = 'extra-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($entries, $delimiter, $selectedDateFrom, $selectedDateTo, $search, $company, $rig): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            // BOM UTF-8 pour une meilleure compatibilite Excel.
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, ['RAPPORT - EXTRA'], $delimiter);
            fputcsv($output, ['Genere le', now()->format('Y-m-d H:i:s')], $delimiter);
            fputcsv($output, [''], $delimiter);

            fputcsv($output, [
                'Date',
                'Heure creation',
                'Identifiant',
                'Nom complet',
                'Compagnie',
                'Rig',
                'Petit-dejeuner (0/1)',
                'Dejeuner (0/1)',
                'Diner (0/1)',
                'Total repas',
            ], $delimiter);

            $mealRuleNamesById = MealRule::query()->pluck('name', 'id')->all();
            $companySummary = collect();
            $rigSummary = collect();

            foreach ($entries as $entry) {
                $entryDate = optional($entry->entry_date)->format('Y-m-d');
                $consumedForDate = $entry->mealLogs->filter(
                    fn ($log) => optional($log->consumed_at)->format('Y-m-d') === $entryDate
                );
                $companySummary->push(trim((string) ($entry->rig_company ?? '')) !== '' ? (string) $entry->rig_company : 'Non defini');
                $rigSummary->push(trim((string) ($entry->position ?? '')) !== '' ? (string) $entry->position : 'Non defini');
                $breakfast = 0;
                $lunch = 0;
                $dinner = 0;

                foreach ($consumedForDate as $log) {
                    $bucket = $this->resolveMealBucket((string) $log->meal_type, $log->meal_rule_id, $mealRuleNamesById);
                    if ($bucket === 'breakfast') {
                        $breakfast += (int) $log->quantity;
                    } elseif ($bucket === 'lunch') {
                        $lunch += (int) $log->quantity;
                    } elseif ($bucket === 'dinner') {
                        $dinner += (int) $log->quantity;
                    }
                }

                fputcsv($output, [
                    $entryDate,
                    optional($entry->created_at)->format('H:i:s') ?: '',
                    (string) ($entry->external_id ?? ''),
                    (string) ($entry->full_name ?? ''),
                    (string) ($entry->rig_company ?? ''),
                    (string) ($entry->position ?? ''),
                    $breakfast,
                    $lunch,
                    $dinner,
                    $breakfast + $lunch + $dinner,
                ], $delimiter);
            }

            fputcsv($output, [''], $delimiter);
            fputcsv($output, ['FILTRES APPLIQUES'], $delimiter);
            fputcsv($output, ['Date debut', $selectedDateFrom ?? '-'], $delimiter);
            fputcsv($output, ['Date fin', $selectedDateTo ?? '-'], $delimiter);
            fputcsv($output, ['Recherche', $search !== '' ? $search : '-'], $delimiter);
            fputcsv($output, ['Compagnie', $company !== '' ? $company : '-'], $delimiter);
            fputcsv($output, ['Rig', $rig !== '' ? $rig : '-'], $delimiter);

            fputcsv($output, [''], $delimiter);
            fputcsv($output, ['SYNTHESE PAR COMPAGNIE'], $delimiter);
            fputcsv($output, ['Compagnie', 'Total entrees'], $delimiter);
            foreach ($companySummary->countBy()->sortDesc() as $companyLabel => $count) {
                fputcsv($output, [(string) $companyLabel, (int) $count], $delimiter);
            }

            fputcsv($output, [''], $delimiter);
            fputcsv($output, ['SYNTHESE PAR RIG'], $delimiter);
            fputcsv($output, ['Rig', 'Total entrees'], $delimiter);
            foreach ($rigSummary->countBy()->sortDesc() as $rigLabel => $count) {
                fputcsv($output, [(string) $rigLabel, (int) $count], $delimiter);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{0:?string,1:?string,2:string,3:string,4:string}
     */
    private function resolveFilters(Request $request): array
    {
        $dateFrom = trim((string) $request->string('date_from'));
        $dateTo = trim((string) $request->string('date_to'));
        $search = trim((string) $request->string('q'));
        $company = trim((string) $request->string('company'));
        $rig = trim((string) $request->string('rig'));

        $selectedDateFrom = $dateFrom !== '' ? CarbonImmutable::parse($dateFrom)->toDateString() : null;
        $selectedDateTo = $dateTo !== '' ? CarbonImmutable::parse($dateTo)->toDateString() : null;

        if ($selectedDateFrom !== null && $selectedDateTo === null) {
            $selectedDateTo = $selectedDateFrom;
        }

        if ($selectedDateTo !== null && $selectedDateFrom === null) {
            $selectedDateFrom = $selectedDateTo;
        }

        if ($selectedDateFrom !== null && $selectedDateTo !== null && $selectedDateFrom > $selectedDateTo) {
            [$selectedDateFrom, $selectedDateTo] = [$selectedDateTo, $selectedDateFrom];
        }

        return [$selectedDateFrom, $selectedDateTo, $search, $company, $rig];
    }

    private function buildFilteredEntriesQuery(
        ?string $selectedDateFrom,
        ?string $selectedDateTo,
        string $search,
        string $company,
        string $rig
    ): Builder {
        return LaReleveEntry::query()
            ->where('source', 'extra')
            ->search($search)
            ->when($selectedDateFrom !== null && $selectedDateTo !== null, function (Builder $query) use ($selectedDateFrom, $selectedDateTo): void {
                $query->whereBetween('entry_date', [$selectedDateFrom, $selectedDateTo]);
            })
            ->when($company !== '', function (Builder $query) use ($company): void {
                $query->where('rig_company', 'like', '%' . $company . '%');
            })
            ->when($rig !== '', function (Builder $query) use ($rig): void {
                $query->where('position', 'like', '%' . $rig . '%');
            });
    }

    private function buildTopStats(Collection $entriesForSummary, string $field): Collection
    {
        return $entriesForSummary
            ->groupBy(function ($entry) use ($field): string {
                $value = trim((string) data_get($entry, $field));
                return $value !== '' ? $value : 'Non defini';
            })
            ->map(function (Collection $rows, string $label): array {
                return [
                    'label' => $label,
                    'entries' => $rows->count(),
                    'with_meal' => $rows->where('consumed_meals_count', '>', 0)->count(),
                    'total_meals' => (int) $rows->sum('consumed_meals_count'),
                ];
            })
            ->sortByDesc('entries')
            ->take(10)
            ->values();
    }

    /**
     * @return array<string, int>
     */
    private function buildMealTypeStats(Collection $logs, array $mealRuleNamesById): array
    {
        $stats = [
            'breakfast' => 0,
            'lunch' => 0,
            'dinner' => 0,
        ];

        foreach ($logs as $log) {
            $bucket = $this->resolveMealBucket((string) $log->meal_type, $log->meal_rule_id, $mealRuleNamesById);
            if ($bucket !== null && array_key_exists($bucket, $stats)) {
                $stats[$bucket] += (int) $log->quantity;
            }
        }

        return $stats;
    }

    private function resolveMealBucket(string $mealType, ?int $mealRuleId, array $mealRuleNamesById): ?string
    {
        $bucket = $this->normalizeMealType($mealType);
        if ($bucket !== null) {
            return $bucket;
        }

        if ($mealRuleId !== null && array_key_exists($mealRuleId, $mealRuleNamesById)) {
            return $this->normalizeMealType((string) $mealRuleNamesById[$mealRuleId]);
        }

        return null;
    }

    private function normalizeMealType(string $meal): ?string
    {
        $normalized = Str::of($meal)->lower()->ascii()->trim()->value();

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
}
