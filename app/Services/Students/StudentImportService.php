<?php

namespace App\Services\Students;

use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Student;
use App\Services\Admissions\StudentEnrolmentService;
use App\Support\Spreadsheet\SheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Taking a class of children onto the roll at once, from a spreadsheet.
 *
 * Follows the same rule as every other import in this platform: **nothing is created
 * until a human has looked at the parsed rows.** The office chooses the class, uploads
 * the file, we show exactly which children would be added and flag everything that needs
 * fixing with the line it is on, and only then does anything get written. The
 * spreadsheet itself is not kept — the parsed rows ride in the session between the
 * preview and the commit.
 *
 * The class and the section come from the form rather than the sheet. That is what the
 * office is doing here: JSS1A's list has arrived, and the whole file is that class. A
 * class column in the sheet would be a second, quieter way of saying the same thing, and
 * the two would disagree the first time somebody filled one in wrongly.
 *
 * Nothing is guessed. A row whose name is missing, or whose gender or date of birth
 * cannot be read, is reported and cannot be ticked; a child who appears to be on the roll
 * already is *shown* as such and left ticked, because the office can see the class list
 * and this cannot, and two children in one class may honestly share a name.
 */
class StudentImportService
{
    /**
     * A year group's list is a few hundred names. Past this the file is a mistake
     * rather than a long list, and the school should split it.
     */
    public const MAX_ROWS = 500;

    /** Where a file waits, under its real name, for the length of the read. */
    private const READ_FOLDER = 'imports/students';

    /**
     * Logical column => the spellings an office clerk might actually type.
     * Matching is case- and punctuation-insensitive.
     *
     * The keys are the `students` columns themselves, so what a row becomes is visible
     * at a glance. The only one that does not read straight across is `last_name`, which
     * is what the school calls the surname.
     *
     * The child's own email is deliberately *not* an alias of the guardian's: the reader
     * matches the longest alias first, so a sheet with both a "Guardian Email" and an
     * "Email" column lands each one where it belongs rather than on whichever came first.
     */
    private const COLUMN_ALIASES = [
        'last_name' => ['surname', 'last name', 'lastname', 'family name', 'surname of pupil', 'surname of student'],
        'first_name' => ['first name', 'firstname', 'given name', 'given names', 'other names', 'name of pupil', 'name of student'],
        'middle_name' => ['middle name', 'middlename'],
        'gender' => ['gender'],
        'date_of_birth' => ['date of birth', 'date of birth dd mm yyyy', 'birth date', 'birthday', 'date born', 'dob'],
        'guardian_name' => ['guardian name', 'parent name', 'parents name', 'father name', 'mother name', 'guardian', 'parent'],
        'guardian_phone' => ['guardian phone', 'guardian phone number', 'guardian number', 'parent phone', 'parent phone number', 'parent number', 'guardians phone', 'phone number of parent'],
        'guardian_email' => ['guardian email', 'guardian email address', 'parent email', 'parent email address', 'guardians email'],
        'email' => ['email', 'email address', 'e mail', 'electronic mail'],
        'address' => ['address', 'home address', 'residential address', 'contact address', 'house address'],
    ];

    /** The columns no sheet can do without, and why. */
    private const REQUIRED = [
        'last_name' => 'Surname',
        'first_name' => 'First name',
    ];

    /** What each optional column is called on the guide, and what it is for. */
    private const OPTIONAL = [
        'middle_name' => ['Middle name', 'Optional. Added to the name when the sheet has one.'],
        'gender' => ['Gender', 'Optional. Write M or F, Male or Female.'],
        'date_of_birth' => ['Date of birth', 'Optional. Dates are read day first, so 03/12/2013 is 3 December.'],
        'guardian_name' => ['Guardian name', 'Optional. The parent or guardian the school would ring.'],
        'guardian_phone' => ['Guardian phone', 'Optional, and worth having: it is the number the school texts about fees and results.'],
        'guardian_email' => ['Guardian email', 'Optional.'],
        'email' => ['Email', 'Optional. The child’s own address. A portal login is made either way.'],
        'address' => ['Address', 'Optional.'],
    ];

    public function __construct(
        private readonly SheetReader $sheets,
        private readonly StudentEnrolmentService $enrolment,
    ) {}

