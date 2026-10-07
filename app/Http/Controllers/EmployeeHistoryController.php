<?php

namespace App\Http\Controllers;

use App\Models\MealLog;
use App\Services\BrandedExcelExportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EmployeeHistoryController extends Controller
{
    public function index(Request $request): View
    {
        [$filterMode, $selectedDate, $selectedDateFrom, $selectedDateTo, $search] = $this->resolveFilters($request);

        $query = $this->getFilteredLogsQuery($filterMode, $selectedDate, $selectedDateFrom, $selectedDateTo, $search);

        $mealLogsForSummary = (clone $query)
            ->with(['mealRule:id,name'])
            ->get(['id', 'meal_type', 'meal_rule_id']);
        $mealTypeSummary = $this->buildMealTypeSummary($mealLogsForSummary);

        $summary = [
            'total' => (clone $query)->count(),
            'authorized' => (clone $query)->where('status', 'approved')->count(),
            'denied' => (clone $query)->where('status', 'rejected')->count(),
            'employees' => (clone $query)->whereNotNull('employee_id')->distinct('employee_id')->count('employee_id'),
            'breakfast' => $mealTypeSummary['breakfast'],
            'lunch' => $mealTypeSummary['lunch'],
            'dinner' => $mealTypeSummary['dinner'],
        ];

        $logs = (clone $query)
            ->paginate(25)
            ->withQueryString();

        $logs->setCollection(
            $logs->getCollection()->map(function (MealLog $log): MealLog {
                $log->setAttribute('meal_type_label', $this->resolveMealLabel($log));

                return $log;
            })
        );

        return view('employee-history.index', [
            'logs' => $logs,
            'summary' => $summary,
            'filterMode' => $filterMode,
            'selectedDate' => $selectedDate,
            'selectedDateFrom' => $selectedDateFrom,
            'selectedDateTo' => $selectedDateTo,
            'search' => $search,
        ]);
    }

    public function export(Request $request)
    {
        [$filterMode, $selectedDate, $selectedDateFrom, $selectedDateTo, $search] = $this->resolveFilters($request);
        $format = in_array((string) $request->string('format'), ['csv', 'excel'], true)
            ? (string) $request->string('format')
            : 'excel';

        $entries = $this->getFilteredLogsQuery($filterMode, $selectedDate, $selectedDateFrom, $selectedDateTo, $search)
            ->get();

        if ($entries->isEmpty()) {
            return response()->json([
                'error' => 'Impossible d\'exporter : aucune donnée disponible avec ces filtres.',
            ], 422);
        }

        if ($format === 'excel') {
            $employeeConsumedTotals = collect($entries)
                ->groupBy(fn (MealLog $entry): string => (string) ($entry->employee_id ?? '0'))
                ->map(fn ($rows): int => $rows->where('status', 'approved')->count());

            $departmentSummary = collect($entries)
                ->map(function (MealLog $entry): string {
                    $employee = $entry->employee;
                    $department = trim((string) ($employee?->departmentModel?->name ?? $employee?->department ?? ''));

                    return $department !== '' ? $department : 'Non defini';
                })
                ->countBy()
                ->sortDesc();

            $excel = app(BrandedExcelExportService::class)->build(
                'historique-employes-' . now()->format('Ymd-His'),
                'RAPPORT - HISTORIQUE EMPLOYES',
                [
                    'Date',
                    'Heure',
                    'Matricule',
                    'Nom complet',
                    'Poste',
                    'Entreprise',
                    'Departement',
                    'Petit-dejeuner (0/1)',
                    'Dejeuner (0/1)',
                    'Diner (0/1)',
                    'Total plats consommes',
                    'Statut',
                    'Montant',
                    'Carte UID',
                ],
                collect($entries)->map(function (MealLog $entry) use ($employeeConsumedTotals): array {
                    $employee = $entry->employee;
                    $department = trim((string) ($employee?->departmentModel?->name ?? $employee?->department ?? ''));
                    $bucket = $this->resolveMealBucket($entry);
                    $employeeId = (string) ($entry->employee_id ?? '0');

                    return [
                        optional($entry->logged_at)->format('Y-m-d') ?: '',
                        optional($entry->logged_at)->format('H:i:s') ?: '',
                        (string) ($employee?->employee_no ?? '-'),
                        trim((string) (($employee?->first_name ?? '') . ' ' . ($employee?->last_name ?? ''))) ?: '-',
                        (string) ($employee?->position ?? '-'),
                        (string) ($employee?->rig_company ?? '-'),
                        $department !== '' ? $department : 'Non defini',
                        $bucket === 'breakfast' ? 1 : 0,
                        $bucket === 'lunch' ? 1 : 0,
                        $bucket === 'dinner' ? 1 : 0,
                        (int) ($entry->employee_id ? ($employeeConsumedTotals[$employeeId] ?? 0) : 0),
                        $entry->status === 'approved' ? 'Autorisé' : 'Refusé',
                        number_format((float) ($entry->amount_charged ?? 0), 2, '.', ''),
                        (string) ($entry->card_uid ?: ($entry->card?->uid ?? '-')),
                    ];
                })->all(),
                [
                    'Mode filtre' => $filterMode,
                    'Date' => $selectedDate,
                    'Date debut' => $selectedDateFrom,
                    'Date fin' => $selectedDateTo,
                    'Recherche' => $search !== '' ? $search : '-',
                ],
                [[
                    'title' => 'SYNTHESE PAR DEPARTEMENT',
                    'headers' => ['Departement', 'Total passages'],
                    'rows' => $departmentSummary->map(fn (int $count, string $department): array => [(string) $department, $count])->values()->all(),
                ]]
            );

            return response()->download($excel['path'], $excel['filename'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $filename = 'historique-employes-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($entries, $filterMode, $selectedDate, $selectedDateFrom, $selectedDateTo, $search): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            $delimiter = ';';

            fputcsv($output, ['RAPPORT - HISTORIQUE EMPLOYES'], $delimiter);
            fputcsv($output, ['Genere le', now()->format('Y-m-d H:i:s')], $delimiter);
            fputcsv($output, [''], $delimiter);

            fputcsv($output, [
                'Date',
                'Heure',
                'Matricule',
                'Nom complet',
                'Poste',
                'Entreprise',
                'Departement',
                'Petit-dejeuner (0/1)',
                'Dejeuner (0/1)',
                'Diner (0/1)',
                'Total plats consommes',
                'Statut',
                'Montant',
                'Carte UID',
            ], $delimiter);

            $departmentSummary = collect();
            $employeeConsumedTotals = collect($entries)
                ->groupBy(fn (MealLog $entry): string => (string) ($entry->employee_id ?? '0'))
                ->map(fn ($rows): int => $rows->where('status', 'approved')->count());

            foreach ($entries as $entry) {
                $employee = $entry->employee;
                $department = trim((string) ($employee?->departmentModel?->name ?? $employee?->department ?? ''));
                $department = $department !== '' ? $department : 'Non defini';
                $departmentSummary->push($department);
                $bucket = $this->resolveMealBucket($entry);
                $employeeId = (string) ($entry->employee_id ?? '0');

                fputcsv($output, [
                    optional($entry->logged_at)->format('Y-m-d') ?: '',
                    optional($entry->logged_at)->format('H:i:s') ?: '',
                    (string) ($employee?->employee_no ?? '-'),
                    trim((string) (($employee?->first_name ?? '') . ' ' . ($employee?->last_name ?? ''))) ?: '-',
                    (string) ($employee?->position ?? '-'),
                    (string) ($employee?->rig_company ?? '-'),
                    $department,
                    $bucket === 'breakfast' ? 1 : 0,
                    $bucket === 'lunch' ? 1 : 0,
                    $bucket === 'dinner' ? 1 : 0,
                    (int) ($employeeConsumedTotals[$employeeId] ?? 0),
                    $entry->status === 'approved' ? 'Autorisé' : 'Refusé',
                    number_format((float) ($entry->amount_charged ?? 0), 2, '.', ''),
                    (string) ($entry->card_uid ?: ($entry->card?->uid ?? '-')),
                ], $delimiter);
            }

            fputcsv($output, [''], $delimiter);
            fputcsv($output, ['FILTRES APPLIQUES'], $delimiter);
            fputcsv($output, ['Mode filtre', $filterMode], $delimiter);
            fputcsv($output, ['Date', $selectedDate], $delimiter);
            fputcsv($output, ['Date debut', $selectedDateFrom], $delimiter);
            fputcsv($output, ['Date fin', $selectedDateTo], $delimiter);
            fputcsv($output, ['Recherche', $search !== '' ? $search : '-'], $delimiter);

            $summaryRows = $departmentSummary
                ->countBy()
                ->sortDesc();

            fputcsv($output, [''], $delimiter);
            fputcsv($output, ['SYNTHESE PAR DEPARTEMENT'], $delimiter);
            fputcsv($output, ['Departement', 'Total passages'], $delimiter);
            foreach ($summaryRows as $department => $count) {
                fputcsv($output, [(string) $department, (int) $count], $delimiter);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{0:string,1:string,2:string,3:string,4:string}
     */
    private function resolveFilters(Request $request): array
    {
        $today = CarbonImmutable::now();
        $filterMode = trim((string) $request->string('filter_mode'));
        $filterMode = in_array($filterMode, ['day', 'range'], true) ? $filterMode : 'range';

        $currentMonthStart = $today->startOfMonth()->toDateString();
        $currentMonthEnd = $today->endOfMonth()->toDateString();

        $date = trim((string) $request->string('date'));
        $dateFrom = trim((string) $request->string('date_from'));
        $dateTo = trim((string) $request->string('date_to'));
        $search = trim((string) $request->string('q'));

        $selectedDate = $date !== '' ? CarbonImmutable::parse($date)->toDateString() : $today->toDateString();
        $selectedDateFrom = $dateFrom !== '' ? CarbonImmutable::parse($dateFrom)->toDateString() : $currentMonthStart;
        $selectedDateTo = $dateTo !== '' ? CarbonImmutable::parse($dateTo)->toDateString() : $currentMonthEnd;

        if ($selectedDateFrom > $selectedDateTo) {
            [$selectedDateFrom, $selectedDateTo] = [$selectedDateTo, $selectedDateFrom];
        }

        return [$filterMode, $selectedDate, $selectedDateFrom, $selectedDateTo, $search];
    }

    private function getFilteredLogsQuery(
        string $filterMode,
        string $selectedDate,
        string $selectedDateFrom,
        string $selectedDateTo,
        string $search
    )
    {
        return MealLog::query()
            ->with(['employee:id,employee_no,first_name,last_name,position,rig_company,department_id,department', 'employee.departmentModel:id,name', 'card:id,uid', 'mealRule:id,name'])
            ->whereNotNull('employee_id')
            ->when($filterMode === 'range', function ($query) use ($selectedDateFrom, $selectedDateTo): void {
                $query->whereBetween('logged_at', [$selectedDateFrom . ' 00:00:00', $selectedDateTo . ' 23:59:59']);
            }, function ($query) use ($selectedDate): void {
                $query->whereDate('logged_at', $selectedDate);
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($subQuery) use ($search): void {
                    $subQuery
                        ->where('meal_type', 'like', '%' . $search . '%')
                        ->orWhere('status', 'like', '%' . $search . '%')
                        ->orWhere('card_uid', 'like', '%' . $search . '%')
                        ->orWhere('reason', 'like', '%' . $search . '%')
                        ->orWhereHas('employee', function ($employeeQuery) use ($search): void {
                            $employeeQuery
                                ->where('employee_no', 'like', '%' . $search . '%')
                                ->orWhere('first_name', 'like', '%' . $search . '%')
                                ->orWhere('last_name', 'like', '%' . $search . '%')
                                ->orWhere('position', 'like', '%' . $search . '%')
                                ->orWhere('rig_company', 'like', '%' . $search . '%');
                        });
                });
            })
            ->latest('logged_at');
    }
    /**
     * @param \Illuminate\Support\Collection<int, MealLog> $logs
     * @return array{breakfast:int,lunch:int,dinner:int}
     */
    private function buildMealTypeSummary($logs): array
    {
        $summary = [
            'breakfast' => 0,
            'lunch' => 0,
            'dinner' => 0,
        ];

        foreach ($logs as $log) {
            $bucket = $this->resolveMealBucket($log);
            if ($bucket !== null) {
                $summary[$bucket]++;
            }
        }

        return $summary;
    }

    private function resolveMealLabel($log): string
    {
        $bucket = $this->resolveMealBucket($log);

        return match ($bucket) {
            'breakfast' => 'Petit-déjeuner',
            'lunch' => 'Déjeuner',
            'dinner' => 'Dîner',
            default => $this->fallbackMealLabel($log),
        };
    }

    private function resolveMealBucket($log): ?string
    {
        $candidate = $log->mealRule?->name;
        if (is_string($candidate) && trim($candidate) !== '') {
            $bucket = $this->normalizeLegacyMealType($candidate);
            if ($bucket !== null) {
                return $bucket;
            }
        }

        $mealType = $log->meal_type;
        if (is_string($mealType) && trim($mealType) !== '') {
            $bucket = $this->normalizeLegacyMealType($mealType);
            if ($bucket !== null) {
                return $bucket;
            }
        }

        return null;
    }

    private function fallbackMealLabel($log): string
    {
        if ($log->mealRule?->name) {
            return (string) $log->mealRule->name;
        }

        $mealType = trim((string) $log->meal_type);

        return $mealType !== '' ? $mealType : '-';
    }

    private function normalizeLegacyMealType(string $value): ?string
    {
        $normalized = Str::of($value)->lower()->ascii()->trim()->value();

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
