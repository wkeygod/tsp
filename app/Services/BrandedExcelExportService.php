<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class BrandedExcelExportService
{
    private const SYSTEM_BRAND_LINE = "SCA Catering • SARL Societe Coopérative d'Approvisionnement (SCA)";
    private const SYSTEM_BRAND_SUBLINE = 'Rapport officiel SCA';

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, scalar|null>> $rows
     * @param array<string, scalar|null> $filters
     * @param array<int, array{title:string, headers:array<int, string>, rows:array<int, array<int, scalar|null>>}> $sections
     * @return array{path:string, filename:string}
     */
    public function build(string $filenameBase, string $title, array $headers, array $rows, array $filters = [], array $sections = []): array
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Export');

        $mainColumnCount = max(1, count($headers));
        $mainLastColumn = Coordinate::stringFromColumnIndex($mainColumnCount);

        $sheet->mergeCells('A1:' . $mainLastColumn . '1');
        $sheet->mergeCells('A2:' . $mainLastColumn . '2');
        $sheet->setCellValue('A1', $title);
        $sheet->setCellValue('A2', self::SYSTEM_BRAND_LINE);
        $sheet->setCellValue('A3', self::SYSTEM_BRAND_SUBLINE . ' • Généré le : ' . now()->format('Y-m-d H:i:s'));

        $sheet->getStyle('A1:' . $mainLastColumn . '1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4F91']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle('A2:' . $mainLastColumn . '2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['argb' => 'FFE8F2FF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1A79B8']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(10);

        $rowPointer = 5;

        $this->writeSectionTable($sheet, $rowPointer, 'TABLEAU PRINCIPAL', $headers, $rows);

        $rowPointer += 2;
        if ($filters !== []) {
            $sheet->setCellValue('A' . $rowPointer, 'FILTRES APPLIQUÉS');
            $sheet->mergeCells('A' . $rowPointer . ':B' . $rowPointer);
            $sheet->getStyle('A' . $rowPointer . ':B' . $rowPointer)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2F6FAE']],
            ]);
            $rowPointer++;

            foreach ($filters as $label => $value) {
                $sheet->setCellValue('A' . $rowPointer, (string) $label);
                $sheet->setCellValue('B' . $rowPointer, (string) ($value ?? '-'));
                $rowPointer++;
            }

            $rowPointer += 1;
        }

        foreach ($sections as $section) {
            $this->writeSectionTable(
                $sheet,
                $rowPointer,
                $section['title'],
                $section['headers'],
                $section['rows']
            );
            $rowPointer += 2;
        }

        foreach (range('A', 'Z') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'export_xlsx_');
        if ($tempPath === false) {
            throw new \RuntimeException('Impossible de créer le fichier temporaire d\'export.');
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return [
            'path' => $tempPath,
            'filename' => $filenameBase . '.xlsx',
        ];
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, array<int, scalar|null>> $rows
     */
    private function writeSectionTable($sheet, int &$rowPointer, string $title, array $headers, array $rows): void
    {
        $colCount = max(1, count($headers));
        $lastColumn = Coordinate::stringFromColumnIndex($colCount);

        $sheet->setCellValue('A' . $rowPointer, $title);
        $sheet->mergeCells('A' . $rowPointer . ':' . $lastColumn . $rowPointer);
        $sheet->getStyle('A' . $rowPointer . ':' . $lastColumn . $rowPointer)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F4F91']],
        ]);
        $rowPointer++;

        foreach ($headers as $index => $header) {
            $cell = Coordinate::stringFromColumnIndex($index + 1) . $rowPointer;
            $sheet->setCellValue($cell, $header);
        }
        $sheet->getStyle('A' . $rowPointer . ':' . $lastColumn . $rowPointer)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF2F6FAE']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $headerRow = $rowPointer;
        $rowPointer++;

        foreach ($rows as $row) {
            foreach ($row as $index => $value) {
                $cell = Coordinate::stringFromColumnIndex($index + 1) . $rowPointer;
                $sheet->setCellValue($cell, $value);
            }
            $rowPointer++;
        }

        $lastDataRow = max($headerRow, $rowPointer - 1);
        $sheet->getStyle('A' . $headerRow . ':' . $lastColumn . $lastDataRow)->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FFD6E1EE'],
                ],
            ],
        ]);

        $sheet->setAutoFilter('A' . $headerRow . ':' . $lastColumn . $headerRow);
    }
}
