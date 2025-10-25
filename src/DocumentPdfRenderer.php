<?php

namespace ModPMS;

use RuntimeException;

require_once __DIR__ . '/Pdf/Fpdf.php';

class DocumentPdfRenderer
{
    private string $storageDirectory;
    private string $publicDirectory;

    public function __construct(?string $storageDirectory = null, ?string $publicDirectory = null)
    {
        $this->storageDirectory = $storageDirectory ?? __DIR__ . '/../storage/documents';
        $this->publicDirectory = $publicDirectory ?? __DIR__ . '/../public';
    }

    public function render(array $document, ?string $logoRelativePath = null): string
    {
        if (!is_dir($this->storageDirectory) && !mkdir($concurrentDirectory = $this->storageDirectory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('Das Verzeichnis für Dokumente konnte nicht erstellt werden.');
        }

        $documentNumber = isset($document['document_number']) && $document['document_number'] !== ''
            ? (string) $document['document_number']
            : 'Dokument';

        $filename = $this->sanitizeFilename($documentNumber) . '.pdf';
        $targetPath = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(15, 20, 15);
        $pdf->AddPage();

        $this->renderLogo($pdf, $logoRelativePath);
        $this->renderHeader($pdf, $document);
        $this->renderRecipient($pdf, $document);
        $this->renderMeta($pdf, $document);
        $this->renderItems($pdf, $document);
        $this->renderBody($pdf, $document);

        $pdf->Output('F', $targetPath);

        return 'storage/documents/' . $filename;
    }

    private function renderLogo(\FPDF $pdf, ?string $logoRelativePath): void
    {
        if ($logoRelativePath === null || $logoRelativePath === '') {
            return;
        }

        $absolutePath = $this->resolvePublicPath($logoRelativePath);
        if ($absolutePath === null) {
            return;
        }

        $pdf->Image($absolutePath, 15, 12, 40);
        $pdf->SetY(30);
    }

    private function renderHeader(\FPDF $pdf, array $document): void
    {
        $typeLabel = $document['type_label'] ?? '';
        $documentNumber = $document['document_number'] ?? '';

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, $this->convertText(trim($typeLabel !== '' ? $typeLabel : 'Dokument')), 0, 1, 'R');

        if ($documentNumber !== '') {
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 6, $this->convertText('Nummer: ' . $documentNumber), 0, 1, 'R');
        }

