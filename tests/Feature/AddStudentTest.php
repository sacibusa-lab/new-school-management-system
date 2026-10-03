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
use App\Services\NumberSequenceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Taking one child onto the roll by hand.
 *
 * The two things this file is really about are the number and the login.
 *
 * The number is **typed here**, not issued: the office has a paper register and the number
 * on it is the school's. So the test that matters most is the one that then adds a child
 * through the *series* and finds it has not been offered a number that is already on
 * somebody — the counter has to have been raised past whatever was typed, or an admission
 * later in the year fails at the counter with a parent waiting.
 *
 * The login is what every child added this way gets, exactly as an admission gives it, so
 * a child who arrives by hand can see their results and fees from the first day. What they
 * do not get is a bill: nothing on this screen decides what a family is charged.
 */
class AddStudentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private SchoolLevel $jss1;

    private Section $a;

    private Section $c;

    private SchoolClass $jss1a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        Storage::fake('public');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'is_current' => true,
        ]);

        $this->jss1 = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);

        $this->a = Section::create(['name' => 'A', 'order' => 1]);
        // A section the school has defined but has not made a class of yet.
        $this->c = Section::create(['name' => 'C', 'order' => 3]);

        $this->jss1a = SchoolClass::create([
            'level_id' => $this->jss1->id,
            'section_id' => $this->a->id,
            'name' => 'JSS1A',
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The page */
    /* ------------------------------------------------------------------ */

    public function test_the_page_asks_for_the_child_and_their_number(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students.add'))
            ->assertOk()
            ->assertSee('Academic details')
            ->assertSee('Register No')
            ->assertSee('Academic year')
            ->assertSee('Select Class First')
            ->assertSee('Student details')
            ->assertSee('Surname')
            ->assertSee('Parent / guardian')
            ->assertSee('Drag and drop a file here or click')
            ->assertSee('Save');
    }

    /* ------------------------------------------------------------------ */
    /* Adding one */
    /* ------------------------------------------------------------------ */

    public function test_a_child_is_added_with_the_number_the_office_typed(): void
    {
        $response = $this->add();

        $student = Student::firstOrFail();

        // Landed on the child's own record, so the office sees what they just made.
        $response->assertRedirect(route('admin.students.show', $student));

        $this->assertSame('SAC/2026/014', $student->student_number);
        $this->assertNull($student->admission_number);
        $this->assertSame('Chidera', $student->first_name);
        $this->assertSame('Ada', $student->middle_name);
        $this->assertSame('Okafor', $student->last_name);
        $this->assertSame('female', $student->gender->value);
        $this->assertSame('2013-03-12', $student->date_of_birth->toDateString());
        $this->assertSame($this->jss1->id, $student->level_id);
        $this->assertSame($this->jss1a->id, $student->school_class_id);
        $this->assertSame($this->session->id, $student->academic_session_id);
        $this->assertSame('Mrs Ngozi Okafor', $student->guardian_name);
        $this->assertSame('08039876543', $student->guardian_phone);
        $this->assertSame('ngozi@example.com', $student->guardian_email);
        $this->assertTrue($student->results_portal_enabled);
        $this->assertTrue($student->fees_portal_enabled);
    }

    public function test_the_child_is_given_a_portal_login(): void
    {
        $this->add();

        $student = Student::firstOrFail();

        $this->assertNotNull($student->user_id);
        $this->assertTrue($student->user->hasRole('Student'));
        $this->assertTrue(Hash::check('SAC/2026/014', $student->user->password));

        // A pupil has no email, so the login gets one of its own rather than none.
        $this->assertStringContainsString('@student.', $student->user->email);
    }

    public function test_a_photograph_can_be_attached_as_they_are_added(): void
    {
        $this->add(['photo' => UploadedFile::fake()->image('chidera.jpg', 300, 400)]);

        $path = Student::firstOrFail()->photo_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith('photos/students/', $path);
        Storage::disk('public')->assertExists($path);
    }

    /** Nothing here decides what a family is charged — that is the Fees screen's job. */
    public function test_nothing_is_billed_for_a_child_added_by_hand(): void
    {
        $this->add();

        $this->assertSame(1, Student::count());
        $this->assertSame(0, Invoice::count());
    }

    /* ------------------------------------------------------------------ */
    /* The number, and the series behind it */
    /* ------------------------------------------------------------------ */

    /**
     * The number typed here is spent. The series that issues admission numbers does not
     * know that unless it is told, and left alone it would eventually reach the same
     * number and offer it to an admission — which the unique index would refuse, at the
     * counter, with a parent waiting.
     */
    public function test_the_number_typed_raises_the_series_so_it_cannot_be_issued_again(): void
    {
        $sequences = app(NumberSequenceService::class);

        $this->add(['student_number' => 'SAC/2026/014']);

        $this->assertSame('SAC/2026/015', $sequences->nextStudentNumber(2026));
    }

    /** Raising it must never run backwards, or a number already in use would be reissued. */
    public function test_a_number_behind_the_series_does_not_drag_it_back(): void
    {
        $sequences = app(NumberSequenceService::class);

        // Three children have been enrolled from applications, so the series is at 3.
        $sequences->nextStudentNumber(2026);
        $sequences->nextStudentNumber(2026);
        $sequences->nextStudentNumber(2026);

        $this->add(['student_number' => 'SAC/2026/002']);

        $this->assertSame('SAC/2026/004', $sequences->nextStudentNumber(2026));
    }

    /* ------------------------------------------------------------------ */
    /* What it will not do */
    /* ------------------------------------------------------------------ */

    public function test_a_number_already_on_another_child_is_refused(): void
    {
        $this->add();

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.students.add.store'), $this->details())
            ->assertSessionHasErrors('student_number');

        $this->assertSame(1, Student::count());
    }

    public function test_a_class_the_school_has_not_made_yet_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.students.add.store'), $this->details([
                // JSS1C: the section exists, the class does not.
                'section_id' => $this->c->id,
            ]))
            ->assertSessionHasErrors('section_id');

        $this->assertSame(0, Student::count());
    }

    public function test_adding_is_closed_to_a_role_that_may_only_read_the_roll(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->get(route('admin.students-results.students.add'))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->post(route('admin.students-results.students.add.store'), $this->details())
            ->assertForbidden();

        $this->assertSame(0, Student::count());
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    /** Fill the form in the way the office would, and add the child. */
    private function add(array $extra = [])
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.students-results.students.add.store'), $this->details($extra));
    }

    /** @param  array<string,mixed>  $extra */
    private function details(array $extra = []): array
    {
        return $extra + [
            'academic_session_id' => $this->session->id,
            'student_number' => 'SAC/2026/014',
            'level_id' => $this->jss1->id,
            'section_id' => $this->a->id,
            'first_name' => 'Chidera',
            'middle_name' => 'Ada',
            'last_name' => 'Okafor',
            'gender' => 'female',
            'date_of_birth' => '2013-03-12',
            'guardian_name' => 'Mrs Ngozi Okafor',
            'guardian_phone' => '08039876543',
            'guardian_email' => 'ngozi@example.com',
        ];
    }
}
