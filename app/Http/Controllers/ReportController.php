<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\LaReleveMealLog;
use App\Models\MealLog;
use App\Services\BrandedExcelExportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class ReportController extends Controller
{
    private const APPROVED_STATUSES = ['approved', 'authorized'];
    private const REJECTED_STATUSES = ['rejected', 'denied'];

    public function index(Request $request): View
    {
        $reportData = $this->buildReportData($request);

        return view('reports.index', $reportData);
    }

    public function export(Request $request)
    {
        $data = $this->buildReportData($request);
        $format = in_array((string) $request->string('format'), ['csv', 'json', 'excel'], true)
            ? (string) $request->string('format')
            : 'excel';

        if ($data['kpi']['total_logs'] === 0) {
            return response()->json([
                'error' => 'Impossible d\'exporter : aucune donnée disponible avec ces filtres.',
            ], 422);
        }

        $filenameBase = 'reports-' . now()->format('Ymd-His');

        if ($format === 'excel') {
            $excel = app(BrandedExcelExportService::class)->build(
                $filenameBase,
                'RAPPORT OPERATIONNEL',
                ['Date', 'Total', 'Autorise', 'Refuse', 'Montant'],
                $data['dailyReport']->map(fn (array $row): array => [
                    $row['bucket'],
                    $row['total'],
                    $row['approved'],
                    $row['rejected'],
                    $row['amount'],
                ])->values()->all(),
                [
                    'Periode' => (string) ($data['filters']['date_from'] ?: '-') . ' -> ' . (string) ($data['filters']['date_to'] ?: '-'),
                    'Source' => (string) ($data['filters']['source'] ?: 'all'),
                    'Departement ID' => (string) ($data['filters']['department_id'] ?: 'tous'),
                    'Type resultat' => (string) ($data['filters']['result_type'] ?: 'tous'),
                    'Budget par jour' => (string) ($data['filters']['budget_per_day'] ?? 0),
                ],
                [
                    [
                        'title' => 'KPI',
                        'headers' => ['Indicateur', 'Valeur'],
                        'rows' => [
                            ['Total logs', $data['kpi']['total_logs']],
                            ['Autorises', $data['kpi']['approved_logs']],
                            ['Refuses', $data['kpi']['rejected_logs']],
                            ['Taux approbation %', $data['kpi']['approval_rate']],
                            ['Montant total', $data['kpi']['total_amount']],
                            ['Budget total', $data['kpi']['budget_total']],
                            ['Ecart budget', $data['kpi']['budget_gap']],
                        ],
                    ],
                    [
                        'title' => 'SYNTHESE PAR DEPARTEMENT',
                        'headers' => ['Departement', 'Total', 'Autorise', 'Refuse', 'Montant'],
                        'rows' => $data['departmentSummary']->map(fn (array $row): array => [
                            $row['label'],
                            $row['total'],
                            $row['approved'],
                            $row['rejected'],
                            $row['amount'],
                        ])->values()->all(),
                    ],
                ]
            );

            return response()->download($excel['path'], $excel['filename'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        if ($format === 'json') {
            $payload = [
                'filters' => $data['filters'],
                'kpi' => $data['kpi'],
                'comparison' => $data['comparison'],
                'forecast' => $data['forecast'],
                'alerts' => $data['alerts'],
                'daily_report' => $data['dailyReport']->values()->all(),
                'weekly_report' => $data['weeklyReport']->values()->all(),
                'monthly_report' => $data['monthlyReport']->values()->all(),
                'department_summary' => $data['departmentSummary']->values()->all(),
                'denial_reasons' => $data['denialReasons']->values()->all(),
            ];

            return response()->streamDownload(function () use ($payload): void {
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }, $filenameBase . '.json', [
                'Content-Type' => 'application/json; charset=UTF-8',
            ]);
        }

        return response()->streamDownload(function () use ($data): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            $delimiter = ';';

            fputcsv($out, ['RAPPORT OPERATIONNEL - TABLEAU JOURNALIER'], $delimiter);
            fputcsv($out, ['Genere le', now()->format('Y-m-d H:i:s')], $delimiter);
            fputcsv($out, [''], $delimiter);

            fputcsv($out, ['Date', 'Total', 'Autorise', 'Refuse', 'Montant'], $delimiter);
            foreach ($data['dailyReport'] as $row) {
                fputcsv($out, [$row['bucket'], $row['total'], $row['approved'], $row['rejected'], $row['amount']], $delimiter);
            }

            fputcsv($out, [''], $delimiter);
            fputcsv($out, ['FILTRES APPLIQUES'], $delimiter);
            fputcsv($out, ['Periode', (string) ($data['filters']['date_from'] ?: '-') . ' -> ' . (string) ($data['filters']['date_to'] ?: '-')], $delimiter);
            fputcsv($out, ['Source', (string) ($data['filters']['source'] ?: 'all')], $delimiter);
            fputcsv($out, ['Departement ID', (string) ($data['filters']['department_id'] ?: 'tous')], $delimiter);
            fputcsv($out, ['Type resultat', (string) ($data['filters']['result_type'] ?: 'tous')], $delimiter);
            fputcsv($out, ['Budget par jour', (string) ($data['filters']['budget_per_day'] ?? 0)], $delimiter);

            fputcsv($out, [''], $delimiter);
            fputcsv($out, ['KPI', 'Valeur'], $delimiter);
            fputcsv($out, ['Total logs', $data['kpi']['total_logs']], $delimiter);
            fputcsv($out, ['Autorises', $data['kpi']['approved_logs']], $delimiter);
            fputcsv($out, ['Refuses', $data['kpi']['rejected_logs']], $delimiter);
            fputcsv($out, ['Taux approbation %', $data['kpi']['approval_rate']], $delimiter);
            fputcsv($out, ['Montant total', $data['kpi']['total_amount']], $delimiter);
            fputcsv($out, ['Budget total', $data['kpi']['budget_total']], $delimiter);
            fputcsv($out, ['Ecart budget', $data['kpi']['budget_gap']], $delimiter);

            fputcsv($out, [''], $delimiter);
            fputcsv($out, ['SYNTHESE PAR DEPARTEMENT'], $delimiter);
            fputcsv($out, ['Departement', 'Total', 'Autorise', 'Refuse', 'Montant'], $delimiter);
            foreach ($data['departmentSummary'] as $row) {
                fputcsv($out, [$row['label'], $row['total'], $row['approved'], $row['rejected'], $row['amount']], $delimiter);
            }

            fclose($out);
        }, $filenameBase . '.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function buildReportData(Request $request): array
    {
        $filters = $this->resolveFilters($request);
        $records = $this->buildUnifiedRecords($filters);

        $kpi = [
            'total_logs' => $records->count(),
            'approved_logs' => $records->where('status', 'approved')->count(),
            'rejected_logs' => $records->where('status', 'rejected')->count(),
            'total_amount' => round((float) $records->sum('amount'), 2),
            'employee_logs' => $records->where('source', 'employee')->count(),
            'la_releve_logs' => $records->where('source', 'la_releve')->count(),
            'extra_logs' => $records->where('source', 'extra')->count(),
        ];

        $kpi['approval_rate'] = $kpi['total_logs'] > 0
            ? round(($kpi['approved_logs'] / $kpi['total_logs']) * 100, 1)
            : 0;

        $periodDays = max(1, CarbonImmutable::parse($filters['date_from'])->diffInDays(CarbonImmutable::parse($filters['date_to'])) + 1);
        $budgetTotal = round($filters['budget_per_day'] * $periodDays, 2);
        $kpi['budget_total'] = $budgetTotal;
        $kpi['budget_gap'] = round($budgetTotal - $kpi['total_amount'], 2);

        $dailyReport = $this->buildGroupedReport($records, 'Y-m-d');
        $weeklyReport = $this->buildGroupedReport($records, 'o-\\WW');
        $monthlyReport = $this->buildGroupedReport($records, 'Y-m');

        $departmentSummary = $records
            ->groupBy(fn (array $row): string => (string) ($row['department'] ?: 'Non defini'))
            ->map(function (Collection $rows, string $label): array {
                return [
                    'label' => $label,
                    'total' => $rows->count(),
                    'approved' => $rows->where('status', 'approved')->count(),
                    'rejected' => $rows->where('status', 'rejected')->count(),
                    'amount' => round((float) $rows->sum('amount'), 2),
                    'approval_rate' => $rows->count() > 0
                        ? round(($rows->where('status', 'approved')->count() / $rows->count()) * 100, 1)
                        : 0,
                ];
            })
            ->sortByDesc('total')
            ->values();

        $denialReasons = $records
            ->where('status', 'rejected')
            ->filter(fn (array $row): bool => trim((string) ($row['reason'] ?? '')) !== '')
            ->groupBy(fn (array $row): string => trim((string) $row['reason']))
            ->map(fn (Collection $rows, string $reason): array => [
                'reason' => $reason,
                'count' => $rows->count(),
            ])
            ->sortByDesc('count')
            ->values();

        $latestLogs = $records
            ->sortByDesc('at')
            ->take(20)
            ->values();

        $comparison = $this->buildComparison($filters);
        $forecast = $this->buildForecast($records);
        $alerts = $this->buildAlerts($kpi, $denialReasons, $filters, $comparison);

        $departments = Department::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'filters' => $filters,
            'departments' => $departments,
            'kpi' => $kpi,
            'dailyReport' => $dailyReport,
            'weeklyReport' => $weeklyReport,
            'monthlyReport' => $monthlyReport,
            'departmentSummary' => $departmentSummary,
            'denialReasons' => $denialReasons,
            'latestLogs' => $latestLogs,
            'comparison' => $comparison,
            'forecast' => $forecast,
            'alerts' => $alerts,
            'exportContext' => [
                'report' => 'restaurant_operational_report',
                'filters' => $filters,
                'sections' => [
                    'kpi',
                    'comparison',
                    'forecast',
                    'daily_report',
                    'weekly_report',
                    'monthly_report',
                    'department_summary',
                    'denial_reasons',
                ],
                'suggested_filename' => 'restaurant-report-' . now()->format('Ymd-His'),
            ],
        ];
    }

    private function resolveFilters(Request $request): array
    {
        $dateFromInput = trim((string) $request->string('date_from'));
        $dateToInput = trim((string) $request->string('date_to'));
        $source = in_array((string) $request->string('source'), ['all', 'employee', 'la_releve', 'extra'], true)
            ? (string) $request->string('source')
            : 'all';
        $resultType = in_array((string) $request->string('result_type'), ['approved', 'rejected'], true)
            ? (string) $request->string('result_type')
            : '';

        $dateTo = $this->safeDate($dateToInput) ?? now()->toDateString();
        $dateFrom = $this->safeDate($dateFromInput) ?? CarbonImmutable::parse($dateTo)->subDays(6)->toDateString();

        if ($dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'department_id' => max(0, (int) $request->integer('department_id')),
            'result_type' => $resultType,
            'source' => $source,
            'budget_per_day' => max(0, (float) $request->input('budget_per_day', 0)),
        ];
    }

    private function buildUnifiedRecords(array $filters): Collection
    {
        $start = CarbonImmutable::parse($filters['date_from'])->startOfDay();
        $end = CarbonImmutable::parse($filters['date_to'])->endOfDay();

        $records = collect();

        if (in_array($filters['source'], ['all', 'employee'], true)) {
            $employeeLogs = MealLog::query()
                ->with(['employee.departmentModel', 'mealRule'])
                ->whereBetween('logged_at', [$start, $end])
                ->when($filters['department_id'] > 0, function ($query) use ($filters): void {
                    $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('department_id', $filters['department_id']));
                })
                ->get(['id', 'employee_id', 'meal_rule_id', 'status', 'logged_at', 'amount_charged', 'reason', 'meal_type']);

            $records = $records->merge($employeeLogs->map(function (MealLog $log): array {
                $status = in_array((string) $log->status, self::APPROVED_STATUSES, true)
                    ? 'approved'
                    : (in_array((string) $log->status, self::REJECTED_STATUSES, true) ? 'rejected' : 'other');

                return [
                    'at' => optional($log->logged_at)?->toDateTimeString(),
                    'source' => 'employee',
                    'status' => $status,
                    'amount' => (float) ($log->amount_charged ?? $log->mealRule?->cost ?? 0),
                    'department' => $log->employee?->departmentModel?->name ?? $log->employee?->department ?? 'Non defini',
                    'reason' => (string) ($log->reason ?? ''),
                    'meal' => (string) ($log->mealRule?->name ?? $log->meal_type ?? '-'),
                ];
            }));
        }

        if (in_array($filters['source'], ['all', 'la_releve', 'extra'], true)) {
            $laReleveQuery = LaReleveMealLog::query()
                ->with(['entry', 'mealRule'])
                ->whereBetween('consumed_at', [$start, $end]);

            if ($filters['source'] === 'la_releve') {
                $laReleveQuery->whereHas('entry', function ($query): void {
                    $query->where(function ($sub): void {
                        $sub->where('source', 'la_releve')->orWhereNull('source');
                    });
                });
            }

            if ($filters['source'] === 'extra') {
                $laReleveQuery->whereHas('entry', fn ($query) => $query->where('source', 'extra'));
            }

            $laReleveLogs = $laReleveQuery->get(['id', 'la_releve_entry_id', 'meal_rule_id', 'consumed_at', 'notes', 'meal_type']);

            $records = $records->merge($laReleveLogs->map(function (LaReleveMealLog $log): array {
                $src = ($log->entry?->source ?? 'la_releve') === 'extra' ? 'extra' : 'la_releve';

                return [
                    'at' => optional($log->consumed_at)?->toDateTimeString(),
                    'source' => $src,
                    'status' => 'approved',
                    'amount' => (float) ($log->mealRule?->cost ?? 0),
                    'department' => $src === 'extra' ? 'EXTRA' : 'LA RELEVE',
                    'reason' => (string) ($log->notes ?? ''),
                    'meal' => (string) ($log->mealRule?->name ?? $log->meal_type ?? '-'),
                ];
            }));
        }

        if ($filters['result_type'] !== '') {
            $records = $records->where('status', $filters['result_type'])->values();
        }

        return $records->filter(fn (array $row): bool => !empty($row['at']))->values();
    }

    private function buildComparison(array $filters): array
    {
        $start = CarbonImmutable::parse($filters['date_from'])->startOfDay();
        $end = CarbonImmutable::parse($filters['date_to'])->endOfDay();
        $days = max(1, $start->diffInDays($end) + 1);

        $previousFilters = $filters;
        $previousFilters['date_to'] = $start->subDay()->toDateString();
        $previousFilters['date_from'] = $start->subDays($days)->toDateString();

        $current = $this->buildUnifiedRecords($filters);
        $previous = $this->buildUnifiedRecords($previousFilters);

        $currentKpi = [
            'total' => $current->count(),
            'approved' => $current->where('status', 'approved')->count(),
            'rejected' => $current->where('status', 'rejected')->count(),
            'amount' => (float) $current->sum('amount'),
        ];
        $previousKpi = [
            'total' => $previous->count(),
            'approved' => $previous->where('status', 'approved')->count(),
            'rejected' => $previous->where('status', 'rejected')->count(),
            'amount' => (float) $previous->sum('amount'),
        ];

        return [
            'previous_period_label' => $previousFilters['date_from'] . ' au ' . $previousFilters['date_to'],
            'total' => $this->compareMetric($currentKpi['total'], $previousKpi['total']),
            'approved' => $this->compareMetric($currentKpi['approved'], $previousKpi['approved']),
            'rejected' => $this->compareMetric($currentKpi['rejected'], $previousKpi['rejected']),
            'amount' => $this->compareMetric($currentKpi['amount'], $previousKpi['amount']),
        ];
    }

    private function buildForecast(Collection $records): array
    {
        $daily = $records
            ->groupBy(fn (array $row): string => CarbonImmutable::parse((string) $row['at'])->toDateString())
            ->map(fn (Collection $rows): array => [
                'total' => $rows->count(),
                'amount' => (float) $rows->sum('amount'),
            ])
            ->sortKeysDesc()
            ->take(7);

        $days = max(1, $daily->count());

        return [
            'reference_days' => $days,
            'next_day_total_estimate' => (int) round($daily->sum('total') / $days),
            'next_day_amount_estimate' => round((float) $daily->sum('amount') / $days, 2),
        ];
    }

    private function buildAlerts(array $kpi, Collection $denialReasons, array $filters, array $comparison): array
    {
        $alerts = [];

        if ((float) $kpi['approval_rate'] < 85) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Taux d\'approbation critique',
                'message' => 'Le taux d\'approbation est tombé à ' . number_format((float) $kpi['approval_rate'], 1) . '%.',
            ];
        } elseif ((float) $kpi['approval_rate'] < 92) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Taux d\'approbation à surveiller',
                'message' => 'Le taux d\'approbation est de ' . number_format((float) $kpi['approval_rate'], 1) . '%.',
            ];
        }

        if ((float) $kpi['budget_total'] > 0 && (float) $kpi['total_amount'] > (float) $kpi['budget_total']) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Dépassement budget',
                'message' => 'Le montant total dépasse le budget de ' . number_format((float) abs($kpi['budget_gap']), 2) . '.',
            ];
        }

        $topReason = $denialReasons->first();
        if (is_array($topReason) && (int) ($topReason['count'] ?? 0) > 0 && (int) $kpi['rejected_logs'] > 0) {
            $ratio = ((int) $topReason['count'] / max(1, (int) $kpi['rejected_logs'])) * 100;
            if ($ratio >= 40) {
                $alerts[] = [
                    'level' => 'info',
                    'title' => 'Motif dominant de refus',
                    'message' => '"' . (string) $topReason['reason'] . '" représente ' . number_format($ratio, 1) . '% des refus.',
                ];
            }
        }

        if (($comparison['total']['delta'] ?? 0) > 0 && ($comparison['rejected']['delta'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'warning',
                'title' => 'Hausse simultanée volume/refus',
                'message' => 'Le volume et les refus augmentent par rapport à la période précédente.',
            ];
        }

        if (count($alerts) === 0) {
            $alerts[] = [
                'level' => 'success',
                'title' => 'Situation stable',
                'message' => 'Aucune anomalie majeure détectée pour la période filtrée.',
            ];
        }

        return $alerts;
    }

    private function compareMetric(float|int $current, float|int $previous): array
    {
        $delta = (float) $current - (float) $previous;
        $pct = (float) $previous !== 0.0
            ? round(($delta / (float) $previous) * 100, 1)
            : null;

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'pct' => $pct,
        ];
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

    private function buildGroupedReport(Collection $records, string $dateFormat): Collection
    {
        return $records
            ->groupBy(function (array $row) use ($dateFormat): string {
                if (empty($row['at'])) {
                    return 'N/A';
                }

                return CarbonImmutable::parse((string) $row['at'])->format($dateFormat);
            })
            ->map(function (Collection $rows, string $bucket): array {
                return [
                    'bucket' => $bucket,
                    'total' => $rows->count(),
                    'approved' => $rows->where('status', 'approved')->count(),
                    'rejected' => $rows->where('status', 'rejected')->count(),
                    'amount' => round((float) $rows->sum('amount'), 2),
                ];
            })
            ->sortByDesc('bucket')
            ->values();
    }
}
