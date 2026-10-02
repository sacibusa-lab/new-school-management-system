<?php

namespace Tests\Feature;

use App\Enums\PromotionAction;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Score;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A student's own record.
 *
 * Three screens open it — the eye on the register, View on the students list, and
 * Student record under an admitted applicant — and for a long time it had a
 * controller, a route and no view at all. That is a 500 rather than an empty page,
 * so the first thing pinned down here is simply that it opens, and the second is
 * that the links pointing at it land on something.
 */
class StudentRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $year;

    private SchoolLevel $jss1;

    private SchoolLevel $jss2;

    private SchoolClass $jss1a;

    private SchoolClass $jss2a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->year = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'is_current' => true,
        ]);

        $this->jss1 = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);
        $this->jss2 = SchoolLevel::create(['name' => 'JSS2', 'order' => 2, 'is_active' => true]);

        $sectionA = Section::create(['name' => 'A', 'order' => 1]);

        $this->jss1a = SchoolClass::create([
            'level_id' => $this->jss1->id,
            'section_id' => $sectionA->id,
            'name' => 'JSS1A',
            'is_active' => true,
        ]);

        $this->jss2a = SchoolClass::create([
            'level_id' => $this->jss2->id,
            'section_id' => $sectionA->id,
            'name' => 'JSS2A',
            'is_active' => true,
        ]);
    }

    public function test_the_record_opens(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('Ada Okonkwo')
            // The admission number is the one the office knows them by, so it has to
            // be on the page and not only in the url they arrived on.
            ->assertSee('SAC/2026/014')
            ->assertSee('JSS1A')
            ->assertSee('Mrs Okonkwo');
    }

    /**
     * The two lists that link here have to point at a page that opens. A link is not
     * a link if the other end is a 500, and that is exactly what happened: the
     * routes were written before the view was.
     */
    public function test_the_lists_that_link_here_point_at_a_page_that_opens(): void
    {
        $student = $this->student();
        $url = route('admin.students.show', $student);

        foreach ([
            route('admin.students-results.students', ['class' => $this->jss1->id]),
            route('admin.students.index'),
        ] as $list) {
            $this->actingAs($this->admin)
                ->get($list)
                ->assertOk()
                ->assertSee($url, false);
        }

        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    public function test_the_record_shows_what_the_fees_have_come_to(): void
    {
        $student = $this->student();

        $this->invoice($student, total: 100_000, paid: 25_000);

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('100,000.00')
            ->assertSee('25,000.00')
            // What is left, worked out for the office rather than left to be subtracted
            // by eye across two tables.
            ->assertSee('75,000.00');
    }

    public function test_the_record_says_so_when_nothing_has_been_billed_yet(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('No fees have been raised against this student yet.')
            ->assertSee('Nothing has been paid in against this student yet.');
    }

    public function test_the_record_is_closed_to_somebody_without_the_permission(): void
    {
        $student = $this->student();

        $this->actingAs(User::factory()->create())
            ->get(route('admin.students.show', $student))
            ->assertForbidden();
    }

    /** The one way in that does not need students.view. */
    public function test_a_student_may_open_their_own_record(): void
    {
        $account = User::factory()->create();

        $this->assertFalse($account->can('students.view'));

        $student = $this->student(['user_id' => $account->id]);

        $this->actingAs($account)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('Ada Okonkwo');
    }

    /* ------------------------------------------------------------------ */
    /* How they got to this year */
    /* ------------------------------------------------------------------ */

    public function test_the_record_shows_how_the_student_got_to_this_year(): void
    {
        $student = $this->student();

        $this->promote($student, PromotionAction::Promoted);

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('Promotion history')
            ->assertSee('2026/2027')
            ->assertSee('2027/2028')
            ->assertSee('JSS1A')
            ->assertSee('JSS2A')
            ->assertSee('Promoted');
    }

    /** The words the school uses for a decision, not the ones the enum is named with. */
    public function test_a_student_who_sat_the_year_again_reads_as_repeating_it(): void
    {
        $student = $this->student();

        $this->promote($student, PromotionAction::Repeated, toClass: $this->jss1a);

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('Repeats the year')
            ->assertDontSee('Repeated');
    }

    public function test_the_record_says_so_when_no_decision_has_ever_been_taken(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('No decision has been recorded about this student at the end of a session yet.');
    }

    /* ------------------------------------------------------------------ */
    /* The examination they came in on */
    /* ------------------------------------------------------------------ */

    public function test_the_record_shows_the_entrance_examination_they_came_in_on(): void
    {
        $student = $this->student();

        $this->sitTheEntranceExam($student, score: 78);

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('Examination results')
            ->assertSee('Mathematics')
            ->assertSee('78%')
            ->assertSee('A');
    }

    /** A transfer in, or a student admitted before the marks were ever recorded. */
    public function test_the_record_says_so_when_there_is_no_application_behind_them(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('This student has no application on file, so there is no entrance examination behind them.');
    }

    public function test_the_record_says_so_when_the_application_carries_no_marks(): void
    {
        $student = $this->student(['applicant_id' => $this->applicant()->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee('No examination scores were captured for this student.');
    }

    /* ------------------------------------------------------------------ */
    /* Editing the record */
    /* ------------------------------------------------------------------ */

    public function test_the_edit_form_opens_with_the_student_already_on_it(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->get(route('admin.students.edit', $student))
            ->assertOk()
            ->assertSee('SAC/2026/014')
            ->assertSee('JSS1A')
            // The details the record prints are the details this form collects, so
            // nothing on the page is unreachable from the pencil.
            ->assertSee('name="guardian_name"', false)
            ->assertSee('name="date_of_birth"', false)
            ->assertSee('name="school_class_id"', false)
            ->assertSee('name="results_portal_enabled"', false);
    }

    public function test_the_record_links_to_the_form_that_edits_it(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->get(route('admin.students.show', $student))
            ->assertOk()
            ->assertSee(route('admin.students.edit', $student), false);
    }

    /**
     * The class options carry class ids, and each one is labelled with the class that
     * id belongs to.
     *
     * Worth its own test because the failure is silent: built through a collection
     * collapse that renumbers integer keys, the options come out as 0, 1, 2 … The form
     * still renders and still submits — it simply shows the wrong class as theirs, and
     * saves it as theirs, without anything looking wrong.
     */
    public function test_the_class_dropdown_offers_each_class_under_its_own_id(): void
    {
        $student = $this->student([
            'school_class_id' => $this->jss2a->id,
            'level_id' => $this->jss2->id,
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.students.edit', $student))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="'.$this->jss2a->id.'"\s+selected/',
            $html,
            'The class dropdown does not show JSS2A as the class this student is in.',
        );

        $this->assertMatchesRegularExpression(
            '/<option value="'.$this->jss1a->id.'"[^>]*>\s*JSS1A/',
            $html,
            'The option carrying JSS1A\'s id is not the one labelled JSS1A.',
        );
    }

    /**
     * A child in JSS2A is in JSS2. The register filters on the year group, so the two
     * being set apart is how somebody goes missing from their own class list — which
     * is why the class decides and the year group follows it.
     */
    public function test_the_year_group_follows_the_class_when_the_record_is_edited(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->put(route('admin.students.update', $student), [
                'first_name' => 'Ada',
                'last_name' => 'Okonkwo',
                'status' => StudentStatus::Active->value,
                'level_id' => $this->jss1->id,
                'school_class_id' => $this->jss2a->id,
            ])
            ->assertRedirect(route('admin.students.show', $student));

        $this->assertSame($this->jss2a->id, $student->refresh()->school_class_id);
        $this->assertSame($this->jss2->id, $student->level_id);
    }

    /** Admitted but not yet put in an arm, they are still filed under a year. */
    public function test_a_student_with_no_class_keeps_the_year_group_that_was_asked_for(): void
    {
        $student = $this->student();

        $this->actingAs($this->admin)
            ->put(route('admin.students.update', $student), [
                'first_name' => 'Ada',
                'last_name' => 'Okonkwo',
                'status' => StudentStatus::Active->value,
                'level_id' => $this->jss2->id,
                'school_class_id' => null,
            ])
            ->assertRedirect(route('admin.students.show', $student));

        $this->assertNull($student->refresh()->school_class_id);
        $this->assertSame($this->jss2->id, $student->level_id);
    }

    public function test_editing_is_closed_to_a_role_that_may_only_read_the_roll(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $student = $this->student();

        $this->actingAs($teacher)
            ->get(route('admin.students.edit', $student))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->put(route('admin.students.update', $student), [
                'first_name' => 'Changed',
                'last_name' => 'Okonkwo',
                'status' => StudentStatus::Active->value,
            ])
            ->assertForbidden();

        $this->assertSame('Ada', $student->refresh()->first_name);
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    /** @param  array<string,mixed>  $extra */
    private function student(array $extra = []): Student
    {
        return Student::create($extra + [
            'student_number' => 'SAC/2026/014',
            'admission_number' => 'SAC-00001',
            'first_name' => 'Ada',
            'last_name' => 'Okonkwo',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
            'level_id' => $this->jss1->id,
            'school_class_id' => $this->jss1a->id,
            'academic_session_id' => $this->year->id,
            'status' => StudentStatus::Active->value,
            'admitted_at' => now(),
        ]);
    }

    private function invoice(Student $student, float $total, float $paid): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV/2026/001',
            'student_id' => $student->id,
            'academic_session_id' => $this->year->id,
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => $paid,
            'balance' => $total - $paid,
            'status' => 'unpaid',
            'issued_at' => now(),
        ]);
    }

    /** The decision taken about them at the end of the year, and the year after it. */
    private function promote(
        Student $student,
        PromotionAction $action,
        ?SchoolClass $toClass = null,
    ): StudentPromotion {
        return StudentPromotion::create([
            'student_id' => $student->id,
            'from_academic_session_id' => $this->year->id,
            'to_academic_session_id' => AcademicSession::create(['name' => '2027/2028'])->id,
            'from_school_class_id' => $this->jss1a->id,
            'to_school_class_id' => ($toClass ?? $this->jss2a)->id,
            'action' => $action->value,
            'decided_by' => $this->admin->id,
            'decided_at' => '2027-07-12 10:00:00',
        ]);
    }

    private function applicant(): Applicant
    {
        return Applicant::create([
            'registration_number' => 'SAC-00001',
            'first_name' => 'Ada',
            'last_name' => 'Okonkwo',
            'level_applied_for_id' => $this->jss1->id,
            'academic_session_id' => $this->year->id,
        ]);
    }

    /** One subject, one mark, sat before they were admitted. */
    private function sitTheEntranceExam(Student $student, float $score): void
    {
        $applicant = $this->applicant();

        $student->update(['applicant_id' => $applicant->id]);

        $exam = Exam::create([
            'title' => 'Entrance Examination 2026',
            'academic_session_id' => $this->year->id,
            'level_id' => $this->jss1->id,
            'exam_date' => '2026-09-12',
        ]);

        $examSubject = ExamSubject::create([
            'exam_id' => $exam->id,
            'subject_id' => Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true])->id,
            'total_marks' => 100,
        ]);

        Score::create([
            'exam_id' => $exam->id,
            'exam_subject_id' => $examSubject->id,
            'applicant_id' => $applicant->id,
            'score' => $score,
            'grade' => 'A',
        ]);
    }
}
