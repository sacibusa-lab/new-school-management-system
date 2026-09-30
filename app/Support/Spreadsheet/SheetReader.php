<?php

namespace App\Support\Spreadsheet;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use RuntimeException;

/**
 * Reading a spreadsheet the office made: the part that has nothing to do with what
 * is in it.
 *
 * The same file turns up twice — a sheet of applicants, and a sheet of teachers —
 * and the two differ only in which columns they expect and what makes a row good.
 * Finding the row of headings, matching a heading to a column we know whatever the
 * clerk called it, and turning a row into clean values is the same job both times,
 * so it lives here and each import brings its own list of names.
 *
 * The columns are not this class's business. `locateHeader()` is handed the
 * aliases and hands back which column of the sheet is which of ours; everything
 * after that — is this a real class, is this email already taken — belongs to the
 * import that asked.
 */
class SheetReader
{
    /** How far down the sheet to hunt for the row of headings. */
    public const HEADER_SEARCH_DEPTH = 10;

    /** What PhpSpreadsheet is allowed to be handed, near enough. */
    public const EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    /**
     * The separators an office file actually uses, in the order they are tried.
     *
     * A space is deliberately not among them — see {@see self::delimiterFor()}.
     */
    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Read the sheet into a plain array of rows.
     *
     * PHP stores an upload as `phpXXXX.tmp`, with no extension, and PhpSpreadsheet
     * picks its reader from the extension. So the file is parked under its real name
     * for the length of the read and then removed — nothing is kept on disk.
     *
     * @return array<int,array<int,string>>
     */
    public function read(UploadedFile $file, string $folder): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());

        if (! in_array($extension, self::EXTENSIONS, true)) {
            throw new RuntimeException('Upload a CSV or Excel file (.csv, .xlsx or .xls).');
        }

        $temporary = trim($folder, '/').'/'.Str::uuid().'.'.$extension;

        $contents = (string) file_get_contents((string) $file->getRealPath());

        Storage::disk('local')->put($temporary, $contents);

        try {
            $absolute = Storage::disk('local')->path($temporary);

            $reader = IOFactory::createReaderForFile($absolute);

            if ($reader instanceof Csv) {
                $reader->setDelimiter($this->delimiterFor($contents));
            }

            $spreadsheet = $reader->load($absolute);

            // Formatted values, so a date cell arrives as "12/03/2013" rather
            // than the Excel serial number behind it.
            $grid = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        } finally {
            Storage::disk('local')->delete($temporary);
        }

        return $grid;
    }

    /**
     * Work out what separates the columns, rather than letting the reader guess.
     *
     * PhpSpreadsheet infers it, and a space is one of the things it will infer: a
     * sheet with a title above the headings — "WAEC candidates 2026" — has more
     * spaces in that line than commas anywhere, so the title is what decides it and
     * every row is read as a single cell. Nothing exports a spreadsheet separated
     * by spaces, so the four separators below are what is actually worth choosing
     * between.
     *
     * The one that wins is the one that splits the lines it appears on into the
     * same number of columns every time, and then the most columns — a heading row
     * is the line with the most of them in it.
     */
    private function delimiterFor(string $contents): string
    {
        $lines = array_slice(preg_split('/\r\n|\n|\r/', $contents) ?: [], 0, 20);

        $best = self::DELIMITERS[0];
        $bestScore = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $columns = [];

            foreach ($lines as $line) {
                if (str_contains($line, $delimiter)) {
                    $columns[] = substr_count($line, $delimiter) + 1;
                }
            }

            if ($columns === []) {
                continue;
            }

            $tally = array_count_values($columns);
            $usual = (int) array_search(max($tally), $tally, true);

            // How often it splits a line the same way, and then how many columns
            // that came to, so a line count cannot outbid a real header row.
            $score = ($tally[$usual] / count($columns)) * 1000 + $usual;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $delimiter;
            }
        }

        return $best;
    }

    /**
     * Find the row of headings and work out which column is which.
     *
     * Every row near the top is scored against the aliases and the best one wins, so
     * a title row ("Staff list 2026") or a gap above the headings is tolerated.
     * Below `$minimum` matched columns there is no header here, only a sheet
     * somebody has laid out some other way.
     *
     * @param  array<int,array<int,string>>  $grid
     * @param  array<string,array<int,string>>  $aliases
     * @return array{0:int|null,1:array<string,int>}
     */
    public function locateHeader(array $grid, array $aliases, int $minimum = 2): array
    {
        $bestIndex = null;
        $bestMap = [];
        $bestScore = 0;

        foreach ($grid as $index => $line) {
            if ($index >= self::HEADER_SEARCH_DEPTH) {
                break;
            }

            $map = [];

            foreach ($line as $column => $cell) {
                $key = $this->matchColumn((string) $cell, $aliases);

                // First spelling of a column wins; later duplicates are ignored.
                if ($key && ! in_array($column, $map, true)) {
                    $map[$key] ??= $column;
                }
            }

            if (count($map) > $bestScore) {
                $bestScore = count($map);
                $bestIndex = $index;
                $bestMap = $map;
            }
        }

        return $bestScore >= $minimum ? [$bestIndex, $bestMap] : [null, []];
    }

    /**
     * Which of our columns a heading names, if any.
     *
     * Matching is case- and punctuation-insensitive, and falls back to a "contains"
     * match so "Candidate Surname" still works. Longest alias first, because a
     * sheet may name two things alike: "Parent Email" must land on the parent's
     * email, not be swallowed by the shorter "email" alias for some other column.
     *
     * @param  array<string,array<int,string>>  $aliases
     */
    public function matchColumn(string $header, array $aliases): ?string
    {
        $normalised = $this->normalise($header);

        if ($normalised === '') {
            return null;
        }

        foreach ($aliases as $key => $spellings) {
            if (in_array($normalised, $spellings, true)) {
                return $key;
            }
        }

        $contains = [];

        foreach ($aliases as $key => $spellings) {
            foreach ($spellings as $alias) {
                $contains[] = ['key' => $key, 'alias' => $alias];
            }
        }

        usort($contains, fn ($a, $b) => strlen($b['alias']) <=> strlen($a['alias']));

        foreach ($contains as $candidate) {
            if (Str::contains($normalised, $candidate['alias'])) {
                return $candidate['key'];
            }
        }

        return null;
    }

    /**
     * One row of the sheet, keyed by which of our columns each cell is.
     *
     * @param  array<int,string>  $line
     * @param  array<string,int>  $map
     * @return array<string,string|null>
     */
    public function row(array $line, array $map): array
    {
        $values = [];

        foreach ($map as $key => $column) {
            $values[$key] = $this->clean($line[$column] ?? null);
        }

        return $values;
    }

    /**
     * Headings on the sheet that we do not read, so the office can be told they
     * were ignored rather than left wondering.
     *
     * @param  array<int,string>  $headerLine
     * @param  array<string,int>  $map
     * @return array<int,string>
     */
    public function unreadColumns(array $headerLine, array $map): array
    {
        $used = array_values($map);

        return collect($headerLine)
            ->reject(fn ($cell, $column) => in_array($column, $used, true) || $this->clean($cell) === null)
            ->map(fn ($cell) => (string) $this->clean($cell))
            ->values()
            ->all();
    }

    /**
     * A row with nothing in any of the columns we read — a gap in the sheet, which
     * is skipped rather than reported as a mistake.
     *
     * @param  array<string,string|null>  $values
     */
    public function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }

    /** Collapse the whitespace a cell always seems to arrive with. */
    public function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    /** "First Name!" and "first name" are the same heading. */
    private function normalise(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($header)));
    }
}
