<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\Teachers\TeacherImportService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Taking on a staff of teachers from a spreadsheet.
 *
 * The rule this file is really about is the platform's: nothing is created until a
 * human has looked at the parsed rows. So what is checked here is that the preview
 * holds everything back, that it says which row is wrong and why, and that the
 * commit makes the accounts the office ticked — and only those.
 *
 * A teacher is an account with the Teacher role, so the email is the login: one per
 * teacher, and never twice, whether the clash is with somebody already on the staff
 * or with another row of the same file.
 */
class TeacherImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    /* ------------------------------------------------------------------ */
    /* The page */
    /* ------------------------------------------------------------------ */

    public function test_the_bulk_upload_page_shows_what_it_reads(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.import'))
            ->assertOk()
            ->assertSee('Choose the spreadsheet')
            ->assertSee('Name')
            ->assertSee('Phone')
            ->assertSee('Email')
            ->assertSee('Download the template');
    }

    public function test_the_template_downloads_with_the_headings_the_reader_looks_for(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.students-results.teachers.import.template'))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="teacher-import-template.csv"');

        $csv = (string) $response->getContent();

        // The byte-order mark is there for Excel; it is not part of the first heading.
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? ''))));

        $this->assertSame(['Name', 'Phone', 'Email'], $rows[0]);
        $this->assertSame(['Chidera Okafor', '08031234567', 'chidera@example.com'], $rows[1]);
    }

    public function test_bulk_upload_is_closed_to_a_role_without_the_permission(): void
    {
        $bursar = User::factory()->create();
        $bursar->assignRole('Bursar / Accounts');

        foreach (['teachers.import', 'teachers.import.template'] as $name) {
            $this->actingAs($bursar)
                ->get(route('admin.students-results.'.$name))
                ->assertForbidden();
        }

        $this->actingAs($bursar)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv("Name,Phone,Email\nChidera Okafor,08031234567,chidera@example.com\n"),
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Reading the sheet */
    /* ------------------------------------------------------------------ */

    /** The whole point of the preview: nothing exists, and nothing is written yet. */
    public function test_a_spreadsheet_is_reviewed_before_any_account_is_opened(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv(
                    "Name,Phone,Email\n"
                    ."Chidera Okafor,08031234567,chidera@example.com\n"
                    ."Ngozi Eze,08031234568,ngozi@example.com\n",
                ),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $this->assertSame(0, User::query()->count() - 1, 'the office account is the only one there should be');

        $staged = session('teacher_import');

        $this->assertNotNull($staged);
        $this->assertSame(2, $staged['summary']['total']);
        $this->assertSame(2, $staged['summary']['ok']);
        $this->assertSame(0, $staged['summary']['errors']);
        $this->assertSame('Chidera Okafor', $staged['rows'][0]['data']['name']);
    }

    /**
     * Headings are matched loosely and the order does not matter, because no two
     * schools keep the same sheet.
     */
    public function test_the_headings_are_read_whatever_they_are_called_and_in_whatever_order(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv(
                    "Email Address,Mobile,Teacher Name\n"
                    ."chidera@example.com,08031234567,Chidera Okafor\n",
                ),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $row = session('teacher_import')['rows'][0];

        $this->assertSame([], $row['errors']);
        $this->assertSame('Chidera Okafor', $row['data']['name']);
        $this->assertSame('08031234567', $row['data']['phone']);
        $this->assertSame('chidera@example.com', $row['data']['email']);
    }

    /** Most of these sheets keep the names apart, so they are joined rather than refused. */
    public function test_a_sheet_that_keeps_the_names_apart_has_them_joined(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv(
                    "Surname,First name,Middle name,Phone,Email\n"
                    ."Okafor,Chidera,Ngozi,08031234567,chidera@example.com\n",
                ),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $row = session('teacher_import')['rows'][0];

        $this->assertSame([], $row['errors']);
        $this->assertSame('Chidera Ngozi Okafor', $row['data']['name']);
    }

    /**
     * A title row above the headings is what a spreadsheet from an office looks
     * like — and it is the line with the most spaces in the file, which is why the
     * separator is worked out from the whole sheet rather than guessed from the
     * first line.
     */
    public function test_a_title_row_above_the_headings_is_tolerated(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv(
                    "Teaching staff 2026/2027\n"
                    ."\n"
                    ."Name,Phone,Email\n"
                    ."Chidera Okafor,08031234567,chidera@example.com\n",
                ),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $staged = session('teacher_import');

        $this->assertSame(1, $staged['summary']['total']);
        $this->assertSame(1, $staged['summary']['ok']);
        $this->assertSame('Chidera Okafor', $staged['rows'][0]['data']['name']);
        $this->assertSame('chidera@example.com', $staged['rows'][0]['data']['email']);
    }

    /** Excel writes a semicolon in some countries, and it has to be read as one. */
    public function test_a_semicolon_separated_sheet_is_read(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv(
                    "Name;Phone;Email\n"
                    ."Chidera Okafor;08031234567;chidera@example.com\n",
                ),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $row = session('teacher_import')['rows'][0];

        $this->assertSame([], $row['errors']);
        $this->assertSame('Chidera Okafor', $row['data']['name']);
        $this->assertSame('chidera@example.com', $row['data']['email']);
    }

    /** A gap between the rows is a gap, not a teacher with nothing on them. */
    public function test_blank_rows_are_passed_over_rather_than_reported(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv(
                    "Name,Phone,Email\n"
                    ."\n"
                    ."Chidera Okafor,08031234567,chidera@example.com\n"
                    .",,\n"
                    ."Ngozi Eze,08031234568,ngozi@example.com\n",
                ),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $staged = session('teacher_import');

        $this->assertSame(2, $staged['summary']['total']);
        $this->assertSame(0, $staged['summary']['errors']);
    }

    public function test_a_sheet_without_a_column_it_needs_is_refused_by_name(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv("Name,Phone\nChidera Okafor,08031234567\n"),
            ])
            ->assertSessionHasErrors([
                'file' => 'The sheet has no Email column. It needs “Name”, “Phone” and “Email” across the top — download the template to see the layout.',
            ]);

        $this->assertNull(session('teacher_import'));
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => UploadedFile::fake()->create('staff.pdf', 40),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_a_file_with_more_rows_than_it_will_take_is_cut_short_and_says_so(): void
    {
        $rows = "Name,Phone,Email\n";

        for ($i = 1; $i <= TeacherImportService::MAX_ROWS + 1; $i++) {
            $rows .= "Teacher {$i},0803123{$i},teacher{$i}@example.com\n";
        }

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), ['file' => $this->csv($rows)])
            ->assertSessionHas('status');

        $staged = session('teacher_import');

        $this->assertTrue($staged['truncated']);
        $this->assertCount(TeacherImportService::MAX_ROWS, $staged['rows']);
        $this->assertStringContainsString(
            'Only the first '.TeacherImportService::MAX_ROWS.' rows were read',
            session('status'),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Rows that cannot be added */
    /* ------------------------------------------------------------------ */

    public function test_a_row_with_no_phone_number_is_flagged(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv("Name,Phone,Email\nChidera Okafor,,chidera@example.com\n"),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $this->assertSame(['The phone number is missing.'], session('teacher_import')['rows'][0]['errors']);
        $this->assertSame(1, session('teacher_import')['summary']['errors']);
    }

    /** The email is the login, so a row that has one already cannot be added. */
    public function test_an_email_that_already_has_an_account_is_flagged(): void
    {
        User::factory()->create(['email' => 'chidera@example.com']);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv("Name,Phone,Email\nChidera Okafor,08031234567,chidera@example.com\n"),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $this->assertSame(
            ['chidera@example.com already has an account.'],
            session('teacher_import')['rows'][0]['errors'],
        );
    }

    /** And the same address twice in one file is one login for two people. */
    public function test_the_same_email_on_two_rows_of_one_file_is_flagged(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv(
                    "Name,Phone,Email\n"
                    ."Chidera Okafor,08031234567,chidera@example.com\n"
                    ."Ngozi Eze,08031234568,Chidera@example.com\n",
                ),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $rows = session('teacher_import')['rows'];

        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame(['Chidera@example.com is also on row 2 of this file.'], $rows[1]['errors']);
    }

    public function test_something_that_is_not_an_email_address_is_flagged(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv("Name,Phone,Email\nChidera Okafor,08031234567,not an address\n"),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $this->assertSame(
            ['“not an address” is not an email address.'],
            session('teacher_import')['rows'][0]['errors'],
        );
    }

    public function test_a_row_with_no_name_is_flagged(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), [
                'file' => $this->csv("Name,Phone,Email\n,08031234567,chidera@example.com\n"),
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'));

        $this->assertSame(['The name is missing.'], session('teacher_import')['rows'][0]['errors']);
    }

    /* ------------------------------------------------------------------ */
    /* Adding them */
    /* ------------------------------------------------------------------ */

    public function test_adding_creates_the_accounts_that_were_ticked(): void
    {
        $this->stage(
            "Name,Phone,Email\n"
            ."Chidera Okafor,08031234567,chidera@example.com\n"
            ."Ngozi Eze,08031234568,ngozi@example.com\n"
            ."Emeka Eze,08031234569,emeka@example.com\n",
        );

        $lines = collect(session('teacher_import')['rows'])->pluck('line')->all();

        // Untick the middle teacher.
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.commit'), [
                'lines' => [$lines[0], $lines[2]],
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('admin.students-results.teachers.list'));

        $teachers = User::query()->role('Teacher')->orderBy('id')->get();

        $this->assertSame(['Chidera Okafor', 'Emeka Eze'], $teachers->pluck('name')->all());
        $this->assertSame(['08031234567', '08031234569'], $teachers->pluck('phone')->all());
        $this->assertSame('chidera@example.com', $teachers->first()->email);

        foreach ($teachers as $teacher) {
            $this->assertSame(['Teacher'], $teacher->getRoleNames()->all());
            $this->assertTrue($teacher->is_active);
            // The password is the office's to give out, and it is changed on first use.
            $this->assertTrue($teacher->must_change_password);
            $this->assertTrue(password_verify('password', $teacher->password));
        }

        // And the staged upload is cleared so it cannot be submitted twice.
        $this->assertNull(session('teacher_import'));
    }

    public function test_a_row_that_needs_fixing_is_skipped_rather_than_stopping_the_rest(): void
    {
        $this->stage(
            "Name,Phone,Email\n"
            ."Chidera Okafor,08031234567,chidera@example.com\n"
            ."No Phone,,nophone@example.com\n",
        );

        $rows = session('teacher_import')['rows'];

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.commit'), [
                'lines' => collect($rows)->pluck('line')->all(),
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('admin.students-results.teachers.list'))
            ->assertSessionHas('status', '1 teacher(s) added as teachers. 1 row(s) could not be added and were skipped. They change the password the first time they sign in.');

        $this->assertSame(['Chidera Okafor'], User::query()->role('Teacher')->pluck('name')->all());
    }

    /** The password is what every one of them signs in with, so it has to be the one meant. */
    public function test_the_password_is_required_and_has_to_be_confirmed(): void
    {
        $this->stage("Name,Phone,Email\nChidera Okafor,08031234567,chidera@example.com\n");

        $line = session('teacher_import')['rows'][0]['line'];

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.commit'), ['lines' => [$line]])
            ->assertSessionHasErrors('password');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.commit'), [
                'lines' => [$line],
                'password' => 'password',
                'password_confirmation' => 'something else',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, User::query()->role('Teacher')->count());
    }

    public function test_at_least_one_teacher_has_to_be_ticked(): void
    {
        $this->stage("Name,Phone,Email\nChidera Okafor,08031234567,chidera@example.com\n");

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.commit'), [
                'lines' => [],
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, User::query()->role('Teacher')->count());
    }

    public function test_an_upload_that_has_expired_says_so_rather_than_adding_nothing(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.commit'), [
                'lines' => [2],
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('admin.students-results.teachers.import'))
            ->assertSessionHas('error', 'That upload has expired. Please choose the file again.');
    }

    /** Adding them twice is the failure the email column exists to prevent. */
    public function test_a_teacher_cannot_be_added_twice_from_two_uploads(): void
    {
        $this->stage("Name,Phone,Email\nChidera Okafor,08031234567,chidera@example.com\n");

        $line = session('teacher_import')['rows'][0]['line'];

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.commit'), [
                'lines' => [$line],
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHas('status');

        $this->stage("Name,Phone,Email\nChidera Okafor,08031234567,chidera@example.com\n");

        $this->assertSame(
            ['chidera@example.com already has an account.'],
            session('teacher_import')['rows'][0]['errors'],
        );

        $this->assertSame(1, User::query()->role('Teacher')->count());
    }

    /* ------------------------------------------------------------------ */

    /** Upload a sheet and leave it staged, as the office's first step does. */
    private function stage(string $contents): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.teachers.import.preview'), ['file' => $this->csv($contents)])
            ->assertRedirect(route('admin.students-results.teachers.import'));
    }

    private function csv(string $contents, string $name = 'teachers.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
