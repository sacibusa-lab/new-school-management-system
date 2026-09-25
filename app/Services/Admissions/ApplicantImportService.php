<?php

namespace App\Services\Admissions;

use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\SchoolLevel;
use App\Models\User;
use App\Services\ApplicantRegistrationService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Bulk registration of applicants from a spreadsheet.
 *
 * Follows the same rule as score imports in this platform: **nothing is created
 * until a human has looked at the parsed rows.** The office uploads the file, we
 * show exactly what will be registered and flag every problem with its row
 * number, and only then does anything get written.
 *
 * The spreadsheet is not written to disk — the parsed rows ride in the session
 * between the preview and the commit, so nothing is left behind on failure.
 */
class ApplicantImportService
{
    /** One upload cannot carry more than this — beyond it, split the file. */
    public const MAX_ROWS = 500;

    /** How far down the sheet to hunt for the header row. */
    private const HEADER_SEARCH_DEPTH = 10;

    /**
     * Logical column => the spellings an office clerk might actually type.
     * Matching is case- and punctuation-insensitive.
     */
    private const COLUMN_ALIASES = [
        'first_name' => ['first name', 'firstname', 'given name', 'given names', 'other names', 'othername', 'forename'],
        'last_name' => ['surname', 'last name', 'lastname', 'family name', 'family'],
        'middle_name' => ['middle name', 'middlename'],
        'gender' => ['gender', 'sex'],
        'date_of_birth' => ['date of birth', 'dob', 'dateofbirth', 'birth date', 'birthday'],
        'phone' => ['phone', 'phone number', 'phonenumber', 'mobile', 'mobile number', 'contact', 'telephone'],
        'email' => ['email', 'e mail', 'email address'],
        'address' => ['address', 'residential address', 'home address', 'contact address'],
        'city' => ['city', 'town'],
        'state' => ['state', 'state of origin', 'state of residence'],
        'lga' => ['lga', 'local government', 'local govt', 'local government area'],
        'previous_school' => ['previous school', 'last school', 'school attended', 'former school'],
        'level' => ['class', 'level', 'class applied for', 'applying for', 'applying for class', 'grade'],
        'guardian_name' => ['parent name', 'guardian', 'guardian name', 'parent guardian', 'parent or guardian', 'parent'],
        'guardian_relationship' => ['relationship', 'guardian relationship'],
        'guardian_phone' => ['parent phone', 'guardian phone', 'parent phone number', 'guardian phone number', 'parent mobile'],
        'guardian_email' => ['parent email', 'guardian email'],
    ];

    public function __construct(
        private readonly ApplicantRegistrationService $registration,
    ) {
    }

    /**
     * The canonical template the office should fill in.
     *
     * @return array<int,array{key:string,label:string,required:bool,note:string}>
     */
    public function columns(): array
    {
        return [
            ['key' => 'surname', 'label' => 'Surname', 'required' => true, 'note' => 'Family name.'],
            ['key' => 'first_name', 'label' => 'First name', 'required' => true, 'note' => 'Given name.'],
            ['key' => 'middle_name', 'label' => 'Middle name', 'required' => false, 'note' => 'Optional.'],
            ['key' => 'class', 'label' => 'Class', 'required' => true, 'note' => 'Must match a class on the Classes list, e.g. JSS1.'],
            ['key' => 'gender', 'label' => 'Gender', 'required' => false, 'note' => 'Male or Female (M/F also accepted).'],
            ['key' => 'date_of_birth', 'label' => 'Date of birth', 'required' => false, 'note' => 'Any date format Excel accepts.'],
            ['key' => 'parent_name', 'label' => 'Parent name', 'required' => false, 'note' => 'Guardian or parent.'],
            ['key' => 'parent_phone', 'label' => 'Parent phone', 'required' => false, 'note' => 'Used for text messages.'],
            ['key' => 'relationship', 'label' => 'Relationship', 'required' => false, 'note' => 'Father, Mother, Guardian…'],
            ['key' => 'phone', 'label' => 'Phone', 'required' => false, 'note' => 'The applicant\'s own number, if any.'],
            ['key' => 'email', 'label' => 'Email', 'required' => false, 'note' => 'Optional.'],
            ['key' => 'address', 'label' => 'Address', 'required' => false, 'note' => 'Home address.'],
            ['key' => 'state', 'label' => 'State', 'required' => false, 'note' => 'State of residence.'],
            ['key' => 'previous_school', 'label' => 'Previous school', 'required' => false, 'note' => 'Optional.'],
        ];
    }

    /** The header row the downloaded template uses. */
    public function templateHeaders(): array
    {
        return array_column($this->columns(), 'label');
    }