    /**
     * The columns the sheet cannot do without. These are what the sample file carries.
     *
     * @return array<int,array{key:string,label:string,required:bool,note:string}>
     */
    public function columns(): array
    {
        $columns = [];

        foreach (self::REQUIRED as $key => $label) {
            $columns[] = [
                'key' => $key,
                'label' => $label,
                'required' => true,
                'note' => $key === 'last_name'
                    ? 'The child’s surname — what the school calls the last name.'
                    : 'The child’s first name, as the school says it.',
            ];
        }

        return $columns;
    }

    /**
     * Every column the reader understands, required first.
     *
     * The optional ones are not printed in the sample file, but a sheet that carries
     * them is read rather than ignored, because most of them will.
     *
     * @return array<int,array{key:string,label:string,required:bool,note:string}>
     */
    public function allColumns(): array
    {
        $columns = $this->columns();

        foreach (self::OPTIONAL as $key => [$label, $note]) {
            $columns[] = [
                'key' => $key,
                'label' => $label,
                'required' => false,
                'note' => $note,
            ];
        }

        return $columns;
    }

    /**
     * The header row the downloaded sample file uses.
     *
     * Every column, not only the required ones. An office filling in a class list has
     * the guardian's number and the child's date of birth to hand, and a sample that
     * carried two headings would have them typing the other eight out of the guide on
     * the page. A column left blank is nothing, and a column deleted from the sheet is
     * read as one the school does not keep.
     */
    public function templateHeaders(): array
    {
        return array_column($this->allColumns(), 'label');
    }

    /**
     * Read the sheet and describe every child in it. Nothing is written.
     *
     * @return array{rows:array<int,array<string,mixed>>,summary:array{total:int,ok:int,errors:int},truncated:bool,unread:array<int,string>,filename:string}
     */
    public function parse(UploadedFile $file, ?SchoolClass $class): array
    {
        $grid = $this->sheets->read($file, self::READ_FOLDER);

        [$headerIndex, $map] = $this->sheets->locateHeader($grid, self::COLUMN_ALIASES, 1);

        if ($headerIndex === null) {
            throw new RuntimeException(
                'No row of column headings was found. The first line of the sheet should name the columns — '
                .'at least a Surname and a First name. Download the sample file below and keep its headings.',
            );
        }

        foreach (self::REQUIRED as $key => $label) {
            if (! isset($map[$key])) {
                throw new RuntimeException(sprintf(
                    'The sheet has no "%s" column. Download the sample file below and keep its headings.',
                    $label,
                ));
            }
        }

        $rows = [];
        $seen = [];

        foreach (array_slice($grid, $headerIndex + 1, null, true) as $index => $line) {
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }

            $values = $this->sheets->row($line, $map);

            // A gap in the sheet is a gap, not a child with a missing name.
            if ($this->sheets->isBlank($values)) {
                continue;
            }

            $rows[] = $this->read($values, (int) $index + 1, $class, $seen);
        }

        $ok = collect($rows)->where('errors', [])->count();

