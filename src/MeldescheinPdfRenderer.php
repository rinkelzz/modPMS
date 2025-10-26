<?php

namespace ModPMS;

require_once __DIR__ . '/Pdf/Fpdf.php';

use RuntimeException;

class MeldescheinPdfRenderer
{
    private string $storageDirectory;
    private string $publicDirectory;

    public function __construct(?string $storageDirectory = null, ?string $publicDirectory = null)
    {
        $this->storageDirectory = $storageDirectory ?? __DIR__ . '/../storage/meldescheine';
        $this->publicDirectory = $publicDirectory ?? __DIR__ . '/../public';
    }

    /**
     * @param array<string, mixed> $form
     */
    public function render(array $form): string
    {
        if (!is_dir($this->storageDirectory) && !mkdir($concurrentDirectory = $this->storageDirectory, 0775, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('Das Verzeichnis für Meldescheine konnte nicht erstellt werden.');
        }

        $formNumber = isset($form['form_number']) && $form['form_number'] !== ''
            ? (string) $form['form_number']
            : 'Meldeschein';

        $filename = $this->sanitizeFilename($formNumber) . '.pdf';
        $targetPath = rtrim($this->storageDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetMargins(15, 20, 15);
        $pdf->AddPage();

        $this->renderHeader($pdf, $formNumber, $form);
        $this->renderGuestSection($pdf, $form);
        $this->renderStaySection($pdf, $form);
        $this->renderCompanySection($pdf, $form);
        $this->renderNotes($pdf, $form);
        $this->renderSignatureSection($pdf, $form);

        $pdf->Output('F', $targetPath);

        return 'storage/meldescheine/' . $filename;
    }

    /**
     * @param array<string, mixed> $form
     */
    private function renderHeader(\FPDF $pdf, string $formNumber, array $form): void
    {
        $issuedDate = isset($form['issued_date']) ? $this->formatDate((string) $form['issued_date']) : null;

        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, $this->convertText('Meldeschein'), 0, 1);

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $this->convertText('Nummer: ' . $formNumber), 0, 1);
        if ($issuedDate !== null) {
            $pdf->Cell(0, 6, $this->convertText('Ausgestellt am: ' . $issuedDate), 0, 1);
        }

        $pdf->Ln(4);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function renderGuestSection(\FPDF $pdf, array $form): void
    {
        $guest = isset($form['guest']) && is_array($form['guest']) ? $form['guest'] : [];
        $guestName = isset($guest['name']) && $guest['name'] !== '' ? (string) $guest['name'] : ($form['guest_name'] ?? 'Gast');
        $dateOfBirth = isset($guest['date_of_birth']) ? $this->formatDate((string) $guest['date_of_birth']) : null;
        $nationality = isset($guest['nationality']) ? trim((string) $guest['nationality']) : '';
        $document = isset($guest['document']) && is_array($guest['document']) ? $guest['document'] : [];
        $documentParts = [];
        if (!empty($document['type'])) {
            $documentParts[] = trim((string) $document['type']);
        }
        if (!empty($document['number'])) {
            $documentParts[] = trim((string) $document['number']);
        }
        $contact = isset($guest['contact']) && is_array($guest['contact']) ? $guest['contact'] : [];
        $contactParts = [];
        if (!empty($contact['email'])) {
            $contactParts[] = trim((string) $contact['email']);
        }
        if (!empty($contact['phone'])) {
            $contactParts[] = trim((string) $contact['phone']);
        }
        $addressLines = isset($guest['address_lines']) && is_array($guest['address_lines']) ? $guest['address_lines'] : [];

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $this->convertText('Gastdaten'), 0, 1);

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $this->convertText('Name: ' . $guestName), 0, 1);
        if ($dateOfBirth !== null) {
            $pdf->Cell(0, 6, $this->convertText('Geburtsdatum: ' . $dateOfBirth), 0, 1);
        }
        if ($nationality !== '') {
            $pdf->Cell(0, 6, $this->convertText('Staatsangehörigkeit: ' . $nationality), 0, 1);
        }
        if ($documentParts !== []) {
            $pdf->Cell(0, 6, $this->convertText('Ausweisdaten: ' . implode(' · ', $documentParts)), 0, 1);
        }
        if ($addressLines !== []) {
            $pdf->Cell(0, 6, $this->convertText('Adresse:'), 0, 1);
            foreach ($addressLines as $line) {
                $pdf->Cell(0, 6, $this->convertText((string) $line), 0, 1);
            }
        }
        if ($contactParts !== []) {
            $pdf->Cell(0, 6, $this->convertText('Kontakt: ' . implode(' · ', $contactParts)), 0, 1);
        }

