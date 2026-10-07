<?php

namespace App\Http\Controllers;

use App\Services\BrandedExcelExportService;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LaReleveEntry;
use App\Models\LaReleveMealLog;
use App\Models\MealLog;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MealLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->resolveFilters($request);
        $records = $this->buildUnifiedRecords($filters);

        $summary = [
            'total' => $records->count(),
            'authorized' => $records->where('status', 'approved')->count(),
            'denied' => $records->where('status', 'rejected')->count(),
            'consumed_total' => $records->where('status', 'approved')->count(),
            'amount_total' => round((float) $records->sum('amount'), 2),
            'source_employee' => $records->where('source', 'employee')->count(),
            'source_la_releve' => $records->where('source', 'la_releve')->count(),
            'source_extra' => $records->where('source', 'extra')->count(),
        ];

        $mealTypeSummary = [
            'breakfast' => 0,
            'lunch' => 0,
            'dinner' => 0,
            'other' => 0,
        ];

        foreach ($records->where('status', 'approved') as $row) {
            $bucket = $this->normalizeMealType((string) ($row['meal'] ?? ''));
            $mealTypeSummary[$bucket] += 1;
        }

        $mealLogs = $this->paginateRecords($records, $request, 20);

        $employees = Employee::query()
            ->where('is_active', true)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'employee_no', 'first_name', 'last_name']);

        $scopedEmployee = $filters['employee_id'] > 0
            ? Employee::query()->find($filters['employee_id'], ['id', 'employee_no', 'first_name', 'last_name'])
            : null;

        $departments = Department::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $denialReasons = MealLog::query()
            ->where('status', 'rejected')
            ->whereNotNull('reason')
            ->where('reason', '!=', '')
            ->select('reason')
            ->distinct()
            ->orderBy('reason')
            ->pluck('reason');

        $companies = collect()
            ->merge(Employee::query()->whereNotNull('rig_company')->where('rig_company', '!=', '')->pluck('rig_company'))
            ->merge(LaReleveEntry::query()->whereNotNull('rig_company')->where('rig_company', '!=', '')->pluck('rig_company'))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $rigs = collect()
            ->merge(Employee::query()->whereNotNull('position')->where('position', '!=', '')->pluck('position'))
            ->merge(LaReleveEntry::query()->whereNotNull('position')->where('position', '!=', '')->pluck('position'))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $sources = [
            'all' => 'Tous',
            'employee' => 'Employe NFC',
            'la_releve' => 'La Releve',
            'extra' => 'Extra',
        ];

        return view('meal-logs.index', [
            'mealLogs' => $mealLogs,
            'summary' => $summary,
            'mealTypeSummary' => $mealTypeSummary,
            'employees' => $employees,
            'departments' => $departments,
            'denialReasons' => $denialReasons,
            'filters' => $filters,
            'companies' => $companies,
            'rigs' => $rigs,
            'sources' => $sources,
            'scopedEmployee' => $scopedEmployee,
        ]);
    }

    public function export(Request $request)
    {
        $filters = $this->resolveFilters($request);
        $records = $this->buildUnifiedRecords($filters);
        $format = in_array((string) $request->string('format'), ['csv', 'excel'], true)
            ? (string) $request->string('format')
            : 'excel';

        if ($records->isEmpty()) {
            return response()->json([
                'error' => 'Impossible d\'exporter : aucune donnée disponible avec ces filtres.',
            ], 422);
        }

        if ($format === 'excel') {
            $departmentSummary = collect($records)
                ->groupBy(fn (array $row): string => trim((string) ($row['department'] ?? '')) !== '' ? (string) $row['department'] : 'Non defini')
                ->map(fn ($rows): int => $rows->count())
                ->sortDesc();

            $excel = app(BrandedExcelExportService::class)->build(
                'meal-logs-unified-' . now()->format('Ymd-His'),
                'RAPPORT - LOGS REPAS',
                [
                    'Date',
                    'Heure',
                    'Source',
                    'Statut',
                    'Type repas',
                    'Matricule',
                    'Nom complet',
                    'Compagnie',
                    'Rig/Poste',
                    'Departement',
                    'UID carte',
                    'Montant',
                ],
                collect($records)->map(fn (array $row): array => [
                    (string) ($row['date'] ?? ''),
                    (string) ($row['time'] ?? ''),
                    (string) ($row['source_label'] ?? ''),
                    (string) ($row['status_label'] ?? ''),
                    (string) ($row['meal'] ?? ''),
                    (string) ($row['employee_no'] ?? ''),
                    (string) ($row['full_name'] ?? ''),
                    (string) ($row['company'] ?? ''),
                    (string) ($row['rig'] ?? ''),
                    (string) ($row['department'] ?? ''),
                    (string) ($row['card_uid'] ?? ''),
                    number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                ])->all(),
                [
                    'Mode filtre' => (string) ($filters['filter_mode'] ?? '-'),
                    'Date' => (string) ($filters['date'] ?? '-'),
                    'Date debut' => (string) ($filters['date_from'] ?? '-'),
                    'Date fin' => (string) ($filters['date_to'] ?? '-'),
                    'Source' => (string) ($filters['source'] ?? '-'),
                    'Resultat' => (string) ($filters['result'] !== '' ? $filters['result'] : 'tous'),
                    'Recherche' => (string) ($filters['q'] !== '' ? $filters['q'] : '-'),
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

        $filename = 'meal-logs-unified-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($records, $filters): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            $delimiter = ';';

            fputcsv($output, ['RAPPORT - LOGS REPAS'], $delimiter);
            fputcsv($output, ['Genere le', now()->format('Y-m-d H:i:s')], $delimiter);
            fputcsv($output, [''], $delimiter);

            fputcsv($output, [
                'Date',
                'Heure',
                'Source',
                'Statut',
                'Type repas',
                'Matricule',
                'Nom complet',
                'Compagnie',
                'Rig/Poste',
                'Departement',
                'UID carte',
                'Montant',
            ], $delimiter);

            foreach ($records as $row) {
                fputcsv($output, [
                    (string) ($row['date'] ?? ''),
                    (string) ($row['time'] ?? ''),
                    (string) ($row['source_label'] ?? ''),
                    (string) ($row['status_label'] ?? ''),
                    (string) ($row['meal'] ?? ''),
                    (string) ($row['employee_no'] ?? ''),
                    (string) ($row['full_name'] ?? ''),
                    (string) ($row['company'] ?? ''),
                    (string) ($row['rig'] ?? ''),
                    (string) ($row['department'] ?? ''),
                    (string) ($row['card_uid'] ?? ''),
                    number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                ], $delimiter);
            }

            fputcsv($output, [''], $delimiter);
            fputcsv($output, ['FILTRES APPLIQUES'], $delimiter);
            fputcsv($output, ['Mode filtre', (string) ($filters['filter_mode'] ?? '-')], $delimiter);
            fputcsv($output, ['Date', (string) ($filters['date'] ?? '-')], $delimiter);
            fputcsv($output, ['Date debut', (string) ($filters['date_from'] ?? '-')], $delimiter);
            fputcsv($output, ['Date fin', (string) ($filters['date_to'] ?? '-')], $delimiter);
            fputcsv($output, ['Source', (string) ($filters['source'] ?? '-')], $delimiter);
            fputcsv($output, ['Resultat', (string) ($filters['result'] !== '' ? $filters['result'] : 'tous')], $delimiter);
            fputcsv($output, ['Recherche', (string) ($filters['q'] !== '' ? $filters['q'] : '-')], $delimiter);

            $departmentSummary = collect($records)
                ->groupBy(fn (array $row): string => trim((string) ($row['department'] ?? '')) !== '' ? (string) $row['department'] : 'Non defini')
                ->map(fn ($rows): int => $rows->count())
                ->sortDesc();

            fputcsv($output, [''], $delimiter);
            fputcsv($output, ['SYNTHESE PAR DEPARTEMENT'], $delimiter);
            fputcsv($output, ['Departement', 'Total passages'], $delimiter);
            foreach ($departmentSummary as $department => $count) {
                fputcsv($output, [(string) $department, (int) $count], $delimiter);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function create()
    {
        return redirect()->route('kiosk.index');
    }

    public function store(Request $request)
    {
        return redirect()->route('kiosk.index');
    }

    public function show(MealLog $mealLog)
    {
        return redirect()->route('meal-logs.index');
    }

    public function edit(MealLog $mealLog)
    {
        return redirect()->route('meal-logs.index');
    }

    public function update(Request $request, MealLog $mealLog)
    {
        return redirect()->route('meal-logs.index');
    }

    public function destroy(MealLog $mealLog)
    {
        abort(403, 'Suppression interdite pour l historique des logs repas.');
    }

    private function resolveFilters(Request $request): array
    {
        $today = CarbonImmutable::now();
        $defaultFrom = $today->startOfMonth()->toDateString();
        $defaultTo = $today->endOfMonth()->toDateString();

        $filterMode = trim((string) $request->string('filter_mode'));
        $filterMode = in_array($filterMode, ['day', 'range'], true) ? $filterMode : 'range';

        $source = trim((string) $request->string('source'));
        $source = in_array($source, ['all', 'employee', 'la_releve', 'extra'], true) ? $source : 'all';

        $result = trim((string) $request->string('result'));
        $result = in_array($result, ['approved', 'rejected'], true) ? $result : '';

        $selectedDate = trim((string) $request->string('date'));
        $selectedDate = $selectedDate !== '' ? CarbonImmutable::parse($selectedDate)->toDateString() : $today->toDateString();

        $selectedDateFrom = trim((string) $request->string('date_from'));
        $selectedDateTo = trim((string) $request->string('date_to'));
        $selectedDateFrom = $selectedDateFrom !== '' ? CarbonImmutable::parse($selectedDateFrom)->toDateString() : $defaultFrom;
        $selectedDateTo = $selectedDateTo !== '' ? CarbonImmutable::parse($selectedDateTo)->toDateString() : $defaultTo;

        if ($selectedDateFrom > $selectedDateTo) {
            [$selectedDateFrom, $selectedDateTo] = [$selectedDateTo, $selectedDateFrom];
        }

        return [
            'filter_mode' => $filterMode,
            'date' => $selectedDate,
            'date_from' => $selectedDateFrom,
            'date_to' => $selectedDateTo,
            'employee_id' => $request->integer('employee_id'),
            'card_uid' => trim((string) $request->string('card_uid')),
            'result' => $result,
            'department_id' => $request->integer('department_id'),
            'denial_reason' => trim((string) $request->string('denial_reason')),
            'source' => $source,
            'meal_type' => trim((string) $request->string('meal_type')),
            'rig_company' => trim((string) $request->string('rig_company')),
            'rig' => trim((string) $request->string('rig')),
            'q' => trim((string) $request->string('q')),
        ];
    }

    private function buildUnifiedRecords(array $filters): Collection
    {
        $records = collect();
        $includeEmployee = in_array($filters['source'], ['all', 'employee'], true);
        $includeLaReleve = in_array($filters['source'], ['all', 'la_releve', 'extra'], true);

        if ($includeEmployee) {
            $employeeQuery = MealLog::query()
                ->with(['employee.departmentModel', 'card', 'mealRule'])
                ->when($filters['filter_mode'] === 'range', function ($query) use ($filters): void {
                    $query->whereBetween('logged_at', [$filters['date_from'] . ' 00:00:00', $filters['date_to'] . ' 23:59:59']);
                }, function ($query) use ($filters): void {
                    $query->whereDate('logged_at', $filters['date']);
                })
                ->when($filters['employee_id'] > 0, fn ($query) => $query->where('employee_id', $filters['employee_id']))
                ->when($filters['card_uid'] !== '', function ($query) use ($filters): void {
                    $query->where(function ($subQuery) use ($filters): void {
                        $subQuery
                            ->where('card_uid', 'like', '%' . $filters['card_uid'] . '%')
                            ->orWhereHas('card', fn ($cardQuery) => $cardQuery->where('uid', 'like', '%' . $filters['card_uid'] . '%'));
                    });
                })
                ->when($filters['result'] !== '', fn ($query) => $query->where('status', $filters['result']))
                ->when($filters['department_id'] > 0, function ($query) use ($filters): void {
                    $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('department_id', $filters['department_id']));
                })
                ->when($filters['denial_reason'] !== '', function ($query) use ($filters): void {
                    $query->where('status', 'rejected')->where('reason', $filters['denial_reason']);
                })
                ->when($filters['meal_type'] !== '', function ($query) use ($filters): void {
                    $query->where(function ($subQuery) use ($filters): void {
                        $subQuery
                            ->where('meal_type', 'like', '%' . $filters['meal_type'] . '%')
                            ->orWhereHas('mealRule', fn ($mealRuleQuery) => $mealRuleQuery->where('name', 'like', '%' . $filters['meal_type'] . '%'));
                    });
                })
                ->when($filters['rig_company'] !== '', function ($query) use ($filters): void {
                    $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('rig_company', 'like', '%' . $filters['rig_company'] . '%'));
                })
                ->when($filters['rig'] !== '', function ($query) use ($filters): void {
                    $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('position', 'like', '%' . $filters['rig'] . '%'));
                })
                ->when($filters['q'] !== '', function ($query) use ($filters): void {
                    $query->where(function ($subQuery) use ($filters): void {
                        $subQuery
                            ->where('card_uid', 'like', '%' . $filters['q'] . '%')
                            ->orWhere('reason', 'like', '%' . $filters['q'] . '%')
                            ->orWhereHas('mealRule', fn ($mealRuleQuery) => $mealRuleQuery->where('name', 'like', '%' . $filters['q'] . '%'))
                            ->orWhereHas('employee', function ($employeeQuery) use ($filters): void {
                                $employeeQuery
                                    ->where('employee_no', 'like', '%' . $filters['q'] . '%')
                                    ->orWhere('first_name', 'like', '%' . $filters['q'] . '%')
                                    ->orWhere('last_name', 'like', '%' . $filters['q'] . '%')
                                    ->orWhere('position', 'like', '%' . $filters['q'] . '%')
                                    ->orWhere('rig_company', 'like', '%' . $filters['q'] . '%');
                            });
                    });
                })
                ->get();

            $employeeRows = $employeeQuery->map(function (MealLog $log): array {
                $employee = $log->employee;
                $status = $log->status === 'approved' ? 'approved' : 'rejected';
                $timestamp = $log->logged_at;

                return [
                    'timestamp' => optional($timestamp)?->toDateTimeString() ?: '',
                    'date' => optional($timestamp)?->format('Y-m-d') ?: '',
                    'time' => optional($timestamp)?->format('H:i:s') ?: '',
                    'source' => 'employee',
                    'source_label' => 'Employe NFC',
                    'status' => $status,
                    'status_label' => $status === 'approved' ? 'Autorise' : 'Refuse',
                    'meal' => (string) ($log->mealRule?->name ?? $log->meal_type ?? '-'),
                    'employee_no' => (string) ($employee?->employee_no ?? '-'),
                    'full_name' => trim((string) (($employee?->first_name ?? '') . ' ' . ($employee?->last_name ?? ''))) ?: '-',
                    'company' => (string) ($employee?->rig_company ?? '-'),
                    'rig' => (string) ($employee?->position ?? '-'),
                    'department' => (string) ($employee?->departmentModel?->name ?? '-'),
                    'card_uid' => (string) ($log->card_uid ?: ($log->card?->uid ?? '-')),
                    'amount' => (float) ($log->amount_charged ?? $log->mealRule?->cost ?? 0),
                    'reason' => (string) ($log->reason ?? '-'),
                ];
            });

            $records = $records->merge($employeeRows);
        }

        if ($includeLaReleve) {
            $laReleveQuery = LaReleveMealLog::query()
                ->with(['entry', 'mealRule'])
                ->when($filters['filter_mode'] === 'range', function ($query) use ($filters): void {
                    $query->whereBetween('consumed_at', [$filters['date_from'] . ' 00:00:00', $filters['date_to'] . ' 23:59:59']);
                }, function ($query) use ($filters): void {
                    $query->whereDate('consumed_at', $filters['date']);
                })
                ->when($filters['source'] === 'la_releve', function ($query): void {
                    $query->whereHas('entry', function ($entryQuery): void {
                        $entryQuery->where(function ($subQuery): void {
                            $subQuery->where('source', 'la_releve')->orWhereNull('source');
                        });
                    });
                })
                ->when($filters['source'] === 'extra', fn ($query) => $query->whereHas('entry', fn ($entryQuery) => $entryQuery->where('source', 'extra')))
                ->when($filters['meal_type'] !== '', function ($query) use ($filters): void {
                    $query->where(function ($subQuery) use ($filters): void {
                        $subQuery
                            ->where('meal_type', 'like', '%' . $filters['meal_type'] . '%')
                            ->orWhereHas('mealRule', fn ($mealRuleQuery) => $mealRuleQuery->where('name', 'like', '%' . $filters['meal_type'] . '%'));
                    });
                })
                ->when($filters['rig_company'] !== '', fn ($query) => $query->whereHas('entry', fn ($entryQuery) => $entryQuery->where('rig_company', 'like', '%' . $filters['rig_company'] . '%')))
                ->when($filters['rig'] !== '', fn ($query) => $query->whereHas('entry', fn ($entryQuery) => $entryQuery->where('position', 'like', '%' . $filters['rig'] . '%')))
                ->when($filters['q'] !== '', function ($query) use ($filters): void {
                    $query->where(function ($subQuery) use ($filters): void {
                        $subQuery
                            ->where('meal_type', 'like', '%' . $filters['q'] . '%')
                            ->orWhere('notes', 'like', '%' . $filters['q'] . '%')
                            ->orWhereHas('mealRule', fn ($mealRuleQuery) => $mealRuleQuery->where('name', 'like', '%' . $filters['q'] . '%'))
                            ->orWhereHas('entry', function ($entryQuery) use ($filters): void {
                                $entryQuery
                                    ->where('external_id', 'like', '%' . $filters['q'] . '%')
                                    ->orWhere('full_name', 'like', '%' . $filters['q'] . '%')
                                    ->orWhere('position', 'like', '%' . $filters['q'] . '%')
                                    ->orWhere('rig_company', 'like', '%' . $filters['q'] . '%');
                            });
                    });
                })
                ->get();

            $laReleveRows = $laReleveQuery->map(function (LaReleveMealLog $log): array {
                $entry = $log->entry;
                $source = ($entry?->source ?? 'la_releve') === 'extra' ? 'extra' : 'la_releve';
                $timestamp = $log->consumed_at;

                return [
                    'timestamp' => optional($timestamp)?->toDateTimeString() ?: '',
                    'date' => optional($timestamp)?->format('Y-m-d') ?: '',
                    'time' => optional($timestamp)?->format('H:i:s') ?: '',
                    'source' => $source,
                    'source_label' => $source === 'extra' ? 'Extra' : 'La Releve',
                    'status' => 'approved',
                    'status_label' => 'Autorise',
                    'meal' => (string) ($log->mealRule?->name ?? $log->meal_type ?? '-'),
                    'employee_no' => (string) ($entry?->external_id ?? '-'),
                    'full_name' => (string) ($entry?->full_name ?? '-'),
                    'company' => (string) ($entry?->rig_company ?? '-'),
                    'rig' => (string) ($entry?->position ?? '-'),
                    'department' => $source === 'extra' ? 'EXTRA' : 'LA RELEVE',
                    'card_uid' => '-',
                    'amount' => (float) ($log->mealRule?->cost ?? 0),
                    'reason' => '-',
                ];
            });

            $records = $records->merge($laReleveRows);
        }

        if ($filters['result'] !== '') {
            $records = $records->where('status', $filters['result'])->values();
        }

        if ($filters['denial_reason'] !== '') {
            $needle = Str::lower($filters['denial_reason']);
            $records = $records->filter(function (array $row) use ($needle): bool {
                return Str::contains(Str::lower((string) ($row['reason'] ?? '')), $needle);
            })->values();
        }

        return $records
            ->filter(fn (array $row): bool => (string) ($row['timestamp'] ?? '') !== '')
            ->sortByDesc('timestamp')
            ->values();
    }

    private function paginateRecords(Collection $records, Request $request, int $perPage): LengthAwarePaginator
    {
        $page = max(1, (int) $request->integer('page', 1));
        $items = $records->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator(
            $items,
            $records->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }

    private function normalizeMealType(string $meal): string
    {
        $normalized = Str::of($meal)->lower()->ascii()->value();

        if (Str::contains($normalized, ['breakfast', 'petit'])) {
            return 'breakfast';
        }

        if (Str::contains($normalized, ['lunch', 'dejeuner', 'dejeuner'])) {
            return 'lunch';
        }

        if (Str::contains($normalized, ['dinner', 'diner'])) {
            return 'dinner';
        }

        return 'other';
    }
}
