<?php

namespace ModPMS;

use DateTimeImmutable;
use RuntimeException;

require_once __DIR__ . '/Pdf/Fpdf.php';

class ReportPdf extends \FPDF
{
    /**
     * @return array<string, mixed>
     */
    public function getCurrentFontMetrics(): array
    {
        return $this->CurrentFont;
    }

    public function getFontSizeInUserUnit(): float
    {
        return $this->FontSize;
    }

    public function getCellMargin(): float
    {
        return $this->cMargin;
    }
}

class ReportPdfRenderer
{
    private string $storageDirectory;

    public function __construct(?string $storageDirectory = null)
    {
        $this->storageDirectory = $storageDirectory ?? __DIR__ . '/../storage/reports';
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function renderBreakfastList(DateTimeImmutable $date, array $entries): string
    {
        $this->ensureStorageDirectory();

        $titleDate = $date->format('d.m.Y');
        $pdf = $this->createPdf('Frühstücksliste', 'Datum: ' . $titleDate);

        $headers = ['Zimmer/Kategorie', 'Gast', 'Firma', 'Personen', 'Zeitraum', 'Frühstück'];
        $widths = [28, 52, 40, 15, 30, 15];
        $aligns = ['L', 'L', 'L', 'C', 'L', 'C'];

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $entry['room_label'] ?? '-',
                $entry['guest_name'] ?? '-',
                $entry['company_name'] ?? '-',
                (string) ($entry['occupancy'] ?? 0),
                $this->formatDateRange($entry['arrival'] ?? null, $entry['departure'] ?? null),
                ($entry['has_breakfast'] ?? false) ? 'Ja' : 'Nein',
            ];
        }

        $this->renderTable($pdf, $headers, $rows, $widths, $aligns);

        if ($rows === []) {
            $this->renderEmptyHint($pdf, 'Keine Gäste mit Frühstück für diesen Tag gefunden.');
        }

        $filename = $this->generateFilename('fruehstueck_' . $date->format('Ymd'));
        $targetPath = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $pdf->Output('F', $targetPath);