        $pdf->Ln(3);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function renderStaySection(\FPDF $pdf, array $form): void
    {
        $stay = isset($form['stay']) && is_array($form['stay']) ? $form['stay'] : [];
        $arrival = isset($stay['arrival_date']) ? $this->formatDate((string) $stay['arrival_date']) : null;
        $departure = isset($stay['departure_date']) ? $this->formatDate((string) $stay['departure_date']) : null;
        $nights = isset($stay['nights']) && $stay['nights'] !== null ? (int) $stay['nights'] : null;
        $purposeLabel = isset($stay['purpose_label']) && $stay['purpose_label'] !== ''
            ? (string) $stay['purpose_label']
            : (isset($form['purpose_of_stay']) && $form['purpose_of_stay'] === 'geschäftlich' ? 'Geschäftlich' : 'Privat');
        $roomLabel = isset($stay['room_label']) ? trim((string) $stay['room_label']) : '';
        if ($roomLabel === '' && isset($form['room_label'])) {
            $roomLabel = trim((string) $form['room_label']);
        }
        $reservationNumber = isset($stay['reservation_number']) ? trim((string) $stay['reservation_number']) : '';

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $this->convertText('Aufenthalt'), 0, 1);

        $pdf->SetFont('Arial', '', 11);
        if ($arrival !== null || $departure !== null) {
            $periodParts = [];
            $periodParts[] = $arrival !== null ? $arrival : 'offen';
            $periodParts[] = $departure !== null ? $departure : 'offen';
            $pdf->Cell(0, 6, $this->convertText('Zeitraum: ' . implode(' – ', $periodParts)), 0, 1);
        }
        if ($nights !== null && $nights > 0) {
            $pdf->Cell(0, 6, $this->convertText('Nächte: ' . $nights), 0, 1);
        }
        $pdf->Cell(0, 6, $this->convertText('Reisezweck: ' . $purposeLabel), 0, 1);
        if ($roomLabel !== '') {
            $pdf->Cell(0, 6, $this->convertText('Zimmer: ' . $roomLabel), 0, 1);
        }
        if ($reservationNumber !== '') {
            $pdf->Cell(0, 6, $this->convertText('Reservierungsnummer: ' . $reservationNumber), 0, 1);
        }

        $pdf->Ln(3);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function renderCompanySection(\FPDF $pdf, array $form): void
    {
        $company = isset($form['company']) && is_array($form['company']) ? $form['company'] : null;
        if ($company === null || ($company['name'] ?? '') === '') {
            return;
        }

        $companyName = trim((string) $company['name']);
        $addressLines = isset($company['address_lines']) && is_array($company['address_lines']) ? $company['address_lines'] : [];
        $taxId = isset($company['tax_id']) ? trim((string) $company['tax_id']) : '';
        $contact = isset($company['contact']) && is_array($company['contact']) ? $company['contact'] : [];
        $contactParts = [];
        if (!empty($contact['email'])) {
            $contactParts[] = trim((string) $contact['email']);
        }
        if (!empty($contact['phone'])) {
            $contactParts[] = trim((string) $contact['phone']);
        }

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $this->convertText('Firma / Dienstherr'), 0, 1);

        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(0, 6, $this->convertText($companyName), 0, 1);
        foreach ($addressLines as $line) {
            $pdf->Cell(0, 6, $this->convertText((string) $line), 0, 1);
        }
        if ($taxId !== '') {
            $pdf->Cell(0, 6, $this->convertText('Steuernummer/USt-IdNr.: ' . $taxId), 0, 1);
        }
        if ($contactParts !== []) {
            $pdf->Cell(0, 6, $this->convertText('Kontakt: ' . implode(' · ', $contactParts)), 0, 1);
        }

