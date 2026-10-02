<?php

namespace App\Services\Teachers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\PhoneNumber;
use App\Support\Spreadsheet\SheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Taking on a staff of teachers at once, from a spreadsheet.
 *
 * Follows the same rule as every other import in this platform: **nothing is
 * created until a human has looked at the parsed rows.** The office uploads the
 * file, we show exactly which accounts would be opened and flag every problem with
 * its row number, and only then does anything get written. The spreadsheet is not
 * kept — the parsed rows ride in the session between the preview and the commit.
 *
 * A teacher here is what a teacher is everywhere else in this module: an account
 * with the Teacher role. So this creates logins, and a login needs a password: the
 * phone and email come from the sheet, and every account is given a password of its
 * own. Those plain passwords are handed back to the office to write down and hand
 * over, and are never stored — a staff sharing one password is one password too
 * many. Each account is forced to change it the first time it is signed in with, so
 * the handwritten one does not survive first contact either.
 */
class TeacherImportService
{
    /**
     * A school's staff is not five hundred people, and a file that size is a
     * mistake rather than a long list. Beyond this, split the file.
     */
    public const MAX_ROWS = 200;

    /** Where a file waits, under its real name, for the length of the read. */
    private const READ_FOLDER = 'imports/teachers';

    /**
     * Logical column => the spellings an office clerk might actually type.
     * Matching is case- and punctuation-insensitive.
     *
     * `surname`, `first_name` and `middle_name` are columns of their own to keep the
     * reader's "contains" fallback honest: "Surname" ends in "name", so without a
     * column to land on it would be read as the teacher's whole name — and the given
     * name beside it would have nowhere to go.
     *
     * Nothing here is a word so common that it swallows another heading: "contact"
     * and "mail" are left out on purpose, so a sheet with a "Contact address" or a
     * "Mailing address" does not have that column read as a phone number or an email.
     */
    private const COLUMN_ALIASES = [
        'name' => ['name', 'full name', 'fullname', 'names', 'teacher name', 'teacher names', 'staff name', 'teachers name'],
        'surname' => ['surname', 'last name', 'lastname', 'family name'],
        'first_name' => ['first name', 'firstname', 'given name', 'given names', 'other names'],
        'middle_name' => ['middle name', 'middlename'],
        'phone' => ['phone', 'phone number', 'phone no', 'telephone', 'mobile', 'mobile number', 'mobile no', 'gsm', 'gsm number'],
        'email' => ['email', 'email address', 'e mail', 'electronic mail', 'email id'],
    ];

    /** The columns the sheet cannot do without, and why. */
    private const COLUMN_NOTES = [
        'surname' => 'Only if the sheet keeps the surname and the given name apart.',
        'first_name' => 'Used with the surname to make the full name.',
        'middle_name' => 'Optional, and added to the name when present.',
        'email' => 'Optional. Read and checked when the sheet has one; the phone number is what they sign in with.',
    ];

    public function __construct(
        private readonly SheetReader $sheets,
        private readonly TeacherRegisterService $register,
    ) {}

    /**
     * The canonical template the office should fill in.
     *
     * @return array<int,array{key:string,label:string,required:bool,note:string}>
     */
    public function columns(): array
    {
        return [
            ['key' => 'name', 'label' => 'Name', 'required' => true, 'note' => 'The teacher’s full name, as the school says it.'],
            ['key' => 'phone', 'label' => 'Phone', 'required' => true, 'note' => 'Required: it is the number they sign in with, and how the school texts them.'],
        ];
    }

