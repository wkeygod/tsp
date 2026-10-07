<?php

namespace App\Http\Controllers;

use App\Models\LaReleveEntry;
use App\Services\BrandedExcelExportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class LaReleveController extends Controller
{
    public function index(Request $request): View
    {
        $today = CarbonImmutable::now();
        $filterMode = trim((string) $request->string('filter_mode'));
        $filterMode = in_array($filterMode, ['day', 'range'], true) ? $filterMode : 'range';

        $date = trim((string) $request->string('date'));
        $dateFrom = trim((string) $request->string('date_from'));
        $dateTo = trim((string) $request->string('date_to'));
        $search = trim((string) $request->string('q'));

        $selectedDate = $date !== '' ? CarbonImmutable::parse($date)->toDateString() : $today->toDateString();
        $selectedDateFrom = $dateFrom !== '' ? CarbonImmutable::parse($dateFrom)->toDateString() : $today->startOfMonth()->toDateString();
        $selectedDateTo = $dateTo !== '' ? CarbonImmutable::parse($dateTo)->toDateString() : $today->endOfMonth()->toDateString();

        if ($selectedDateFrom > $selectedDateTo) {
            [$selectedDateFrom, $selectedDateTo] = [$selectedDateTo, $selectedDateFrom];
        }

        $entriesQuery = LaReleveEntry::query()
            ->forLaReleve()
            ->search($search);
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
            ->paginate(25)
            ->withQueryString();

        $groupedEntries = $entries->getCollection()->groupBy(function (LaReleveEntry $entry): string {
            return $entry->entry_date ? substr((string) $entry->entry_date, 0, 10) : 'Sans date';
        });

        $entriesForSummaryQuery = LaReleveEntry::query()
            ->forLaReleve()
            ->search($search);
        if ($filterMode === 'range') {
            $entriesForSummaryQuery->where(function ($query) use ($selectedDateFrom, $selectedDateTo) {
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
            $entriesForSummaryQuery->forDate($selectedDate);
        }

        $entriesForSummary = $entriesForSummaryQuery
            ->withCount([
                'mealLogs as consumed_meals_count' => function ($query) use ($filterMode, $selectedDate, $selectedDateFrom, $selectedDateTo): void {
                    if ($filterMode === 'range') {
                        $query->whereBetween('consumed_at', [$selectedDateFrom . ' 00:00:00', $selectedDateTo . ' 23:59:59']);
                    } else {
                        $query->whereDate('consumed_at', $selectedDate);
                    }
                },
            ])
            ->get(['id', 'allowed_meal_types']);

        $summary = [
            'total_entries' => $entriesForSummary->count(),
            'already_eaten' => $entriesForSummary->where('consumed_meals_count', '>', 0)->count(),
            'quota_reached' => $entriesForSummary->filter(function (LaReleveEntry $entry): bool {
                $allowedCount = count(array_values(array_filter($entry->allowed_meal_types ?? [], fn($type) => in_array($type, ['breakfast', 'lunch', 'dinner'], true))));
                return $allowedCount > 0 && (int) $entry->consumed_meals_count >= $allowedCount;
            })->count(),
            'remaining_meals_total' => $entriesForSummary->sum(function (LaReleveEntry $entry): int {
                $allowedCount = count(array_values(array_filter($entry->allowed_meal_types ?? [], fn($type) => in_array($type, ['breakfast', 'lunch', 'dinner'], true))));
                return max(0, $allowedCount - (int) $entry->consumed_meals_count);
            }),
        ];

        return view('la-releve.index', [
            'entries' => $entries,
            'groupedEntries' => $groupedEntries,
            'filterMode' => $filterMode,
            'selectedDate' => $selectedDate,
            'selectedDateFrom' => $selectedDateFrom,
            'selectedDateTo' => $selectedDateTo,
            'search' => $search,
            'summary' => $summary,
        ]);
    }

    public function create(Request $request): View
    {
        $selectedDate = trim((string) $request->string('date'));

        return view('la-releve.create', [
            'selectedDate' => $selectedDate !== '' ? $selectedDate : now()->toDateString(),
            'mealTypeOptions' => ['breakfast', 'lunch', 'dinner'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateEntry($request);

        LaReleveEntry::query()->create([
            ...$validated,
            'external_id' => $this->buildInternalExternalId($validated['entry_date']),
            'created_by_user_id' => $request->user()?->id,
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return redirect()
            ->route('la-releve.index', ['date' => $validated['entry_date']])
            ->with('success', 'Entrée La Releve créée.');
    }

    public function showImport(Request $request): View
    {
        $selectedDate = trim((string) $request->string('date'));

        return view('la-releve.import', [
            'selectedDate' => $selectedDate !== '' ? $selectedDate : now()->toDateString(),
            'mealTypeOptions' => ['breakfast', 'lunch', 'dinner'],
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
            'entry_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:entry_date'],
            'allowed_meal_types' => ['required', 'array', 'min:1'],
            'allowed_meal_types.*' => ['string', 'in:breakfast,lunch,dinner'],
        ]);

        $file = $request->file('csv_file');
        $extension = strtolower($file->getClientOriginalExtension());
        $date = $request->input('entry_date');
        $allowedMealTypes = $request->input('allowed_meal_types');

        // Parse rows from the file (header + data)
        try {
            if (in_array($extension, ['xlsx', 'xls'], true)) {
                $rows = $this->parseExcelFile($file);
            } else {
                $rows = $this->parseCsvFile($file);
            }
        } catch (\Exception $e) {
            return back()->with('error', 'Impossible de lire le fichier : ' . $e->getMessage());
        }

        if (count($rows) < 2) {
            return back()->with('error', 'Le fichier est vide ou ne contient pas de données.');
        }

        $columns = null;
        $headerIndex = 0;

        foreach (array_slice($rows, 0, 8, true) as $index => $candidateHeader) {
            $candidateColumns = $this->detectImportColumns($candidateHeader);

            if (($candidateColumns['name'] !== -1 || ($candidateColumns['first_name'] !== -1 && $candidateColumns['last_name'] !== -1)) && $candidateColumns['id'] !== -1) {
                $columns = $candidateColumns;
                $headerIndex = $index;
                break;
            }
        }

        if ($columns === null) {
            $detectedHeaders = collect($rows[0] ?? [])
                ->map(fn($header) => trim((string) $header))
                ->filter()
                ->take(8)
                ->implode(', ');

            $suffix = $detectedHeaders !== '' ? " Colonnes détectées : {$detectedHeaders}." : '';

            return back()->with('error', 'Le format du fichier est invalide. Il doit contenir au moins une colonne Nom ou First Name/Last Name, et une colonne Identifiant/Matricule/Code/N.' . $suffix);
        }

        $dataRows = array_slice($rows, $headerIndex + 1);
        $nameCol = $columns['name'];
        $firstNameCol = $columns['first_name'];
        $lastNameCol = $columns['last_name'];
        $idCol = $columns['id'];
        $companyCol = $columns['company'];
        $positionCol = $columns['position'];

        $imported = 0;
        $skipped = 0;

        \Illuminate\Support\Facades\DB::beginTransaction();

        try {
            foreach ($dataRows as $row) {
                $requiredColumns = array_filter([$nameCol, $firstNameCol, $lastNameCol, $idCol], fn($col) => $col !== -1);
                if (count($row) <= max($requiredColumns)) {
                    continue;
                }

                if ($nameCol !== -1) {
                    $name = trim((string) ($row[$nameCol] ?? ''));
                } else {
                    $firstName = trim((string) ($row[$firstNameCol] ?? ''));
                    $lastName = trim((string) ($row[$lastNameCol] ?? ''));
                    $name = trim($firstName . ' ' . $lastName);
                }

                $id = trim((string) ($row[$idCol] ?? ''));
                $company = ($companyCol !== -1 && isset($row[$companyCol])) ? trim((string) $row[$companyCol]) : 'Non défini';
                $position = ($positionCol !== -1 && isset($row[$positionCol])) ? trim((string) $row[$positionCol]) : 'Non défini';

                if ($name === '' || $id === '') {
                    continue;
                }

                // Check for unique entry_date and external_id constraint
                $exists = LaReleveEntry::query()
                    ->whereDate('entry_date', $date)
                    ->where('external_id', $id)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                LaReleveEntry::query()->create([
                    'full_name' => $name,
                    'external_id' => $id,
                    'position' => $position,
                    'rig_company' => $company,
                    'entry_date' => $date,
                    'end_date' => $request->input('end_date'),
                    'allowed_meal_types' => $allowedMealTypes,
                    'source' => 'la_releve',
                    'created_by_user_id' => $request->user()?->id,
                    'updated_by_user_id' => $request->user()?->id,
                ]);

                $imported++;
            }

            \Illuminate\Support\Facades\DB::commit();

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return back()->with('error', 'Une erreur est survenue lors de l\'importation : ' . $e->getMessage());
        }

        if ($imported === 0 && $skipped === 0) {
            return back()->with('error', 'Le fichier a été reconnu, mais aucune ligne importable n\'a été trouvée. Vérifiez que les colonnes Nom complet ou First Name/Last Name, et Identifiant/Matricule/Code/N sont remplies.');
        }

        $message = "Importation terminée. {$imported} entrée(s) importée(s)";
        if ($skipped > 0) {
            $message .= " et {$skipped} ignoré(s) car déjà existant(s).";
        } else {
            $message .= '.';
        }

        return redirect()
            ->route('la-releve.index', ['date' => $date])
            ->with('success', $message);
    }

    /**
     * Parse an Excel file (.xlsx/.xls) and return rows as arrays.
     */
    private function parseExcelFile($file): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file->getRealPath());
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = [];

        foreach ($worksheet->toArray(null, true, true, false) as $row) {
            // Skip completely empty rows
            $nonEmpty = array_filter($row, fn($cell) => $cell !== null && trim((string) $cell) !== '');
            if (count($nonEmpty) > 0) {
                $rows[] = array_map(fn($cell) => $cell !== null ? trim((string) $cell) : '', $row);
            }
        }

        return $rows;
    }

    /**
     * Parse a CSV file (.csv/.txt) and return rows as arrays.
     */
    private function parseCsvFile($file): array
    {
        $path = $file->getRealPath();
        $separator = $this->detectCsvSeparator($path);
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier.');
        }

        $rows = [];
        while (($row = fgetcsv($handle, 4096, $separator)) !== false) {
            $nonEmpty = array_filter($row, fn($cell) => $cell !== null && trim((string) $cell) !== '');
            if (count($nonEmpty) > 0) {
                $rows[] = array_map(fn($cell) => $cell !== null ? trim((string) $cell) : '', $row);
            }
        }

        fclose($handle);
        return $rows;
    }

    private function detectCsvSeparator(string $path): string
    {
        $sample = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($sample === false) {
            throw new \RuntimeException('Impossible de lire le fichier.');
        }

        $firstLine = $sample[0] ?? '';
        $separators = [',', ';', "\t", '|'];
        $bestSeparator = ',';
        $bestCount = 0;

        foreach ($separators as $separator) {
            $count = substr_count($firstLine, $separator);

            if ($count > $bestCount) {
                $bestCount = $count;
                $bestSeparator = $separator;
            }
        }

        return $bestSeparator;
    }

    private function detectImportColumns(array $header): array
    {
        $columns = [
            'name' => -1,
            'first_name' => -1,
            'last_name' => -1,
            'id' => -1,
            'company' => -1,
            'position' => -1,
        ];

        foreach ($header as $index => $colName) {
            $normalized = $this->normalizeImportHeader((string) $colName);

            if ($columns['name'] === -1 && $this->isNameHeader($normalized)) {
                $columns['name'] = $index;
            } elseif ($columns['first_name'] === -1 && $this->isFirstNameHeader($normalized)) {
                $columns['first_name'] = $index;
            } elseif ($columns['last_name'] === -1 && $this->isLastNameHeader($normalized)) {
                $columns['last_name'] = $index;
            } elseif ($columns['id'] === -1 && $this->isIdentifierHeader($normalized)) {
                $columns['id'] = $index;
            } elseif ($columns['company'] === -1 && $this->isCompanyHeader($normalized)) {
                $columns['company'] = $index;
            } elseif ($columns['position'] === -1 && $this->isPositionHeader($normalized)) {
                $columns['position'] = $index;
            }
        }

        return $columns;
    }

    private function normalizeImportHeader(string $header): string
    {
        $header = preg_replace('/^\x{FEFF}/u', '', $header) ?? $header;
        $header = mb_strtolower(trim($header));
        $header = strtr($header, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ç' => 'c',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ÿ' => 'y',
        ]);
        $header = preg_replace('/[^a-z0-9]+/u', ' ', $header) ?? $header;

        return trim(preg_replace('/\s+/', ' ', $header) ?? $header);
    }

    private function isNameHeader(string $header): bool
    {
        return in_array($header, [
            'nom complet',
            'nom',
            'name',
            'full name',
            'nom et prenom',
            'prenom et nom',
            'employee',
            'employe',
            'personne',
            'salarie',
        ], true);
    }

    private function isFirstNameHeader(string $header): bool
    {
        return in_array($header, [
            'first name',
            'firstname',
            'prenom',
        ], true);
    }

    private function isLastNameHeader(string $header): bool
    {
        return in_array($header, [
            'last name',
            'lastname',
            'nom famille',
            'nom de famille',
        ], true);
    }

    private function isIdentifierHeader(string $header): bool
    {
        return in_array($header, [
            'identifiant',
            'id',
            'external id',
            'code',
            'matricule',
            'n',
            'no',
            'num',
            'n matricule',
            'numero matricule',
            'numero',
            'badge',
            'n badge',
            'numero badge',
            'uid',
            'employee id',
            'employe id',
        ], true);
    }

    private function isCompanyHeader(string $header): bool
    {
        return in_array($header, [
            'entreprise',
            'societe',
            'company',
            'rig company',
            'compagnie',
            'client',
            'crew',
        ], true);
    }

    private function isPositionHeader(string $header): bool
    {
        return in_array($header, [
            'position',
            'poste',
            'fonction',
            'job',
            'metier',
            'titre',
        ], true);
    }

    public function edit(LaReleveEntry $laReleveEntry): View
    {
        if ($this->hasConsumedMeals($laReleveEntry)) {
            abort(403, 'Cette entree ne peut plus etre modifiee car au moins un repas a deja ete consomme.');
        }

        return view('la-releve.edit', [
            'entry' => $laReleveEntry,
            'mealTypeOptions' => ['breakfast', 'lunch', 'dinner'],
        ]);
    }

    public function update(Request $request, LaReleveEntry $laReleveEntry): RedirectResponse
    {
        if ($this->hasConsumedMeals($laReleveEntry)) {
            abort(403, 'Cette entree ne peut plus etre modifiee car au moins un repas a deja ete consomme.');
        }

        $validated = $this->validateEntry($request, $laReleveEntry->id);

        $laReleveEntry->update([
            ...$validated,
            'external_id' => $this->buildInternalExternalId($validated['entry_date'], $laReleveEntry->id, $laReleveEntry->external_id),
            'updated_by_user_id' => $request->user()?->id,
        ]);

        return redirect()
            ->route('la-releve.index', ['date' => $validated['entry_date']])
            ->with('success', 'Entrée La Releve mise à jour.');
    }

    public function export(Request $request)
    {
        $filterMode = trim((string) $request->string('filter_mode'));
        $filterMode = in_array($filterMode, ['day', 'range'], true) ? $filterMode : 'day';

        $format = trim((string) $request->string('format'));
        $format = in_array($format, ['csv', 'excel'], true) ? $format : 'csv';

        $date = trim((string) $request->string('date'));
        $dateFrom = trim((string) $request->string('date_from'));
        $dateTo = trim((string) $request->string('date_to'));
        $search = trim((string) $request->string('q'));

        $selectedDate = $date !== '' ? CarbonImmutable::parse($date)->toDateString() : now()->toDateString();
        $selectedDateFrom = $dateFrom !== '' ? CarbonImmutable::parse($dateFrom)->toDateString() : $selectedDate;
        $selectedDateTo = $dateTo !== '' ? CarbonImmutable::parse($dateTo)->toDateString() : $selectedDate;

        if ($selectedDateFrom > $selectedDateTo) {
            [$selectedDateFrom, $selectedDateTo] = [$selectedDateTo, $selectedDateFrom];
        }

        $entriesQuery = LaReleveEntry::query()
            ->forLaReleve()
            ->search($search);
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
            ->get(['id', 'full_name', 'position', 'rig_company', 'entry_date', 'external_id']);

        if ($entries->isEmpty()) {
            return response()->json([
                'error' => 'Impossible d\'exporter : aucune donnée disponible avec ces filtres.',
            ], 422);
        }

        if ($format === 'excel') {
            $companySummary = collect($entries)
                ->map(fn (LaReleveEntry $entry): string => trim((string) ($entry->rig_company ?? '')) !== '' ? (string) $entry->rig_company : 'Non defini')
                ->countBy()
                ->sortDesc();

            $excel = app(BrandedExcelExportService::class)->build(
                'la-releve-export-' . now()->format('Ymd-His'),
                'RAPPORT - LA RELEVE',
                [
                    'Date',
                    'Heure creation',
                    'Identifiant',
                    'Nom complet',
                    'Poste',
                    'Entreprise',
                       'Petit-dejeuner (0/1)',
                       'Dejeuner (0/1)',
                       'Diner (0/1)',
                    'Total plats consommes',
                    'Statut',
                ],
                $entries->map(function (LaReleveEntry $entry): array {
                       $allowedTypes = array_values(array_filter($entry->allowed_meal_types ?? [], fn ($type) => in_array($type, ['breakfast', 'lunch', 'dinner'], true)));
                       $breakfast = in_array('breakfast', $allowedTypes, true) ? 1 : 0;
                       $lunch = in_array('lunch', $allowedTypes, true) ? 1 : 0;
                       $dinner = in_array('dinner', $allowedTypes, true) ? 1 : 0;

                    return [
                        optional($entry->entry_date)->format('Y-m-d') ?: '',
                        optional($entry->created_at)->format('H:i:s') ?: '',
                        (string) ($entry->external_id ?? ''),
                        (string) ($entry->full_name ?? ''),
                        (string) ($entry->position ?? ''),
                        (string) ($entry->rig_company ?? ''),
                           $breakfast,
                           $lunch,
                           $dinner,
                        (int) ($entry->consumed_meals_count ?? 0),
                        $entry->consumed_meals_count > 0 ? 'Autorisé' : 'Aucun repas consommé',
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
                    'title' => 'SYNTHESE PAR COMPAGNIE',
                    'headers' => ['Compagnie', 'Total entrees'],
                    'rows' => $companySummary->map(fn (int $count, string $company): array => [(string) $company, $count])->values()->all(),
                ]]
            );

            return response()->download($excel['path'], $excel['filename'], [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $delimiter = ',';
        $contentType = 'text/csv; charset=UTF-8';
        $filename = 'la-releve-export-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($entries, $delimiter, $filterMode, $selectedDate, $selectedDateFrom, $selectedDateTo, $search): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, ['RAPPORT - LA RELEVE'], $delimiter);
            fputcsv($output, ['Genere le', now()->format('Y-m-d H:i:s')], $delimiter);
            fputcsv($output, [''], $delimiter);

            fputcsv($output, [
                'Date',
                'Heure creation',
                'Identifiant',
                'Nom complet',
                'Poste',
                'Entreprise',
                'Petit-dejeuner (0/1)',
                'Dejeuner (0/1)',
                'Diner (0/1)',
                'Total plats consommes',
                'Statut',
            ], $delimiter);

            $companySummary = collect();

            foreach ($entries as $entry) {
                $allowedTypes = array_values(array_filter($entry->allowed_meal_types ?? [], fn ($type) => in_array($type, ['breakfast', 'lunch', 'dinner'], true)));
                $breakfast = in_array('breakfast', $allowedTypes, true) ? 1 : 0;
                $lunch = in_array('lunch', $allowedTypes, true) ? 1 : 0;
                $dinner = in_array('dinner', $allowedTypes, true) ? 1 : 0;
                $company = trim((string) ($entry->rig_company ?? '')) !== '' ? (string) $entry->rig_company : 'Non defini';
                $companySummary->push($company);

                fputcsv($output, [
                    optional($entry->entry_date)->format('Y-m-d') ?: '',
                    optional($entry->created_at)->format('H:i:s') ?: '',
                    (string) ($entry->external_id ?? ''),
                    (string) ($entry->full_name ?? ''),
                    (string) ($entry->position ?? ''),
                    (string) ($entry->rig_company ?? ''),
                    $breakfast,
                    $lunch,
                    $dinner,
                    (int) ($entry->consumed_meals_count ?? 0),
                    (int) ($entry->consumed_meals_count ?? 0) > 0 ? 'Autorisé' : 'Aucun repas consommé',
                ], $delimiter);
            }

            fputcsv($output, [''], $delimiter);
            fputcsv($output, ['FILTRES APPLIQUES'], $delimiter);
            fputcsv($output, ['Mode filtre', $filterMode], $delimiter);
            fputcsv($output, ['Date', $selectedDate], $delimiter);
            fputcsv($output, ['Date debut', $selectedDateFrom], $delimiter);
            fputcsv($output, ['Date fin', $selectedDateTo], $delimiter);
            fputcsv($output, ['Recherche', $search !== '' ? $search : '-'], $delimiter);

            $summaryRows = $companySummary
                ->countBy()
                ->sortDesc();

            fputcsv($output, [''], $delimiter);
            fputcsv($output, ['SYNTHESE PAR COMPAGNIE'], $delimiter);
            fputcsv($output, ['Compagnie', 'Total entrees'], $delimiter);
            foreach ($summaryRows as $company => $count) {
                fputcsv($output, [(string) $company, (int) $count], $delimiter);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    public function destroy(LaReleveEntry $laReleveEntry): RedirectResponse
    {
        abort(403, 'Suppression interdite pour l historique La Releve.');
    }

    private function validateEntry(Request $request, ?int $entryId = null): array
    {
        return $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'position' => ['required', 'string', 'max:120'],
            'rig_company' => ['required', 'string', 'max:120'],
            'entry_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:entry_date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'allowed_meal_types' => ['required', 'array', 'min:1'],
            'allowed_meal_types.*' => ['string', 'in:breakfast,lunch,dinner'],
        ]);
    }

    private function hasConsumedMeals(LaReleveEntry $entry): bool
    {
        return $entry->mealLogs()->exists();
    }

    private function buildInternalExternalId(string $entryDate, ?int $ignoreEntryId = null, ?string $preferred = null): string
    {
        if (is_string($preferred) && trim($preferred) !== '') {
            $exists = LaReleveEntry::query()
                ->whereDate('entry_date', $entryDate)
                ->where('external_id', $preferred)
                ->when($ignoreEntryId !== null, function ($query) use ($ignoreEntryId): void {
                    $query->where('id', '!=', $ignoreEntryId);
                })
                ->exists();

            if (!$exists) {
                return $preferred;
            }
        }

        $base = 'LR-' . str_replace('-', '', $entryDate);

        for ($i = 1; $i <= 9999; $i++) {
            $candidate = $base . '-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $exists = LaReleveEntry::query()
                ->whereDate('entry_date', $entryDate)
                ->where('external_id', $candidate)
                ->when($ignoreEntryId !== null, function ($query) use ($ignoreEntryId): void {
                    $query->where('id', '!=', $ignoreEntryId);
                })
                ->exists();

            if (!$exists) {
                return $candidate;
            }
        }

        return $base . '-' . now()->format('His');
    }
}