        $pdf->Ln(3);
    }

    /**
     * @param array<string, mixed> $form
     */
    private function renderNotes(\FPDF $pdf, array $form): void
    {
        $notes = '';
        if (isset($form['notes']) && trim((string) $form['notes']) !== '') {
            $notes = trim((string) $form['notes']);
        } elseif (isset($form['guest']['notes']) && trim((string) $form['guest']['notes']) !== '') {
            $notes = trim((string) $form['guest']['notes']);
        }

        if ($notes === '') {
            return;
        }

        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $this->convertText('Hinweise'), 0, 1);

        $pdf->SetFont('Arial', '', 11);
        $pdf->MultiCell(0, 6, $this->convertText($notes));
        $pdf->Ln(3);
    }

    private function renderSignatureSection(\FPDF $pdf, array $form): void
    {
        $pdf->Ln(4);
        $pdf->SetFont('Arial', '', 11);

        $signature = isset($form['signature']) && is_array($form['signature']) ? $form['signature'] : [];

        $guestSignaturePath = null;
        if (isset($signature['guest_signature_path']) && $signature['guest_signature_path'] !== '') {
            $guestSignaturePath = $this->resolveRelativePath((string) $signature['guest_signature_path']);
        } elseif (isset($form['guest_signature_path']) && $form['guest_signature_path'] !== '') {
            $guestSignaturePath = $this->resolveRelativePath((string) $form['guest_signature_path']);
        }

        $guestSignedAt = null;
        if (isset($signature['guest_signed_at']) && $signature['guest_signed_at'] !== '') {
            $guestSignedAt = $this->formatDateTime((string) $signature['guest_signed_at']);
        } elseif (isset($form['guest_signed_at']) && $form['guest_signed_at'] !== '') {
            $guestSignedAt = $this->formatDateTime((string) $form['guest_signed_at']);
        }

        if ($guestSignaturePath !== null) {
            $pdf->Cell(0, 6, $this->convertText('Unterschrift Gast:'), 0, 1);
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $imageWidth = 70.0;
            $imageHeight = 0.0;

            try {
                $size = @getimagesize($guestSignaturePath);
                if (is_array($size) && isset($size[0], $size[1]) && (int) $size[0] > 0) {
                    $imageHeight = ($size[1] / $size[0]) * $imageWidth;
                }
            } catch (\Throwable $exception) {
                $imageHeight = 0.0;
            }

            if ($imageHeight <= 0.0) {
                $imageHeight = 28.0;
            }

            $pdf->Image($guestSignaturePath, $x, $y, $imageWidth);
            $pdf->Ln($imageHeight + 2.0);
        } else {
            $pdf->Cell(0, 6, $this->convertText('Unterschrift Gast: _________________________________'), 0, 1);
        }

        if ($guestSignedAt !== null) {
            $pdf->SetFont('Arial', 'I', 9);
            $pdf->Cell(0, 5, $this->convertText('Signiert am: ' . $guestSignedAt), 0, 1);
            $pdf->SetFont('Arial', '', 11);
        }

        $pdf->Cell(0, 6, $this->convertText('Unterschrift Gastgeber: ____________________________'), 0, 1);
    }

    private function sanitizeFilename(string $filename): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9_\-]/', '_', $filename);

        return $normalized !== '' ? $normalized : 'Meldeschein';
    }

    private function formatDate(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('d.m.Y', $timestamp);
    }

    private function convertText(string $text): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $text);
        if ($converted === false) {
            return $text;
        }

        return $converted;
    }

    private function resolveRelativePath(string $relativePath): ?string
    {
        $trimmed = ltrim($relativePath, '/\\');
        if ($trimmed === '') {
            return null;
        }

        $base = realpath(__DIR__ . '/..');
        if ($base === false) {
            return null;
        }

        $absolute = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
        if (is_file($absolute)) {
            return $absolute;
        }

        return null;
    }

    private function formatDateTime(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $dateTime = new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            return null;
        }

        return $dateTime->format('d.m.Y H:i');
    }
}