    /**
     * Read a spreadsheet and turn it into reviewable rows.
     *
     * @return array{
     *     rows: array<int,array<string,mixed>>,
     *     matched: array<int,string>,
     *     ignored: array<int,string>,
     *     summary: array<string,int>
     * }
     */
    public function parse(UploadedFile $file): array
    {
        $grid = $this->readGrid($file);

        [$headerIndex, $map] = $this->locateHeader($grid);

        if ($headerIndex === null || ! isset($map['first_name'], $map['last_name'], $map['level'])) {
            throw new \RuntimeException(
                'Could not find the column headings. The sheet needs at least '
                . '“Surname”, “First name” and “Class” across the top — download the template to see the layout.',
            );
        }

        $levels = $this->levelLookup();

        $rows = [];
        $summary = ['total' => 0, 'ok' => 0, 'errors' => 0];
        $truncated = false;

        foreach ($grid as $index => $line) {
            if ($index <= $headerIndex) {
                continue;
            }

            $values = $this->mapRow($line, $map);

            // Skip blank spacer rows rather than reporting them as errors.
            if ($this->isBlank($values)) {
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;

                break;
            }

            $rows[] = $this->prepareRow($values, $index + 1, $levels);
        }

        foreach ($rows as $row) {
            $summary['total']++;
            $row['errors'] === [] ? $summary['ok']++ : $summary['errors']++;
        }

        return [
            'rows' => $rows,
            'matched' => array_keys($map),
            'ignored' => $this->ignoredHeaders($grid[$headerIndex] ?? [], $map),
            'truncated' => $truncated,
            'summary' => $summary,
            'levels' => $levels->values()->unique('id')->pluck('name', 'id')->all(),
        ];
    }

    /**
     * Create the chosen rows. Each row is independent: one failure never
     * abandons the rest of a file the office has already checked.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,int>  $lines  which rows to register
     * @return array{imported:int,numbers:array<int,string>,failed:array<int,array{line:int,reason:string}>}
     */
    public function commit(array $rows, array $lines, ?User $actor = null): array
    {
        $imported = 0;
        $numbers = [];
        $failed = [];

        foreach ($rows as $row) {
            if (! in_array($row['line'], $lines, true)) {
                continue;
            }

            if ($row['errors'] !== []) {
                $failed[] = ['line' => $row['line'], 'reason' => implode(' ', $row['errors'])];

                continue;
            }

            try {
                // No SMS here: a file of 300 would otherwise try 300 synchronous
                // sends and time the request out. The office broadcasts later.
                $applicant = $this->registration->register($row['data'], [], notify: false);

                $numbers[] = $applicant->registration_number;
                $imported++;
            } catch (\Throwable $e) {
                $failed[] = ['line' => $row['line'], 'reason' => $e->getMessage()];
            }
        }

        if ($imported > 0) {
            ActivityLog::record(
                'applicants.imported',
                null,
                "Bulk registered {$imported} applicant(s)" . ($actor ? " by {$actor->name}" : ''),
                ['module' => 'admissions', 'count' => $imported],
            );
        }

        return ['imported' => $imported, 'numbers' => $numbers, 'failed' => $failed];
    }

    /* ------------------------------------------------------------------ */
    /* Reading the sheet                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Read the sheet into a plain array.
     *
     * PHP stores an upload as `phpXXXX.tmp`, with no extension, and
     * PhpSpreadsheet picks its reader from the extension. So the file is parked
     * under its real name for the length of the read and then removed — nothing
     * is kept on disk.
     *
     * @return array<int,array<int,string>>
     */
    private function readGrid(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->extension());

        if (! in_array($extension, ['csv', 'txt', 'xlsx', 'xls'], true)) {
            throw new \RuntimeException('Upload a CSV or Excel file (.csv, .xlsx or .xls).');
        }

        $temporary = 'imports/applicants/' . Str::uuid() . '.' . $extension;

        Storage::disk('local')->put($temporary, (string) file_get_contents((string) $file->getRealPath()));

        try {
            $absolute = Storage::disk('local')->path($temporary);

            $spreadsheet = IOFactory::createReaderForFile($absolute)->load($absolute);

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
     * Score the first few rows against our aliases and take the best match, so a
     * title row ("WAEC candidates 2026") or gap above the headings is tolerated.
     *
     * @param  array<int,array<int,string>>  $grid
     * @return array{0:int|null,1:array<string,int>}
     */
    private function locateHeader(array $grid): array
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
                $key = $this->matchColumn((string) $cell);

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

        return $bestScore >= 2 ? [$bestIndex, $bestMap] : [null, []];
    }

    /** Turn one spreadsheet row into a keyed array using the located header. */
    private function mapRow(array $line, array $map): array
    {
        $values = [];

        foreach ($map as $key => $column) {
            $values[$key] = $this->clean($line[$column] ?? null);
        }

        return $values;
    }

    /**
     * @param  array<string,string|null>  $values
     * @return array<int,string>
     */
    private function ignoredHeaders(array $headerLine, array $map): array
    {
        $used = array_values($map);

        return collect($headerLine)
            ->reject(fn ($cell, $column) => in_array($column, $used, true) || $this->clean($cell) === null)
            ->map(fn ($cell) => (string) $this->clean($cell))
            ->values()
            ->all();
    }

