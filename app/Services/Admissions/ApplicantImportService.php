<?php

namespace App\Services\Admissions;

use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\SchoolLevel;
use App\Models\User;
use App\Services\ApplicantRegistrationService;
use App\Support\Spreadsheet\SheetReader;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date;

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

    /** Where a file waits, under its real name, for the length of the read. */
    private const READ_FOLDER = 'imports/applicants';

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
        'nationality' => ['nationality', 'country'],
        'address' => ['address', 'residential address', 'home address', 'contact address'],
        'city' => ['city', 'town'],
        'state' => ['state', 'state of origin', 'state of residence'],
        'lga' => ['lga', 'local government', 'local govt', 'local government area'],
        'level' => ['class', 'level', 'class applied for', 'applying for', 'applying for class', 'grade'],
        'guardian_name' => ['parent name', 'guardian', 'guardian name', 'parent guardian', 'parent or guardian', 'parent'],
        'guardian_relationship' => ['relationship', 'guardian relationship'],
        'guardian_phone' => ['parent phone', 'guardian phone', 'parent phone number', 'guardian phone number', 'parent mobile'],
        'guardian_email' => ['parent email', 'guardian email', 'parent email address', 'guardian email address'],
    ];

    /**
     * Columns the reader looks for but the template does not print, in the order
     * the office registration form asks for them. The guide on the import screen
     * lists these so an office with the data already in a sheet keeps it.
     */
    private const COLUMN_NOTES = [
        'middle_name' => 'Optional.',
        'gender' => 'Male or Female (M/F also accepted).',
        'date_of_birth' => 'Any date format Excel accepts.',
        'nationality' => 'Optional.',
        'guardian_name' => 'The parent or guardian.',
        'guardian_relationship' => 'Father, Mother, Guardian…',
        'address' => 'Home address.',
        'city' => 'Optional.',
        'state' => 'State of residence.',
        'lga' => 'Local government area.',
    ];

    public function __construct(
        private readonly ApplicantRegistrationService $registration,
        private readonly SheetReader $sheets,
    ) {}

    /**
     * The canonical template the office should fill in.
     *
     * Exactly the columns the office registration form insists on, and nothing
     * else, so nobody has to work out which of a dozen columns actually matter.
     * Everything the reader understands beyond these is optional and listed
     * separately by allColumns().
     *
     * @return array<int,array{key:string,label:string,required:bool,note:string}>
     */
    public function columns(): array
    {
        return [
            ['key' => 'last_name', 'label' => 'Surname', 'required' => true, 'note' => 'Family name.'],
            ['key' => 'first_name', 'label' => 'First name', 'required' => true, 'note' => 'Given name.'],
            ['key' => 'level', 'label' => 'Class', 'required' => true, 'note' => 'Must match a class on the Classes list, e.g. JSS1.'],
            ['key' => 'guardian_phone', 'label' => 'Parent phone', 'required' => true, 'note' => 'Needed to open the fee account, and used for text messages.'],
            ['key' => 'guardian_email', 'label' => 'Parent email', 'required' => true, 'note' => 'Needed to open the fee account.'],
        ];
    }

    /**
     * Every column the reader understands, required and optional together.
     *
     * The optional ones are not printed in the template, but a sheet that has
     * them is read rather than ignored, so an office can keep using the file it
     * already keeps.
     *
     * @return array<int,array{key:string,label:string,required:bool,note:string}>
     */
    public function allColumns(): array
    {
        $required = $this->columns();

        $optional = [
            'middle_name' => 'Middle name',
            'gender' => 'Gender',
            'date_of_birth' => 'Date of birth',
            'nationality' => 'Nationality',
            'guardian_name' => 'Parent name',
            'guardian_relationship' => 'Relationship',
            'address' => 'Address',
            'city' => 'City',
            'state' => 'State',
            'lga' => 'LGA',
        ];

        foreach ($optional as $key => $label) {
            $required[] = [
                'key' => $key,
                'label' => $label,
                'required' => false,
                'note' => self::COLUMN_NOTES[$key] ?? 'Optional.',
            ];
        }

        return $required;
    }

    /** The header row the downloaded template uses: the required columns only. */
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
        $grid = $this->sheets->read($file, self::READ_FOLDER);

        [$headerIndex, $map] = $this->sheets->locateHeader($grid, self::COLUMN_ALIASES);

        if ($headerIndex === null || ! isset($map['first_name'], $map['last_name'], $map['level'])) {
            throw new \RuntimeException(
                'Could not find the column headings. The sheet needs at least '
                .'“Surname”, “First name” and “Class” across the top — download the template to see the layout.',
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

            $values = $this->sheets->row($line, $map);

            // Skip blank spacer rows rather than reporting them as errors.
            if ($this->sheets->isBlank($values)) {
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
            'ignored' => $this->sheets->unreadColumns($grid[$headerIndex] ?? [], $map),
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
        // A form posts the ticked rows as strings, while the parsed rows carry them as
        // numbers — and the match below is strict. Left as they arrive, nothing matches,
        // every row is skipped, and the office is told "Nothing was registered" with no
        // reason beside it. So the two sides are put in the same shape first.
        $lines = array_map('intval', $lines);

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
                "Bulk registered {$imported} applicant(s)".($actor ? " by {$actor->name}" : ''),
                ['module' => 'admissions', 'count' => $imported],
            );
        }

        return ['imported' => $imported, 'numbers' => $numbers, 'failed' => $failed];
    }

    /* ------------------------------------------------------------------ */
    /* Validating one row */
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

        // The parent's phone and email are the only contact on the registration
        // form, and the details the fee account is opened in, so a row without
        // them cannot be taken any further — the same rule as the form.
        $parentPhone = $values['guardian_phone'] ?? null;
        $parentEmail = $values['guardian_email'] ?? null;

        if ($parentPhone === null) {
            $errors[] = 'Parent phone is missing.';
        }

        if ($parentEmail === null) {
            $errors[] = 'Parent email is missing.';
        } elseif ($this->emailProblem($parentEmail) !== null) {
            $errors[] = $this->emailProblem($parentEmail);
        }

        return [
            'line' => $line,
            'name' => trim(collect([$lastName, $firstName, $values['middle_name'] ?? null])->filter()->implode(' ')),
            'class' => $level?->name ?? $typedClass,
            'gender' => $gender ? ucfirst($gender) : null,
            'dob' => $dob?->format('j M Y'),
            'parent' => collect([$values['guardian_name'] ?? null, $parentPhone])->filter()->implode(' · ') ?: null,
            'parent_email' => $parentEmail,
            'errors' => $errors,
            'data' => [
                'first_name' => $firstName,
                'middle_name' => $values['middle_name'] ?? null,
                'last_name' => $lastName,
                'gender' => $gender,
                'date_of_birth' => $dob?->toDateString(),
                'nationality' => $values['nationality'] ?? null,
                'address' => $values['address'] ?? null,
                'city' => $values['city'] ?? null,
                'state' => $values['state'] ?? null,
                'lga' => $values['lga'] ?? null,
                'level_applied_for_id' => $level?->id,
                'guardian_name' => $values['guardian_name'] ?? null,
                'guardian_relationship' => $values['guardian_relationship'] ?? null,
                'guardian_phone' => $parentPhone,
                'guardian_email' => $parentEmail,
                // No applicant phone, email or previous school: the office form
                // does not collect them, and the parent is the account holder.
            ],
        ];
    }

    /** A one-line reason the address will not do, or null when it is fine. */
    private function emailProblem(string $email): ?string
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false
            ? "Parent email \"{$email}\" does not look like an email address."
            : null;
    }

    /* ------------------------------------------------------------------ */
    /* Small helpers */
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
                    Date::excelToDateTimeObject((float) $value),
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
}
