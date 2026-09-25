<?php

namespace App\Services\Import;

use App\Contracts\ScoresheetExtractor;
use App\Enums\ImportDriver;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads Excel (.xlsx/.xls) and CSV scoresheets.
 *
 * The sheet is expected to carry a header row naming the columns. We look for
 * whichever of name / registration number / score / subject we can find, so
 * schools can keep using the spreadsheets they already have.
 */
class SpreadsheetScoresheetExtractor implements ScoresheetExtractor
{
    /** Header keywords mapped to the logical column we need. */
    private const COLUMN_ALIASES = [
        'identifier' => ['reg', 'regno', 'reg no', 'registration', 'registration number', 'adm', 'admission', 'adm no', 'sn reg', 'number', 'id', 'student no'],
        'name' => ['name', 'student', 'student name', 'candidate', 'candidate name', 'full name', 'names', 'surname'],
        'score' => ['score', 'marks', 'mark', 'total', 'total score', 'result', 'grade score', 'obtained'],
        'subject' => ['subject', 'paper', 'course', 'module'],
        'absent' => ['absent', 'abs', 'not present'],
    ];

    public function driver(): ImportDriver
    {
        return ImportDriver::Spreadsheet;
    }

    public function describe(): string
    {
        return 'Read from the uploaded spreadsheet columns.';
    }

    public function supports(string $mimeType, string $extension): bool
    {
        $extension = strtolower($extension);

        return in_array($extension, ['xlsx', 'xls', 'csv', 'txt'], true)
            || str_contains($mimeType, 'spreadsheet')
            || str_contains($mimeType, 'csv')
            || str_contains($mimeType, 'excel');
    }

    public function extract(string $absolutePath, array $context = []): array
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);

        $rows = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = array_merge($rows, $this->extractSheet($sheet, count($rows)));
        }

        $spreadsheet->disconnectWorksheets();

        return $rows;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractSheet(Worksheet $sheet, int $offset): array
    {
        $grid = $sheet->toArray(null, true, false, false);

        if ($grid === []) {
            return [];
        }

        [$headerRowIndex, $map] = $this->locateHeader($grid);

        if ($headerRowIndex === null) {
            return [];
        }

        $out = [];
        $rowNumber = 0;

        foreach ($grid as $index => $line) {
            if ($index <= $headerRowIndex) {
                continue;
            }

            $rowNumber++;
            $raw = [];

            foreach ($line as $columnIndex => $cell) {
                if ($cell !== null && $cell !== '') {
                    $raw[$this->columnLetter($columnIndex)] = $cell;
                }
            }

            if ($raw === []) {
                continue;
            }

            $name = $this->value($line, $map['name'] ?? null);
            $identifier = $this->value($line, $map['identifier'] ?? null);
            $score = $this->value($line, $map['score'] ?? null);
            $subject = $this->value($line, $map['subject'] ?? null);
            $absent = $this->value($line, $map['absent'] ?? null);

            // A line with neither a name nor a number is not a candidate.
            if ($this->blank($name) && $this->blank($identifier)) {
                continue;
            }

            $out[] = [
                'row' => $offset + $rowNumber,
                'identifier' => $this->cleanIdentifier($identifier),
                'name' => $this->cleanName($name),
                'subject' => $this->blank($subject) ? ($context['subject'] ?? null) : trim((string) $subject),
                'score' => $this->cleanScore($score),
                'confidence' => null,
                'absent' => $this->isTruthy($absent),
                'raw' => $raw,
            ];
        }

        return $out;
    }

    /**
     * Find the header row by scoring the first 10 rows against our aliases.
     *
     * @param  array<int,array<int,mixed>>  $grid
     * @return array{0:?int,1:array<string,int>}
     */
    private function locateHeader(array $grid): array
    {
        $bestIndex = null;
        $bestMap = [];
        $bestScore = 0;

        foreach (array_slice($grid, 0, 10, true) as $index => $line) {
            $map = [];

            foreach ($line as $columnIndex => $cell) {
                $normalised = $this->normalise((string) ($cell ?? ''));

                if ($normalised === '') {
                    continue;
                }

                foreach (self::COLUMN_ALIASES as $field => $aliases) {
                    if (isset($map[$field])) {
                        continue;
                    }

                    if (in_array($normalised, $aliases, true) || $this->containsAlias($normalised, $aliases)) {
                        $map[$field] = $columnIndex;
                        break;
                    }
                }
            }

            // A usable header must at least identify the student and the score.
            $usable = isset($map['name']) || isset($map['identifier']);

            if ($usable && isset($map['score']) && count($map) > $bestScore) {
                $bestScore = count($map);
                $bestIndex = $index;
                $bestMap = $map;
            }
        }

        // Fall back to the first non-empty row if nothing scored well.
        if ($bestIndex === null) {
            foreach (array_slice($grid, 0, 5, true) as $index => $line) {
                if (count(array_filter($line, fn ($v) => $v !== null && $v !== '')) >= 2) {
                    return [$index, ['name' => 0, 'score' => count($line) - 1]];
                }
            }
        }

        return [$bestIndex, $bestMap];
    }

    /** @param array<int,string> $aliases */
    private function containsAlias(string $header, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if (str_contains($header, $alias)) {
                return true;
            }
        }

        return false;
    }

    private function value(array $line, ?int $index): mixed
    {
        return $index === null ? null : ($line[$index] ?? null);
    }

    private function normalise(string $value): string
    {
        $value = strtolower(trim($value));

        return trim(preg_replace('/[^a-z0-9 ]+/', ' ', $value) ?? $value);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function cleanName(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    }

    /** Normalise SAC-00001 / sac 1 / SAC/2026/001 into a single form. */
    private function cleanIdentifier(mixed $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        $clean = strtoupper(str_replace(' ', '', trim((string) $value)));

        return $clean ?: null;
    }

    private function cleanScore(mixed $value): ?float
    {
        if ($this->blank($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return round((float) $value, 2);
        }

        // Tolerate "72/100", "72%", " 72 " and stray characters.
        if (preg_match('/-?\d+(\.\d+)?/', str_replace(',', '', (string) $value), $matches)) {
            return round((float) $matches[0], 2);
        }

        return null;
    }

    private function isTruthy(mixed $value): bool
    {
        if ($this->blank($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'y', 'yes', 'true', 'x', 'abs', 'absent'], true);
    }

    private function columnLetter(int $index): string
    {
        $letter = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letter = chr(65 + ($i % 26)) . $letter;
        }

        return $letter;
    }
}