        $pdf->Ln(5);
    }

    private function renderRecipient(\FPDF $pdf, array $document): void
    {
        $recipient = trim((string) ($document['recipient_name'] ?? ''));
        $address = trim((string) ($document['recipient_address'] ?? ''));

        if ($recipient === '' && $address === '') {
            return;
        }

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $this->convertText('Empfänger:'), 0, 1);
        if ($recipient !== '') {
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(0, 6, $this->convertText($recipient), 0, 1);
        }

        if ($address !== '') {
            $pdf->SetFont('Arial', '', 11);
            foreach (preg_split('/\r?\n/', $address) as $line) {
                $pdf->Cell(0, 6, $this->convertText($line), 0, 1);
            }
        }

        $pdf->Ln(4);
    }

    private function renderMeta(\FPDF $pdf, array $document): void
    {
        $issueDate = $this->formatDate($document['issue_date'] ?? null);
        $dueDate = $this->formatDate($document['due_date'] ?? null);

        if ($issueDate === null && $dueDate === null) {
            return;
        }

        $pdf->SetFont('Arial', '', 11);

        if ($issueDate !== null) {
            $pdf->Cell(0, 6, $this->convertText('Ausstellungsdatum: ' . $issueDate), 0, 1);
        }

        if ($dueDate !== null) {
            $pdf->Cell(0, 6, $this->convertText('Fällig bis: ' . $dueDate), 0, 1);
        }

        $pdf->Ln(4);
    }

    private function renderItems(\FPDF $pdf, array $document): void
    {
        $items = isset($document['items']) && is_array($document['items']) ? $document['items'] : [];
        if ($items === []) {
            return;
        }

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(80, 8, $this->convertText('Position'), 1, 0);
        $pdf->Cell(20, 8, $this->convertText('Menge'), 1, 0, 'R');
        $pdf->Cell(28, 8, $this->convertText('Einzelpreis'), 1, 0, 'R');
        $pdf->Cell(22, 8, $this->convertText('MwSt.'), 1, 0, 'R');
        $pdf->Cell(35, 8, $this->convertText('Gesamt'), 1, 1, 'R');

        $pdf->SetFont('Arial', '', 10);

        $currency = $document['currency'] ?? 'EUR';

        foreach ($items as $item) {
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                $description = 'Position';
            }

            $quantity = isset($item['quantity']) ? (float) $item['quantity'] : 0.0;
            $unitPrice = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;
            $taxRate = isset($item['tax_rate']) ? (float) $item['tax_rate'] : 0.0;
            $totalGross = isset($item['total_gross']) ? (float) $item['total_gross'] : ($unitPrice * $quantity);

            $yBefore = $pdf->GetY();
            $pdf->MultiCell(80, 7, $this->convertText($description), 1);
            $yAfter = $pdf->GetY();
            $rowHeight = $yAfter - $yBefore;
            if ($rowHeight < 7) {
                $rowHeight = 7;
            }

            $pdf->SetXY(95, $yBefore);
            $pdf->Cell(20, $rowHeight, $this->convertText($this->formatQuantity($quantity)), 1, 0, 'R');
            $pdf->Cell(28, $rowHeight, $this->convertText($this->formatMoney($unitPrice, $currency)), 1, 0, 'R');
            $pdf->Cell(22, $rowHeight, $this->convertText($this->formatPercent($taxRate)), 1, 0, 'R');
            $pdf->Cell(35, $rowHeight, $this->convertText($this->formatMoney($totalGross, $currency)), 1, 1, 'R');
        }

        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(150, 8, $this->convertText('Netto'), 1, 0, 'R');
        $pdf->Cell(35, 8, $this->convertText($this->formatMoney((float) ($document['total_net'] ?? 0.0), $currency)), 1, 1, 'R');
        $pdf->Cell(150, 8, $this->convertText('MwSt.'), 1, 0, 'R');
        $pdf->Cell(35, 8, $this->convertText($this->formatMoney((float) ($document['total_vat'] ?? 0.0), $currency)), 1, 1, 'R');
        $pdf->Cell(150, 8, $this->convertText('Brutto'), 1, 0, 'R');
        $pdf->Cell(35, 8, $this->convertText($this->formatMoney((float) ($document['total_gross'] ?? 0.0), $currency)), 1, 1, 'R');

        $pdf->Ln(4);
    }

    private function renderBody(\FPDF $pdf, array $document): void
    {
        $subject = trim((string) ($document['subject'] ?? ''));
        $body = trim((string) ($document['body_html'] ?? ''));

        if ($subject !== '') {
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->MultiCell(0, 6, $this->convertText($subject));
            $pdf->Ln(2);
        }

        if ($body !== '') {
            $plainText = $this->convertHtmlToText($body);
            $pdf->SetFont('Arial', '', 11);
            $pdf->MultiCell(0, 6, $this->convertText($plainText));
        }
    }

    private function convertHtmlToText(string $html): string
    {
        $text = preg_replace('/<\/(p|div)>/i', "\n\n", $html);
        $text = preg_replace('/<br\s*\/?\s*>/i', "\n", $text);
        $text = strip_tags((string) $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim($text);
    }

    private function sanitizeFilename(string $filename): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);
        return $normalized !== '' ? $normalized : 'Dokument';
    }

    private function formatMoney(float $value, string $currency): string
    {
        return number_format($value, 2, ',', '.') . ' ' . strtoupper($currency);
    }

    private function formatPercent(float $value): string
    {
        return number_format($value, 2, ',', '.') . ' %';
    }

    private function formatQuantity(float $value): string
    {
        if (abs($value - round($value)) < 0.01) {
            return (string) round($value);
        }

        return number_format($value, 2, ',', '.');
    }

    private function formatDate(?string $dateValue): ?string
    {
        if ($dateValue === null || $dateValue === '') {
            return null;
        }

        $timestamp = strtotime($dateValue);
        if ($timestamp === false) {
            return null;
        }

        return date('d.m.Y', $timestamp);
    }

    private function resolvePublicPath(string $relativePath): ?string
    {
        $normalized = ltrim($relativePath, '/');
        $candidate = rtrim($this->publicDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalized;

        if (!is_readable($candidate)) {
            return null;
        }

        return $candidate;
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