        return 'storage/reports/' . $filename;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function renderCleaningList(DateTimeImmutable $date, array $entries): string
    {
        $this->ensureStorageDirectory();

        $pdf = $this->createPdf('Putzliste', 'Datum: ' . $date->format('d.m.Y'));

        $headers = ['Zimmer/Kategorie', 'Gast', 'Firma', 'Personen', 'Zeitraum', 'Status'];
        $widths = [28, 52, 40, 15, 30, 15];
        $aligns = ['L', 'L', 'L', 'C', 'L', 'C'];

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                $entry['room_label'] ?? '-',
                $entry['guest_name'] ?? '-',
                $entry['company_name'] ?? '-',
                (string) ($entry['occupancy'] ?? 0),
                $this->formatDateRange($entry['arrival'] ?? null, $entry['departure'] ?? null),
                $entry['state'] ?? '-',
            ];
        }

        $this->renderTable($pdf, $headers, $rows, $widths, $aligns);

        if ($rows === []) {
            $this->renderEmptyHint($pdf, 'Keine Zimmerbewegungen für diesen Tag gefunden.');
        }

        $filename = $this->generateFilename('putzliste_' . $date->format('Ymd'));
        $targetPath = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $pdf->Output('F', $targetPath);

        return 'storage/reports/' . $filename;
    }

    /**
     * @param array<string, mixed> $report
     */
    public function renderMonthlyReport(array $report): string
    {
        if (!isset($report['period_start']) || !($report['period_start'] instanceof DateTimeImmutable)) {
            throw new RuntimeException('Ungültige Monatsdaten übergeben.');
        }
        if (!isset($report['period_end']) || !($report['period_end'] instanceof DateTimeImmutable)) {
            throw new RuntimeException('Ungültige Monatsdaten übergeben.');
        }

        $this->ensureStorageDirectory();

        /** @var DateTimeImmutable $periodStart */
        $periodStart = $report['period_start'];
        /** @var DateTimeImmutable $periodEnd */
        $periodEnd = $report['period_end'];
        $displayEnd = $periodEnd->modify('-1 day');

        $subtitle = sprintf('Zeitraum: %s – %s', $periodStart->format('d.m.Y'), $displayEnd->format('d.m.Y'));
        $pdf = $this->createPdf('Monatsbericht', $subtitle);

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $this->convertText('Anreisen: ' . (int) ($report['arrivals'] ?? 0)), 0, 1);
        $pdf->Cell(0, 6, $this->convertText('Abreisen: ' . (int) ($report['departures'] ?? 0)), 0, 1);
        $pdf->Cell(0, 6, $this->convertText('Reservierungen mit Nächten: ' . (int) ($report['reservation_count'] ?? 0)), 0, 1);
        $pdf->Cell(0, 6, $this->convertText('Zimmernächte: ' . (int) ($report['total_room_nights'] ?? 0)), 0, 1);
        $pdf->Cell(0, 6, $this->convertText('Übernachtungen (Personen): ' . (int) ($report['total_overnights'] ?? 0)), 0, 1);
        $averageStay = isset($report['average_stay']) ? (float) $report['average_stay'] : 0.0;
        $pdf->Cell(0, 6, $this->convertText('Ø Aufenthalt (Nächte): ' . number_format($averageStay, 2, ',', '.')), 0, 1);

        if (isset($report['status_breakdown']) && is_array($report['status_breakdown']) && $report['status_breakdown'] !== []) {
            $pdf->Ln(2);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 6, $this->convertText('Status-Übersicht'), 0, 1);
            $pdf->SetFont('Arial', '', 11);
            foreach ($report['status_breakdown'] as $statusInfo) {
                if (!is_array($statusInfo)) {
                    continue;
                }
                $label = $statusInfo['status'] ?? '';
                $count = (int) ($statusInfo['count'] ?? 0);
                $pdf->Cell(0, 6, $this->convertText(sprintf('%s: %d', $label !== '' ? $label : 'Unbekannt', $count)), 0, 1);
            }
        }

        $pdf->Ln(4);

        $headers = ['Reservierung', 'Gast/Firma', 'Zimmer', 'Zeitraum', 'Nächte', 'Pers.', 'Umsatz (€)'];
        $widths = [26, 52, 28, 32, 12, 12, 18];
        $aligns = ['L', 'L', 'L', 'L', 'R', 'R', 'R'];

        $rows = [];
        if (isset($report['entries']) && is_array($report['entries'])) {
            foreach ($report['entries'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $reservationLabel = $entry['reservation_number'] ?? '';
                $statusLabel = $entry['status'] ?? '';
                if ($statusLabel !== '') {
                    $reservationLabel = trim((string) $reservationLabel) !== ''
                        ? sprintf('%s · %s', $reservationLabel, $statusLabel)
                        : $statusLabel;
                }

                $guest = $entry['guest_name'] ?? '';
                $company = $entry['company_name'] ?? '';
                if ($company !== '' && $company !== 'Privatgast') {
                    $guest = $guest !== '' ? sprintf('%s / %s', $guest, $company) : $company;
                }

                $rows[] = [
                    $reservationLabel !== '' ? $reservationLabel : '—',
                    $guest !== '' ? $guest : '—',
                    $entry['room_label'] ?? '—',
                    $this->formatDateRange($entry['arrival'] ?? null, $entry['departure'] ?? null),
                    (string) ($entry['nights_in_period'] ?? 0),
                    (string) ($entry['occupancy'] ?? 0),
                    $this->formatCurrency($entry['total_revenue'] ?? 0.0),
                ];
            }
        }

        $this->renderTable($pdf, $headers, $rows, $widths, $aligns);

        if ($rows === []) {
            $this->renderEmptyHint($pdf, 'Keine Reservierungen im ausgewählten Zeitraum gefunden.');
        }

        $filename = $this->generateFilename('monatsbericht_' . $periodStart->format('Ym'));
        $targetPath = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $pdf->Output('F', $targetPath);

        return 'storage/reports/' . $filename;
    }

    /**
     * @param array<string, mixed> $report
     */
    public function renderMonthlyClosingReport(array $report): string
    {
        if (!isset($report['period_start']) || !($report['period_start'] instanceof DateTimeImmutable)) {
            throw new RuntimeException('Ungültige Monatsdaten übergeben.');
        }
        if (!isset($report['period_end']) || !($report['period_end'] instanceof DateTimeImmutable)) {
            throw new RuntimeException('Ungültige Monatsdaten übergeben.');
        }

        $this->ensureStorageDirectory();

        /** @var DateTimeImmutable $periodStart */
        $periodStart = $report['period_start'];
        /** @var DateTimeImmutable $periodEnd */
        $periodEnd = $report['period_end'];
        $displayEnd = $periodEnd->modify('-1 day');

        $subtitle = sprintf('Zeitraum: %s – %s', $periodStart->format('d.m.Y'), $displayEnd->format('d.m.Y'));
        $pdf = $this->createPdf('Monatsabschlussbericht', $subtitle);

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $this->convertText('Gesamtumsatz: ' . $this->formatCurrency($report['total_revenue'] ?? 0.0)), 0, 1);
        $pdf->Cell(0, 6, $this->convertText('Zimmerumsatz: ' . $this->formatCurrency($report['total_room_revenue'] ?? 0.0)), 0, 1);
        $pdf->Cell(0, 6, $this->convertText('Artikelumsatz: ' . $this->formatCurrency($report['total_article_revenue'] ?? 0.0)), 0, 1);
        $pdf->Cell(0, 6, $this->convertText('Übernachtungen (Personen): ' . (int) ($report['total_overnights'] ?? 0)), 0, 1);

        if (isset($report['status_financials']) && is_array($report['status_financials']) && $report['status_financials'] !== []) {
            $pdf->Ln(2);
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 6, $this->convertText('Umsatz nach Status'), 0, 1);
            $pdf->SetFont('Arial', '', 11);
            foreach ($report['status_financials'] as $statusInfo) {
                if (!is_array($statusInfo)) {
                    continue;
                }
                $label = $statusInfo['status'] ?? '';
                $total = $statusInfo['total_revenue'] ?? 0.0;
                $pdf->Cell(0, 6, $this->convertText(sprintf('%s: %s', $label !== '' ? $label : 'Unbekannt', $this->formatCurrency($total))), 0, 1);
            }
        }

        $pdf->Ln(4);

        $headers = ['Reservierung', 'Gast/Firma', 'Zimmer', 'Zeitraum', 'Nächte', 'Pers.', 'Umsatz (€)'];
        $widths = [26, 52, 28, 32, 12, 12, 18];
        $aligns = ['L', 'L', 'L', 'L', 'R', 'R', 'R'];

        $rows = [];
        if (isset($report['entries']) && is_array($report['entries'])) {
            foreach ($report['entries'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $reservationLabel = $entry['reservation_number'] ?? '';
                if (($entry['status'] ?? '') !== '') {
                    $reservationLabel = trim((string) $reservationLabel) !== ''
                        ? sprintf('%s · %s', $reservationLabel, $entry['status'])
                        : (string) $entry['status'];
                }

                $guest = $entry['guest_name'] ?? '';
                $company = $entry['company_name'] ?? '';
                if ($company !== '' && $company !== 'Privatgast') {
                    $guest = $guest !== '' ? sprintf('%s / %s', $guest, $company) : $company;
                }

                $rows[] = [
                    $reservationLabel !== '' ? $reservationLabel : '—',
                    $guest !== '' ? $guest : '—',
                    $entry['room_label'] ?? '—',
                    $this->formatDateRange($entry['arrival'] ?? null, $entry['departure'] ?? null),
                    (string) ($entry['nights_in_period'] ?? 0),
                    (string) ($entry['occupancy'] ?? 0),
                    $this->formatCurrency($entry['total_revenue'] ?? 0.0),
                ];
            }
        }

        $this->renderTable($pdf, $headers, $rows, $widths, $aligns);

        if ($rows === []) {
            $this->renderEmptyHint($pdf, 'Keine abrechenbaren Reservierungen im ausgewählten Zeitraum gefunden.');
        }

        $filename = $this->generateFilename('monatsabschluss_' . $periodStart->format('Ym'));
        $targetPath = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
        $pdf->Output('F', $targetPath);

        return 'storage/reports/' . $filename;
    }

    private function ensureStorageDirectory(): void
    {
        if (is_dir($this->storageDirectory)) {
            return;
        }

        if (!mkdir($concurrentDirectory = $this->storageDirectory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('Das Verzeichnis für Berichte konnte nicht erstellt werden.');
        }
    }

    private function createPdf(string $title, string $subtitle): ReportPdf
    {
        $pdf = new ReportPdf('L', 'mm', 'A4');
        $pdf->SetMargins(15, 20, 15);
        $pdf->AddPage();

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, $this->convertText($title), 0, 1);

        if ($subtitle !== '') {
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 8, $this->convertText($subtitle), 0, 1);
        }

        $pdf->Ln(2);

        return $pdf;
    }

    private function renderTable(ReportPdf $pdf, array $headers, array $rows, array $widths, array $aligns): void
    {
        $lineHeight = 6.0;

        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetFont('Arial', 'B', 10);

        foreach ($headers as $index => $header) {
            $width = $widths[$index] ?? 0.0;
            $pdf->Cell($width, $lineHeight + 2.0, $this->convertText((string) $header), 1, 0, 'L', true);
        }
        $pdf->Ln();

        $pdf->SetFont('Arial', '', 10);
        foreach ($rows as $row) {
            $rowHeight = $this->calculateRowHeight($pdf, $row, $widths, $lineHeight);
            $startX = $pdf->GetX();
            $startY = $pdf->GetY();

            foreach (array_values($row) as $index => $value) {
                $width = $widths[$index] ?? 0.0;
                $align = $aligns[$index] ?? 'L';
                $x = $pdf->GetX();
                $y = $pdf->GetY();

                $pdf->MultiCell($width, $lineHeight, $this->convertText((string) $value), 1, $align);
                $pdf->SetXY($x + $width, $y);
            }

            $pdf->SetXY($startX, $startY + $rowHeight);
        }
    }

    /**
     * @param array<int, string> $row
     * @param array<int, float> $widths
     */
    private function calculateRowHeight(ReportPdf $pdf, array $row, array $widths, float $lineHeight): float
    {
        $maxLines = 1;

        foreach (array_values($row) as $index => $value) {
            $width = $widths[$index] ?? 0.0;
            if ($width <= 0.0) {
                continue;
            }

            $lines = $this->getNumberOfLines($pdf, $width, $this->convertText((string) $value));
            if ($lines > $maxLines) {
                $maxLines = $lines;
            }
        }

        return $maxLines * $lineHeight;
    }

    private function getNumberOfLines(ReportPdf $pdf, float $width, string $text): int
    {
        $text = str_replace("\r", '', $text);
        $textLength = strlen($text);

        if ($textLength > 0 && $text[$textLength - 1] === "\n") {
            $textLength--;
        }

        $currentFont = $pdf->getCurrentFontMetrics();
        $cw = is_array($currentFont['cw'] ?? null) ? $currentFont['cw'] : [];
        $fontSize = $pdf->getFontSizeInUserUnit();
        $cellMargin = $pdf->getCellMargin();

        $availableWidth = max($width - (2.0 * $cellMargin), 0.0);
        if ($availableWidth <= 0.0 || $fontSize <= 0.0) {
            return max(1, substr_count($text, "\n") + 1);
        }

        $wmax = ($availableWidth * 1000) / $fontSize;
        $lines = 1;
        $lineLength = 0;
        $sep = -1;
        $i = 0;
        $j = 0;

        while ($i < $textLength) {
            $char = $text[$i];

            if ($char === "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $lineLength = 0;
                $lines++;
                continue;
            }

            if ($char === ' ') {
                $sep = $i;
            }

            $lineLength += $cw[$char] ?? 0;

            if ($lineLength > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }

                $sep = -1;
                $j = $i;
                $lineLength = 0;
                $lines++;
                continue;
            }

            $i++;
        }

        return $lines;
    }

    private function renderEmptyHint(ReportPdf $pdf, string $message): void
    {
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->Cell(0, 8, $this->convertText($message), 0, 1);
    }

    private function formatDateRange(?DateTimeImmutable $start, ?DateTimeImmutable $end): string
    {
        $startLabel = $start instanceof DateTimeImmutable ? $start->format('d.m.Y') : '—';
        $endLabel = $end instanceof DateTimeImmutable ? $end->format('d.m.Y') : '—';

        return $startLabel . ' – ' . $endLabel;
    }

    private function formatCurrency($value): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;

        return number_format($number, 2, ',', '.');
    }

    private function sanitizeFilename(string $filename): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        return $normalized !== '' ? $normalized : 'bericht';
    }

    private function generateFilename(string $prefix): string
    {
        try {
            $random = bin2hex(random_bytes(4));
        } catch (\Throwable $exception) {
            $random = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8);
        }

        $name = $prefix . '_' . date('Ymd_His') . '_' . $random;

        return $this->sanitizeFilename($name) . '.pdf';
    }

    private function convertText(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        if ($converted === false) {
            return $text;
        }

        return $converted;
    }
}
