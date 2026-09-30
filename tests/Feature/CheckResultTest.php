<?php

namespace Tests\Feature;

use App\Enums\ResultStatus;
use App\Models\AcademicSession;
use App\Models\ResultPublication;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\TermResult;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Checking a result on behalf of the family on the phone.
 *
 * The page's whole reason for existing is to answer the call a parent makes when
 * they cannot see their child's result — so the tests here are mostly about what it
 * shows when there is nothing to show, and about the two numbers being two
 * different numbers. The public checker hides unpublished results; this one must
 * not, or the office cannot tell a parent why theirs is missing.
 */
class CheckResultTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private AcademicSession $lastYear;

    private SchoolClass $schoolClass;

    private Term $firstTerm;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->lastYear = AcademicSession::create(['name' => '2026/2027']);

        $this->session = AcademicSession::create([
            'name' => '2027/2028',
            'is_current' => true,
        ]);

        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $this->schoolClass = SchoolClass::create([
            'level_id' => $level->id,
            'name' => 'JSS1A',
            'is_active' => true,
        ]);

        $this->firstTerm = Term::create([
            'name' => 'First Term',
            'position' => 1,
            'is_current' => true,
        ]);

        Term::create(['name' => 'Second Term', 'position' => 2]);
        Term::create(['name' => 'Third Term', 'position' => 3]);

        $this->student = Student::create([
            'student_number' => 'SAC/2026/001',
            'admission_number' => 'SAC-00001',
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
            'level_id' => $level->id,
            'school_class_id' => $this->schoolClass->id,
            'academic_session_id' => $this->session->id,
            'admitted_at' => now()->subYear(),
            'guardian_name' => 'Mrs. Ngozi Okafor',
            'guardian_phone' => '08031234567',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The form */
    /* ------------------------------------------------------------------ */

    public function test_the_page_asks_for_a_year_a_term_and_an_admission_number(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result'))
            ->assertOk()
            ->assertSee('Check Student Result')
            ->assertSee('Academic year')
            ->assertSee('Term')
            ->assertSee('Admission number');
    }

    /** The office is asking about the term in front of them far more often than not. */
    public function test_the_form_opens_on_the_year_and_term_the_school_is_in(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result'))
            ->assertOk()
            ->assertSee('value="'.$this->session->id.'" selected', false)
            ->assertSee('value="'.$this->firstTerm->id.'" selected', false);
    }

    public function test_the_term_chooser_lists_every_term_in_order(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result'))
            ->assertOk()
            ->getContent();

        // Only the chooser is read, so the order cannot be satisfied by the same
        // words appearing elsewhere on the page. The labels are indented inside
        // their <option>, so they are matched on their own rather than as >First Term<.
        $chooser = str($html)->after('name="term_id"')->before('</select>')->value();

        $positions = [];

        foreach (['First Term', 'Second Term', 'Third Term'] as $name) {
            $at = strpos($chooser, $name);

            $this->assertNotFalse($at, "The term chooser is missing {$name}.");
            $positions[] = $at;
        }

        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'The terms are not in the order the school runs them.');
    }

    /* ------------------------------------------------------------------ */
    /* Reading a result */
    /* ------------------------------------------------------------------ */

    public function test_it_finds_the_student_by_admission_number_and_shows_the_result(): void
    {
        $this->reportCard($this->session, $this->firstTerm, ResultStatus::Published);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => $this->session->id,
                'term_id' => $this->firstTerm->id,
                'admission_number' => 'SAC/2026/001',
            ]))
            ->assertOk()
            ->assertSee('Chidera Okafor')
            ->assertSee('First Term — 2027/2028')
            ->assertSee('72%')
            ->assertSee('5th of 40')
            ->assertSee('B2')
            ->assertSee('Mathematics')
            ->assertSee('Printable report card')
            ->assertDontSee('No student with that number')
            ->assertDontSee('Not published to parents');
    }

    /**
     * The two numbers are the trap this page has to survive: the slip in the
     * parent's hand often carries the number the child applied with, not the one
     * issued on admission, and the office should not have to ask which is which.
     */
    public function test_it_finds_the_same_child_by_the_number_they_applied_with(): void
    {
        $this->reportCard($this->session, $this->firstTerm, ResultStatus::Published);

        foreach (['SAC-00001', 'sac 1', 'SAC00001'] as $written) {
            $this->actingAs($this->admin)
                ->get(route('admin.students-results.check-result', [
                    'academic_session_id' => $this->session->id,
                    'term_id' => $this->firstTerm->id,
                    'admission_number' => $written,
                ]))
                ->assertOk()
                ->assertSee('Chidera Okafor')
                ->assertDontSee('No student with that number');
        }
    }

    public function test_it_finds_the_child_however_the_admission_number_is_spaced(): void
    {
        $this->reportCard($this->session, $this->firstTerm, ResultStatus::Published);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => $this->session->id,
                'term_id' => $this->firstTerm->id,
                'admission_number' => 'sac 2026 1',
            ]))
            ->assertOk()
            ->assertSee('Chidera Okafor');
    }

    /* ------------------------------------------------------------------ */
    /* What it says when there is no result to show */
    /* ------------------------------------------------------------------ */

    public function test_an_unpublished_result_is_shown_and_labelled_as_unpublished(): void
    {
        $this->reportCard($this->session, $this->firstTerm, ResultStatus::Approved);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => $this->session->id,
                'term_id' => $this->firstTerm->id,
                'admission_number' => 'SAC/2026/001',
            ]))
            ->assertOk()
            // The result is there to read...
            ->assertSee('72%')
            ->assertSee('Mathematics')
            // ...and the reason the parent cannot see it is spelled out.
            ->assertSee('Not published to parents')
            ->assertDontSee('Printable report card');
    }

    /**
     * A published result is offered to the office as a slip, because the caller
     * usually wants a copy of the thing rather than its contents read aloud.
     */
    public function test_a_published_result_can_be_printed_for_the_caller(): void
    {
        $result = $this->reportCard($this->session, $this->firstTerm, ResultStatus::Published);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => $this->session->id,
                'term_id' => $this->firstTerm->id,
                'admission_number' => 'SAC/2026/001',
            ]))
            ->assertOk()
            ->assertSee(route('public.result.slip', $result), false)
            ->assertDontSee('Not published to parents');
    }

    public function test_it_says_when_the_student_has_no_result_for_that_year_and_term(): void
    {
        $this->reportCard($this->session, $this->firstTerm, ResultStatus::Published);

        // Same child, a term they have nothing for.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => $this->session->id,
                'term_id' => Term::query()->where('position', 3)->sole()->id,
                'admission_number' => 'SAC/2026/001',
            ]))
            ->assertOk()
            ->assertSee('Chidera Okafor')
            ->assertSee('No result on file for Third Term 2027/2028');
    }

    public function test_it_says_when_no_student_has_that_number(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => $this->session->id,
                'term_id' => $this->firstTerm->id,
                'admission_number' => 'SAC/2026/999',
            ]))
            ->assertOk()
            ->assertSee('No student with that number')
            ->assertDontSee('Chidera Okafor');
    }

    /** An empty box is not a search: it must not be reported as "no such student". */
    public function test_an_empty_admission_number_asks_for_one_rather_than_giving_up(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result'))
            ->assertOk()
            ->assertSee('Type an admission number to begin')
            ->assertDontSee('No student with that number');
    }

    public function test_an_unknown_year_or_term_is_rejected_rather_than_guessed(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => 9999,
                'term_id' => 9999,
                'admission_number' => 'SAC/2026/001',
            ]))
            ->assertSessionHasErrors(['academic_session_id', 'term_id']);
    }

    /* ------------------------------------------------------------------ */
    /* Reaching back to the terms the school has moved on from */
    /* ------------------------------------------------------------------ */

    /**
     * Why terms are universal and dates are per session: the call that comes in
     * eleven months later is about the session that has ended, and last year's
     * result has to still be there — and still say last year.
     */
    public function test_a_result_from_an_earlier_session_is_still_reachable(): void
    {
        $this->reportCard($this->lastYear, $this->firstTerm, ResultStatus::Published);

        // The school has moved on; the child has not been re-admitted.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => $this->session->id,
                'term_id' => $this->firstTerm->id,
                'admission_number' => 'SAC/2026/001',
            ]))
            ->assertOk()
            ->assertSee('No result on file for First Term 2027/2028');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => $this->lastYear->id,
                'term_id' => $this->firstTerm->id,
                'admission_number' => 'SAC/2026/001',
            ]))
            ->assertOk()
            ->assertSee('First Term — 2026/2027')
            ->assertSee('Mathematics')
            ->assertDontSee('No result on file');
    }

    /* ------------------------------------------------------------------ */
    /* Who may look */
    /* ------------------------------------------------------------------ */

    /**
     * No surname stands between this page and a child's record, so the gate has
     * to hold on the lookup itself and not only on the empty form.
     */
    public function test_the_result_itself_is_refused_to_anyone_without_the_permission(): void
    {
        $this->reportCard($this->session, $this->firstTerm, ResultStatus::Published);

        $officer = User::factory()->create();
        $officer->assignRole('Exam Officer');

        $this->assertTrue($officer->can('results.view'));
        $this->assertFalse($officer->can('results.check'));

        $this->actingAs($officer)
            ->get(route('admin.students-results.check-result', [
                'academic_session_id' => $this->session->id,
                'term_id' => $this->firstTerm->id,
                'admission_number' => 'SAC/2026/001',
            ]))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */

    /**
     * A report card as the results service writes one: a summary for the term and
     * a row per subject, with the session and term it belongs to.
     */
    private function reportCard(AcademicSession $session, Term $term, ResultStatus $status): TermResult
    {
        $result = TermResult::create([
            'student_id' => $this->student->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'school_class_id' => $this->schoolClass->id,
            'total_score' => 576,
            'average' => 72,
            'subjects_count' => 8,
            'position' => 5,
            'class_size' => 40,
            'grade' => 'B2',
            'teacher_remark' => 'A steady term.',
            'status' => $status,
            'published_at' => $status === ResultStatus::Published ? now() : null,
        ]);

        $result->items()->create([
            'subject_id' => Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true])->id,
            'ca_score' => 25,
            'exam_score' => 47,
            'total_score' => 72,
            'grade' => 'B2',
            'remark' => 'Good',
            'subject_position' => 6,
        ]);

        if ($status === ResultStatus::Published) {
            ResultPublication::create([
                'academic_session_id' => $session->id,
                'term_id' => $term->id,
                'school_class_id' => $this->schoolClass->id,
                'is_published' => true,
                'published_at' => now(),
            ]);
        }

        return $result;
    }
}