    /**
     * Every column the reader understands, required and optional together.
     *
     * The optional ones are not printed in the template, but a sheet that keeps the
     * surname and the given name in separate columns is read rather than ignored,
     * because that is how most of them are kept.
     *
     * @return array<int,array{key:string,label:string,required:bool,note:string}>
     */
    public function allColumns(): array
    {
        $columns = $this->columns();

        foreach (['surname' => 'Surname', 'first_name' => 'First name', 'middle_name' => 'Middle name', 'email' => 'Email'] as $key => $label) {
            $columns[] = [
                'key' => $key,
                'label' => $label,
                'required' => false,
                'note' => self::COLUMN_NOTES[$key],
            ];
        }

        return $columns;
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
     *     truncated: bool,
     *     summary: array<string,int>
     * }
     */
    public function parse(UploadedFile $file): array
    {
        $grid = $this->sheets->read($file, self::READ_FOLDER);

        // One matched column is enough to call it a header row here, so that a sheet
        // that is only missing its email column is told which column is missing
        // rather than told its headings cannot be found at all.
        [$headerIndex, $map] = $this->sheets->locateHeader($grid, self::COLUMN_ALIASES, 1);

        if ($headerIndex === null) {
            throw new RuntimeException(
                'Could not find the column headings. The sheet needs “Name” and “Phone” '
                .'across the top — download the template to see the layout.',
            );
        }

        if (($missing = $this->missingColumns($map)) !== []) {
            throw new RuntimeException(sprintf(
                'The sheet has no %s column. It needs “Name” and “Phone” across the top — download the template to see the layout.',
                $this->and($missing),
            ));
        }

        $rows = [];
        $summary = ['total' => 0, 'ok' => 0, 'errors' => 0];
        $truncated = false;

        // Row number per email already read, so a file that lists somebody twice is
        // caught here rather than as a database error at the end.
        $seen = [];

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

            $rows[] = $this->prepareRow($values, $index + 1, $seen);
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
        ];
    }

    /**
     * Create the chosen rows, each as an account with the Teacher role.
     *
     * Each row is independent: one failure never abandons the rest of a file the
     * office has already checked. Each account is given a password of its own, and
     * the plain ones are handed back for the office to write down — they are never
     * stored, so what is returned here is the only place they exist.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,int>  $lines  which rows to add
     * @return array{created:int,names:array<int,string>,credentials:array<int,array{name:string,phone:string,password:string}>,failed:array<int,array{line:int,reason:string}>}
     */
    public function commit(array $rows, array $lines, ?User $actor = null): array
    {
        // A form posts the ticked rows as strings, while the parsed rows carry them as
        // numbers — and the match below is strict. Left as they arrive, nothing matches,
        // every row is skipped, and the office is told "No teacher was added" with no
        // reason beside it. So the two sides are put in the same shape first.
        $lines = array_map('intval', $lines);

        $created = 0;
        $names = [];
        $credentials = [];
        $failed = [];

        foreach ($rows as $row) {
            if (! in_array($row['line'], $lines, true)) {
                continue;
            }

            if ($row['errors'] !== []) {
                $failed[] = ['line' => $row['line'], 'reason' => implode(' ', $row['errors'])];

                continue;
            }

            $phone = (string) $row['data']['phone'];
            $email = $row['data']['email'] ?: null;

            // Checked again rather than trusted: the preview may be minutes old, and
            // an account opened in between is the one case a unique index would
            // otherwise report as a database error.
            if (User::query()->where('phone', $phone)->exists()
                || ($email !== null && User::query()->where('email', $email)->exists())) {
                $failed[] = ['line' => $row['line'], 'reason' => "{$phone} already has an account."];

                continue;
            }

            // One password per teacher, made here rather than typed on a screen: a
            // sheet of paper carrying one password for a whole staff is a sheet that
            // opens every account on it.
            $password = $this->register->newPassword();

            try {
                $teacher = DB::transaction(function () use ($row, $password, $email, $phone) {
                    $teacher = User::create([
                        'name' => $row['data']['name'],
                        'email' => $email,
                        'phone' => $phone,
                        'password' => Hash::make($password),
                        'is_active' => true,
                    ]);

                    $teacher->assignRole('Teacher');

                    return $teacher;
                });

                $names[] = $teacher->name;
                $credentials[] = [
                    'name' => $teacher->name,
                    'phone' => $phone,
                    'password' => $password,
                ];
                $created++;
            } catch (\Throwable $e) {
                $failed[] = ['line' => $row['line'], 'reason' => $e->getMessage()];
            }
        }

        if ($created > 0) {
            ActivityLog::record(
                'teachers.imported',
                null,
                "Added {$created} teacher(s) in bulk".($actor ? " by {$actor->name}" : ''),
                ['module' => 'teachers', 'count' => $created],
            );
        }

        return [
            'created' => $created,
            'names' => $names,
            'credentials' => $credentials,
            'failed' => $failed,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Validating one row */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string,string|null>  $values
     * @param  array<string,int>  $seen  phones and emails already on an earlier row of this file
     * @return array<string,mixed>
     */
    private function prepareRow(array $values, int $line, array &$seen): array
    {
        $errors = [];

        $name = $values['name'] ?? $this->composeName($values);
        $phone = PhoneNumber::normalize($values['phone'] ?? null);

        // A blank cell and an absent column both mean the same thing here.
        $email = $values['email'] ?? null;
        $email = ($email === null || trim($email) === '') ? null : trim($email);

        if ($name === null) {
            $errors[] = 'The name is missing.';
        }

        // The phone number is the login, so it is the column that cannot be left out,
        // and the one that belongs to exactly one account.
        if ($phone === null) {
            $errors[] = 'The phone number is missing.';
        } else {
            $key = 'phone:'.$phone;

            if (isset($seen[$key])) {
                $errors[] = "{$phone} is also on row {$seen[$key]} of this file.";
            } else {
                $seen[$key] = $line;
            }

            if (User::query()->where('phone', $phone)->exists()) {
                $errors[] = "{$phone} already has an account.";
            }
        }

        // An email is optional now. When the sheet does carry one it still has to be
        // an address, and it still belongs to one teacher.
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "“{$email}” is not an email address.";

            $email = null;
        } elseif ($email !== null) {
            $key = 'email:'.strtolower($email);

            if (isset($seen[$key])) {
                $errors[] = "{$email} is also on row {$seen[$key]} of this file.";
            } else {
                $seen[$key] = $line;
            }

            if (User::query()->where('email', $email)->exists()) {
                $errors[] = "{$email} already has an account.";
            }
        }

        return [
            'line' => $line,
            'data' => ['name' => $name, 'phone' => $phone, 'email' => $email],
            'errors' => $errors,
        ];
    }

    /**
     * The full name, out of a single column or out of the three that a sheet often
     * keeps instead of one.
     *
     * @param  array<string,string|null>  $values
     */
    private function composeName(array $values): ?string
    {
        $parts = array_values(array_filter([
            $values['first_name'] ?? null,
            $values['middle_name'] ?? null,
            $values['surname'] ?? null,
        ]));

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * Which of the columns the sheet cannot do without it has not got.
     *
     * A name can arrive as one column or as a surname and a given name, so it is
     * only missing when none of the three is there.
     *
     * @param  array<string,int>  $map
     * @return array<int,string>
     */
    private function missingColumns(array $map): array
    {
        $missing = [];

        if (! isset($map['name']) && ! isset($map['surname']) && ! isset($map['first_name'])) {
            $missing[] = 'Name';
        }

        if (! isset($map['phone'])) {
            $missing[] = 'Phone';
        }

        return $missing;
    }

    /** "Phone", "Phone and Email", or "Name, Phone and Email". */
    private function and(array $labels): string
    {
        if (count($labels) === 1) {
            return $labels[0];
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }
}