    private function isBlank(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Validating one row                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string,string|null>  $values
     * @param  Collection<int,SchoolLevel>  $levels
     * @return array<string,mixed>
     */
    private function prepareRow(array $values, int $line, Collection $levels): array
    {
        $errors = [];

        $firstName = $values['first_name'] ?? null;
        $lastName = $values['last_name'] ?? null;

        if ($firstName === null) {
            $errors[] = 'First name is missing.';
        }

        if ($lastName === null) {
            $errors[] = 'Surname is missing.';
        }

        // Class: matched loosely so "jss 1", "JSS1" and "Jss-1" all land on JSS1.
        $level = null;
        $typedClass = $values['level'] ?? null;

        if ($typedClass === null) {
            $errors[] = 'Class is missing.';
        } else {
            $level = $levels->get($this->normaliseLevel($typedClass));

            if (! $level) {
                $errors[] = "Class \"{$typedClass}\" does not match any class on the Classes list.";
            }
        }

        $gender = null;

        if (($typedGender = $values['gender'] ?? null) !== null) {
            $gender = $this->normaliseGender($typedGender);

            if ($gender === null) {
                $errors[] = "Gender \"{$typedGender}\" is not understood — use Male or Female.";
            }
        }

        $dob = null;

        if (($typedDob = $values['date_of_birth'] ?? null) !== null) {
            $dob = $this->parseDate($typedDob);

            if ($dob === null) {
                $errors[] = "Date of birth \"{$typedDob}\" could not be read.";
            }
        }

        return [
            'line' => $line,
            'name' => trim(collect([$lastName, $firstName, $values['middle_name'] ?? null])->filter()->implode(' ')),
            'class' => $level?->name ?? $typedClass,
            'gender' => $gender ? ucfirst($gender) : null,
            'dob' => $dob?->format('j M Y'),
            'phone' => $values['phone'] ?? null,
            'guardian' => collect([$values['guardian_name'] ?? null, $values['guardian_phone'] ?? null])
                ->filter()
                ->implode(' · ') ?: null,
            'errors' => $errors,
            'data' => [
                'first_name' => $firstName,
                'middle_name' => $values['middle_name'] ?? null,
                'last_name' => $lastName,
                'gender' => $gender,
                'date_of_birth' => $dob?->toDateString(),
                'phone' => $values['phone'] ?? null,
                'email' => $values['email'] ?? null,
                'address' => $values['address'] ?? null,
                'city' => $values['city'] ?? null,
                'state' => $values['state'] ?? null,
                'lga' => $values['lga'] ?? null,
                'previous_school' => $values['previous_school'] ?? null,
                'level_applied_for_id' => $level?->id,
                'guardian_name' => $values['guardian_name'] ?? null,
                'guardian_relationship' => $values['guardian_relationship'] ?? null,
                'guardian_phone' => $values['guardian_phone'] ?? null,
                'guardian_email' => $values['guardian_email'] ?? null,
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Small helpers                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Every active class, keyed so a loose match works: "jss1" => the model.
     *
     * @return Collection<string,SchoolLevel>
     */
    private function levelLookup(): Collection
    {
        return SchoolLevel::query()
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (SchoolLevel $level) => $this->normaliseLevel($level->name));
    }

    private function normaliseLevel(?string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
    }

    private function normaliseGender(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'm', 'male', 'boy', 'man' => 'male',
            'f', 'female', 'girl', 'woman' => 'female',
            default => null,
        };
    }

    /** Accepts whatever Excel hands over, plus the common hand-typed formats. */
    private function parseDate(string $value): ?Carbon
    {
        $value = trim($value);

        // A bare number is an unformatted Excel date serial.
        if (preg_match('/^\d+(\.\d+)?$/', $value) && (float) $value > 1000) {
            try {
                return Carbon::instance(
                    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value),
                )->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }

        foreach (['d/m/Y', 'j/n/Y', 'd-m-Y', 'j-n-Y', 'Y-m-d', 'd/m/y', 'j M Y', 'M j, Y', 'd M Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($date && $date->year > 1990 && $date->isPast()) {
                return $date->startOfDay();
            }
        }

        return null;
    }

    private function matchColumn(string $header): ?string
    {
        $normalised = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($header)));

        if ($normalised === '') {
            return null;
        }

        foreach (self::COLUMN_ALIASES as $key => $aliases) {
            if (in_array($normalised, $aliases, true)) {
                return $key;
            }
        }

        // Fall back to a "contains" match so "Candidate Surname" still works.
        foreach (self::COLUMN_ALIASES as $key => $aliases) {
            foreach ($aliases as $alias) {
                if (Str::contains($normalised, $alias)) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/', ' ', (string) $value));

        return $value === '' ? null : $value;
    }
}
