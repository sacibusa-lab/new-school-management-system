<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Taking a class of children onto the roll from a spreadsheet.
 *
 * The rule this file is really about is the platform's: nothing is created until a human
 * has looked at the parsed rows. So what is checked here is that the preview holds
 * everything back, that it says which line is wrong and why, and that the commit adds the
 * children the office ticked — and only those.
 *
 * The other half is what an imported child is given. They come in by spreadsheet rather
 * than by application, but they are children of the school either the same way: a number
 * off the same sequence, and a portal login, because a name on a register that cannot see
 * its own results is a record the school has to fix later. What they are *not* given is a
 * bill — nothing here decides what a family is charged.
 */
class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private SchoolLevel $jss1;

    private Section $a;

    private Section $b;

    private Section $c;

    private SchoolClass $jss1a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'is_current' => true,
        ]);

        $this->jss1 = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);

        $this->a = Section::create(['name' => 'A', 'order' => 1]);
        $this->b = Section::create(['name' => 'B', 'order' => 2]);
        // A section the school has defined but has not made a class of yet.
        $this->c = Section::create(['name' => 'C', 'order' => 3]);

        $this->jss1a = $this->classOf($this->a);
    }

    /* ------------------------------------------------------------------ */
    /* The page */
    /* ------------------------------------------------------------------ */

    public function test_the_page_asks_for_a_class_a_section_and_a_file(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students.multiple-import'))
            ->assertOk()
            ->assertSee('Choose a class')
            ->assertSee('Select Class First')
            ->assertSee('Drag and drop a file here or click')
            ->assertSee('Download Sample Import File')
            // The two columns a row cannot do without, both marked required.
            ->assertSee('Surname')
            ->assertSee('First name');
    }

    public function test_the_sample_file_carries_every_heading_the_reader_looks_for(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.students-results.students.multiple-import.template'))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="student-import-sample.csv"');

        $csv = (string) $response->getContent();

        // The byte-order mark is there for Excel; it is not part of the first heading.
        $rows = array_map(
            'str_getcsv',
            array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? ''))),
        );

        $this->assertSame(
            ['Surname', 'First name', 'Middle name', 'Gender', 'Date of birth', 'Guardian name',
                'Guardian phone', 'Guardian email', 'Email', 'Address'],
            $rows[0],
        );

        $this->assertSame('Okafor', $rows[1][0]);
    }

    /* ------------------------------------------------------------------ */
    /* Read, and hold everything back */
    /* ------------------------------------------------------------------ */

    public function test_reading_a_file_creates_nothing_at_all(): void
    {
        $this->stage("Surname,First name\nOkafor,Chidera\nBello,Aisha\n");

        $this->assertSame(0, Student::count());
        $this->assertSame(0, User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Student'))->count());

        // ...and the reader says what it found, ready to be ticked.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students.multiple-import'))
            ->assertOk()
            ->assertSee('Check before adding')
            ->assertSee('Okafor Chidera')
            ->assertSee('Bello Aisha')
            ->assertSee('2 ready');
    }

    public function test_a_line_that_cannot_be_read_is_reported_with_its_line_number_and_cannot_be_ticked(): void
    {
        $this->stage("Surname,First name\nOkafor,Chidera\nBello,\n");

        $response = $this->actingAs($this->admin)
            ->get(route('admin.students-results.students.multiple-import'))
            ->assertOk()
            ->assertSee('No first name.')
            ->assertSee('1 ready')
            ->assertSee('1 need fixing');

        $html = $response->getContent();

        // The second child's line is the one that cannot be added, and its box is shut.
        $this->assertMatchesRegularExpression(
            '/value="3"\s+class="line-check[^"]*"\s+disabled/s',
            $html,
            'The line that needs fixing can still be ticked.',
        );
    }

    /**
     * A gender or a birthday that cannot be read is a mistake on the sheet, and guessing
     * at it is how a boy ends up on the girls' list. Both are refused rather than defaulted.
     */
    public function test_a_gender_or_a_date_that_cannot_be_read_is_refused_rather_than_guessed(): void
    {
        $this->stage("Surname,First name,Gender,Date of birth\nOkafor,Chidera,X,12/03/2013\nBello,Aisha,F,not a date\n");

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students.multiple-import'))
            ->assertOk()
            ->assertSee('“X” is not a gender')
            ->assertSee('“not a date” is not a date')
            ->assertSee('0 ready')
            ->assertSee('2 need fixing');
    }

    /**
     * Dates are read day first, so 03/12/2013 is 3 December. `strtotime` would read the
     * same string as 12 March and move the birthday by nine months without saying so.
     */
    public function test_a_date_is_read_day_first(): void
    {
        $this->stage("Surname,First name,Date of birth\nOkafor,Chidera,03/12/2013\n");

        $this->commit([2]);

        $this->assertSame('2013-12-03', Student::firstOrFail()->date_of_birth->toDateString());
    }

    /* ------------------------------------------------------------------ */
    /* Commit */
    /* ------------------------------------------------------------------ */

    public function test_the_ticked_children_are_added_to_the_class_that_was_chosen(): void
    {
        $this->stage("Surname,First name,Gender,Guardian name,Guardian phone\n"
            ."Okafor,Chidera,F,Mrs Ngozi Okafor,08039876543\n"
            ."Bello,Aisha,F,Mrs Bello,08030000000\n");

        $this->commit([2, 3])
            ->assertRedirect(route('admin.students-results.students', [
                'class' => $this->jss1->id,
                'section' => $this->a->id,
            ]));

        $this->assertSame(2, Student::count());

        $chidera = Student::query()->where('first_name', 'Chidera')->firstOrFail();

        $this->assertSame('Okafor', $chidera->last_name);
        $this->assertSame('female', $chidera->gender->value);
        $this->assertSame($this->jss1->id, $chidera->level_id);
        $this->assertSame($this->jss1a->id, $chidera->school_class_id);
        $this->assertSame($this->session->id, $chidera->academic_session_id);
        $this->assertSame('Mrs Ngozi Okafor', $chidera->guardian_name);
        $this->assertSame('08039876543', $chidera->guardian_phone);
        $this->assertTrue($chidera->results_portal_enabled);
        $this->assertTrue($chidera->fees_portal_enabled);

        // A number off the same sequence an admission draws from, and its own.
        $this->assertMatchesRegularExpression('#^SAC/2026/\d+$#', $chidera->student_number);
        $this->assertNotSame(
            $chidera->student_number,
            Student::query()->where('first_name', 'Aisha')->firstOrFail()->student_number,
        );
    }

    public function test_a_child_that_is_not_ticked_is_not_added(): void
    {
        $this->stage("Surname,First name\nOkafor,Chidera\nBello,Aisha\n");

        $this->commit([2]);

        $this->assertSame(1, Student::count());
        $this->assertNull(Student::query()->where('first_name', 'Aisha')->first());
    }

    /**
     * Every child added this way can see their own results and fees from the day they
     * arrive, exactly as one admitted through an application can.
     */
    public function test_each_child_added_is_given_a_portal_login(): void
    {
        $this->stage("Surname,First name\nOkafor,Chidera\n");

        $this->commit([2]);

        $student = Student::firstOrFail();

        $this->assertNotNull($student->user_id);

        $account = $student->user;

        $this->assertSame('Chidera Okafor', $account->name);
        $this->assertTrue($account->hasRole('Student'));

        // The number they were given is the password they start with, so the office has
        // nothing to write down and hand over.
        $this->assertTrue(Hash::check($student->student_number, $account->password));

        // No email was on the sheet, so one was made rather than the login being refused.
        $this->assertStringContainsString('@student.', $account->email);
    }

    /**
     * An import is not a bill. What a family is charged is the office's decision, taken
     * on the Fees screen, and a spreadsheet full of names is not it.
     */
    public function test_nothing_is_billed_for_children_who_arrive_by_spreadsheet(): void
    {
        $this->stage("Surname,First name\nOkafor,Chidera\nBello,Aisha\n");

        $this->commit([2, 3]);

        $this->assertSame(2, Student::count());
        $this->assertSame(0, Invoice::count());
    }

    /* ------------------------------------------------------------------ */
    /* The sheet says where, the form says which class */
    /* ------------------------------------------------------------------ */

    public function test_a_class_the_school_has_not_made_yet_is_refused_before_the_file_is_read(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.students.multiple-import.preview'), [
                'level_id' => $this->jss1->id,
                // JSS1C: the section exists, the class does not.
                'section_id' => $this->c->id,
                'file' => $this->csv("Surname,First name\nOkafor,Chidera\n"),
            ])
            ->assertSessionHasErrors('section_id');

        $this->assertSame(0, Student::count());
    }

    public function test_a_sheet_without_a_name_column_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.students.multiple-import.preview'), [
                'level_id' => $this->jss1->id,
                'section_id' => $this->a->id,
                'file' => $this->csv("Class,Section\nJSS1,A\n"),
            ])
            ->assertSessionHasErrors('file');
    }

    public function test_importing_is_closed_to_a_role_that_may_only_read_the_roll(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->get(route('admin.students-results.students.multiple-import'))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->post(route('admin.students-results.students.multiple-import.preview'), [
                'level_id' => $this->jss1->id,
                'section_id' => $this->a->id,
                'file' => $this->csv("Surname,First name\nOkafor,Chidera\n"),
            ])
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    /** Upload a file and leave it staged, as the office's first step does. */
    private function stage(string $contents): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.students.multiple-import.preview'), [
                'level_id' => $this->jss1->id,
                'section_id' => $this->a->id,
                'file' => $this->csv($contents),
            ])
            ->assertRedirect(route('admin.students-results.students.multiple-import'))
            ->assertSessionHasNoErrors();
    }

    /** Add the ticked lines, as the second step does. */
    private function commit(array $lines)
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.students-results.students.multiple-import.commit'), ['lines' => $lines]);
    }

    private function classOf(Section $section, bool $active = true): SchoolClass
    {
        return SchoolClass::create([
            'level_id' => $this->jss1->id,
            'section_id' => $section->id,
            'name' => $this->jss1->name.$section->name,
            'is_active' => $active,
        ]);
    }

    private function csv(string $contents, string $name = 'children.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