        return [
            'rows' => $rows,
            'summary' => [
                'total' => count($rows),
                'ok' => $ok,
                'errors' => count($rows) - $ok,
            ],
            'truncated' => count($rows) >= self::MAX_ROWS,
            'unread' => $this->sheets->unreadColumns($grid[$headerIndex], $map),
            'filename' => $file->getClientOriginalName(),
        ];
    }

    /**
     * One row of the sheet, as a child or as the reasons they cannot be one yet.
     *
     * @param  array<string,string|null>  $values
     * @param  array<string,string>  $seen  Names already met in this file, by key.
     * @return array<string,mixed>
     */
    private function read(array $values, int $line, ?SchoolClass $class, array &$seen): array
    {
        $errors = [];
        $notes = [];

        $lastName = $values['last_name'] ?? null;
        $firstName = $values['first_name'] ?? null;

        if ($lastName === null) {
            $errors[] = 'No surname.';
        }

        if ($firstName === null) {
            $errors[] = 'No first name.';
        }

        $gender = null;

        if (($raw = $values['gender'] ?? null) !== null) {
            $gender = match (Str::lower($raw)) {
                'm', 'male', 'boy' => 'male',
                'f', 'female', 'girl' => 'female',
                default => null,
            };

            if ($gender === null) {
                $errors[] = "“{$raw}” is not a gender — write M or F.";
            }
        }

        $dateOfBirth = null;

        if (($raw = $values['date_of_birth'] ?? null) !== null) {
            $dateOfBirth = $this->dateOfBirth($raw);

            if ($dateOfBirth === null) {
                $errors[] = "“{$raw}” is not a date — write it as 12/03/2013.";
            }
        }

        $name = trim(($lastName ?? '').' '.($firstName ?? ''));

        if ($errors === [] && $lastName !== null && $firstName !== null) {
            $key = Str::lower($lastName.'|'.$firstName);

            if (isset($seen[$key])) {
                $notes[] = 'This name is on an earlier line of this same file.';
            }

            $seen[$key] = $line;

            if ($onTheRoll = $this->alreadyOnTheRoll($lastName, $firstName, $dateOfBirth, $class)) {
                $notes[] = "Somebody of this name is already in {$class?->name} as {$onTheRoll}.";
            }
        }

        return [
            'line' => $line,
            'name' => $name === '' ? '(no name)' : $name,
            'errors' => $errors,
            'notes' => $notes,
            'values' => [
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $values['middle_name'] ?? null,
                'gender' => $gender,
                'date_of_birth' => $dateOfBirth,
                'guardian_name' => $values['guardian_name'] ?? null,
                'guardian_phone' => $values['guardian_phone'] ?? null,
                'guardian_email' => $values['guardian_email'] ?? null,
                'email' => $values['email'] ?? null,
                'address' => $values['address'] ?? null,
            ],
        ];
    }

    /**
     * A date read day first, and only in the formats the office actually writes.
     *
     * `strtotime` is not used on purpose: it reads 03/12/2013 as 12 March and
     * 12/03/2013 as 12 March too, so a sheet written one way round would have half its
     * birthdays moved by nine months and nobody would ever see it. The formats below
     * are tried in order and the day always comes first.
     */
    private function dateOfBirth(string $raw): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'd M Y', 'j F Y', 'd F Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $raw);
            $problems = \DateTimeImmutable::getLastErrors();

            if ($date === false) {
                continue;
            }

            if (is_array($problems) && ($problems['error_count'] > 0 || $problems['warning_count'] > 0)) {
                continue;
            }

            return $date->format('Y-m-d');
        }

        return null;
    }

    /**
     * The number of a child who looks like this one and is already in this class.
     *
     * Matched on the name, and on the date of birth as well when the sheet gave one, so
     * that a genuine namesake is not reported as a duplicate of a child born on another
     * day. Returns null when there is nobody.
     *
     * The same children can be in this school twice legitimately — a repeated year, a
     * transfer back — which is why this only ever warns.
     */
    private function alreadyOnTheRoll(
        string $lastName,
        string $firstName,
        ?string $dateOfBirth,
        ?SchoolClass $class,
    ): ?string {
        $existing = Student::query()
            ->when(
                $class !== null,
                fn ($query) => $query->where('school_class_id', $class->id),
                fn ($query) => $query->whereNull('school_class_id'),
            )
            ->where('last_name', $lastName)
            ->where('first_name', $firstName)
            ->when($dateOfBirth !== null, fn ($query) => $query->whereDate('date_of_birth', $dateOfBirth))
            ->first();

        return $existing?->student_number;
    }

    /**
     * Add the rows the office left ticked.
     *
     * One at a time and inside its own transaction, so a row that fails partway — a
     * number that collides, an account that cannot be made — leaves the children before
     * it on the roll and does not take them down with it. What failed comes back with
     * its line and its reason, and the office is told rather than left counting.
     *
     * @param  array{rows:array<int,array<string,mixed>>}  $staged
     * @param  array<int,int|string>  $lines
     * @return array{created:int,failed:array<int,array{line:int,name:string,reason:string}>}
     */
    public function commit(
        array $staged,
        array $lines,
        SchoolLevel $level,
        ?SchoolClass $class,
        AcademicSession $session,
    ): array {
        $wanted = array_flip(array_map('intval', $lines));

        $created = [];
        $failed = [];

        foreach ($staged['rows'] as $row) {
            if (! isset($wanted[$row['line']])) {
                continue;
            }

            if ($row['errors'] !== []) {
                $failed[] = ['line' => $row['line'], 'name' => $row['name'], 'reason' => implode(' ', $row['errors'])];

                continue;
            }

            try {
                $created[] = $this->enrolment->enrolFromSheet($row['values'], $level, $class, $session);
            } catch (\Throwable $e) {
                $failed[] = ['line' => $row['line'], 'name' => $row['name'], 'reason' => $e->getMessage()];
            }
        }

        return [
            'created' => count($created),
            'failed' => $failed,
            'students' => $created,
        ];
    }
}
