<?php

namespace Tests\Feature;

use App\Enums\AdmissionDecisionStatus;
use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Enums\ScoreSource;
use App\Models\AcademicSession;
use App\Models\AdmissionDecision;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\SchoolLevel;
use App\Models\Score;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Admissions\AdmissionService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parents read this page on a phone, and some of them print it. So it has to say
 * plainly whether the child got in, and it must not disagree with the decision
 * the school actually made.
 */
class PublicAdmissionStatusTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;

    private SchoolLevel $level;

    private Exam $exam;

    private Applicant $applicant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        Setting::flush();

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'is_current' => true,
            'is_admission_open' => true,
        ]);

        $this->level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $this->exam = Exam::create([
            'title' => 'Entrance Examination 2026/2027',
            'academic_session_id' => $this->session->id,
            'level_id' => $this->level->id,
            'cutoff_mark' => 50,
            'status' => ExamStatus::Completed,
        ]);

        $this->applicant = Applicant::create([
            'registration_number' => 'SAC-00001',
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
            'gender' => 'female',
            'guardian_name' => 'Mrs. Ngozi Okafor',
            'guardian_phone' => '08031234567',
            'guardian_email' => 'ngozi@example.com',
            'level_applied_for_id' => $this->level->id,
            'academic_session_id' => $this->session->id,
            'status' => ApplicantStatus::ExamCompleted,
            'submitted_at' => now(),
        ]);
    }

    private function decide(ApplicantStatus $status, float $average, float $total = 225): AdmissionDecision
    {
        $this->applicant->update(['status' => $status]);

        return AdmissionDecision::create([
            'applicant_id' => $this->applicant->id,
            'exam_id' => $this->exam->id,
            'total_score' => $total,
            'average_score' => $average,
            'highest_score' => 82,
            'subjects_offered' => 3,
            'subjects_passed' => $average >= 50 ? 3 : 1,
            'subjects_failed' => $average >= 50 ? 0 : 2,
            'cutoff_mark' => 50,
            'position' => 1,
            'position_in_level' => 1,
            'decision' => $status === ApplicantStatus::Admitted
                ? AdmissionDecisionStatus::Admitted
                : AdmissionDecisionStatus::Rejected,
        ]);
    }

    /**
     * The lookup asks for the number AND the surname, because the number alone is
     * a counter — SAC-00001, SAC-00002 — and this page shows a child's photograph.
     */
    private function search(string $number = 'SAC-00001', ?string $surname = 'Okafor')
    {
        return $this->get(route('public.status', array_filter([
            'registration_number' => $number,
            'surname' => $surname,
        ], fn ($value) => $value !== null)));
    }

    /**
     * Sit the candidate in front of some papers.
     *
     * @param  array<int,array{0:string,1:string,2:float|null,3:bool}>  $marks
     *         [code, name, mark, absent]
     */
    private function satPapers(array $marks): void
    {
        foreach ($marks as $i => [$code, $name, $mark, $absent]) {
            $subject = Subject::create(['name' => $name, 'code' => $code, 'is_active' => true]);

            $paper = ExamSubject::create([
                'exam_id' => $this->exam->id,
                'subject_id' => $subject->id,
                'total_marks' => 100,
                'pass_mark' => 50,
                'sort_order' => $i,
            ]);

            Score::create([
                'exam_id' => $this->exam->id,
                'exam_subject_id' => $paper->id,
                'applicant_id' => $this->applicant->id,
                'score' => $mark,
                'is_absent' => $absent,
                'source' => ScoreSource::Manual,
            ]);
        }
    }

    public function test_an_admitted_candidate_is_told_so_plainly(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75);

        $this->search()
            ->assertOk()
            ->assertSee('Congratulations — you have been admitted')
            ->assertSee('Chidera Okafor')
            ->assertSee('SAC-00001');
    }

    public function test_the_page_shows_the_total_average_and_cutoff_it_was_decided_on(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75, 225);

        $response = $this->search()->assertOk();

        // The same three figures the score entry grid and the cutoff desk use.
        $response->assertSee('Total over 3 paper(s)');
        $response->assertSee('225');
        $response->assertSee('75%');
        $response->assertSee('50%');
    }

    public function test_a_rejected_candidate_is_told_kindly_and_offered_the_numbers(): void
    {
        $this->decide(ApplicantStatus::Rejected, 38.33, 115);

        $this->search()
            ->assertOk()
            ->assertSee('Not admitted on this occasion')
            ->assertSee('38.33%')
            ->assertSee('50%')
            ->assertSee('2 not passed');
    }

    public function test_a_candidate_still_waiting_is_not_given_a_verdict(): void
    {
        $this->search()
            ->assertOk()
            ->assertSee('Your scripts have been marked')
            ->assertDontSee('you have been admitted')
            ->assertDontSee('Not admitted');
    }

    public function test_a_candidate_with_no_marks_at_all_is_told_they_are_registered(): void
    {
        $this->applicant->update(['status' => ApplicantStatus::Registered]);

        $this->search()
            ->assertOk()
            ->assertSee('Your application is in progress');
    }

    public function test_an_admitted_candidate_is_given_their_admission_number(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75);

        Student::create([
            'applicant_id' => $this->applicant->id,
            'academic_session_id' => $this->session->id,
            'level_id' => $this->level->id,
            'student_number' => 'SAC/2026/001',
            'admission_number' => 'SAC-00001',
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
        ]);

        $this->search()
            ->assertOk()
            ->assertSee('Your admission number')
            ->assertSee('SAC/2026/001');
    }

    public function test_the_photograph_is_shown_when_there_is_one(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75);

        $this->applicant->update(['photo_path' => 'photos/applicants/abc.jpg']);

        $this->search()
            ->assertOk()
            ->assertSee('storage/photos/applicants/abc.jpg', false)
            ->assertSee('Passport photograph of Chidera Okafor');
    }

    public function test_initials_stand_in_when_there_is_no_photograph(): void
    {
        $this->search()
            ->assertOk()
            ->assertSee('CO')
            ->assertDontSee('storage/photos/applicants', false);
    }

    public function test_a_number_typed_loosely_still_finds_the_candidate(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75);

        foreach (['sac-1', 'SAC 00001', 'sac00001'] as $typed) {
            $this->search($typed)
                ->assertOk()
                ->assertSee('Chidera Okafor');
        }
    }

    public function test_an_unknown_number_says_so_without_leaking_anything(): void
    {
        $this->search('SAC-99999')
            ->assertOk()
            ->assertSee('No match found')
            ->assertDontSee('Chidera Okafor');
    }

    public function test_a_parent_can_print_the_page(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75);

        $this->search()
            ->assertOk()
            ->assertSee('Print or save this page');
    }

    /* ------------------------------------------------------------------ */
    /* The papers behind the total                                         */
    /* ------------------------------------------------------------------ */

    public function test_the_marks_are_listed_paper_by_paper(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75, 225);

        $this->satPapers([
            ['MTH', 'Mathematics', 90, false],
            ['ENG', 'English Language', 80, false],
            ['GPR', 'General Paper', 55, false],
        ]);

        $this->search()
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertSee('90 / 100')
            ->assertSee('English Language')
            ->assertSee('80 / 100')
            ->assertSee('General Paper')
            ->assertSee('55 / 100')
            // The examination lists the papers in its own order, not by score.
            ->assertSeeInOrder(['Mathematics', 'English Language', 'General Paper']);
    }

    /**
     * The whole point of listing the papers is that a parent can add them up.
     * If the list and the total are built separately they will drift apart.
     */
    public function test_the_papers_listed_add_up_to_the_total_beside_them(): void
    {
        $this->satPapers([
            ['MTH', 'Mathematics', 90, false],
            ['ENG', 'English Language', 80, false],
            ['GPR', 'General Paper', 55, false],
        ]);

        app(AdmissionService::class)->compute($this->exam);

        $decision = AdmissionDecision::query()->sole();

        $this->assertSame(225.0, (float) $decision->total_score);

        $this->search()
            ->assertOk()
            ->assertSee('Total over 3 paper(s)')
            ->assertSee('225');
    }

    public function test_a_paper_sat_as_absent_says_so_and_is_held_out_of_the_average(): void
    {
        $this->decide(ApplicantStatus::Rejected, 30, 60);

        $this->satPapers([
            ['MTH', 'Mathematics', null, true],
            ['ENG', 'English Language', 60, false],
        ]);

        $this->search()
            ->assertOk()
            ->assertSee('Absent')
            ->assertSee('60 / 100');
    }

    /** A paper nobody entered a mark for must not appear as a failure. */
    public function test_a_paper_with_no_mark_at_all_is_not_listed(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75, 90);

        $this->satPapers([
            ['MTH', 'Mathematics', 90, false],
            ['GPR', 'General Paper', null, false],
        ]);

        $this->search()
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertDontSee('General Paper');
    }

    /** These were asked for and removed: parents want the record, not two more doors. */
    public function test_the_page_no_longer_offers_the_results_and_fees_buttons_or_the_progress_tracker(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75);

        $this->search()
            ->assertOk()
            ->assertDontSee('Check your results')
            ->assertDontSee('View your fees')
            ->assertDontSee('Where you are in the process');
    }

    /* ------------------------------------------------------------------ */
    /* What comes out of the printer                                       */
    /* ------------------------------------------------------------------ */

    /**
     * A parent printing this page wants the record, not the website around it
     * and not the box they typed the number into. Each of these carries
     * `print:hidden`; if one is dropped the sheet fills up with furniture again.
     */
    public function test_the_printout_leaves_out_the_website_and_the_search_box(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75);

        $html = $this->search()->assertOk()->getContent();

        // Literal class strings rather than a regex: the header tag spans several
        // lines and `[^>]*` cannot cross the `>` inside its Alpine attributes.
        $this->assertStringContainsString('transition-shadow print:hidden', $html, 'The site header would print.');
        $this->assertStringContainsString('text-brand-100 print:hidden', $html, 'The site footer would print.');
        $this->assertStringContainsString('text-center print:hidden', $html, 'The page heading would print.');
        $this->assertStringContainsString('max-w-xl print:hidden', $html, 'The search form would print.');
        $this->assertStringContainsString('class="mt-5 print:hidden"', $html, 'The print button would print itself.');
    }

    /**
     * The record has to fit one sheet. It is laid out at ~880px against the
     * ~1047px an A4 page gives once browser margins are taken off — so this
     * guards the slack, not just the current fit.
     */
    public function test_the_printed_record_is_the_only_thing_on_the_page(): void
    {
        $this->decide(ApplicantStatus::Admitted, 75);
        $this->satPapers([
            ['MTH', 'Mathematics', 90, false],
            ['ENG', 'English Language', 80, false],
            ['GPR', 'General Paper', 55, false],
        ]);

        $html = $this->search()->assertOk()->getContent();

        // The body must not be forced to fill a screen on paper: `min-h-screen`
        // would make it taller than the printable area and cost a second sheet.
        $this->assertMatchesRegularExpression('/<body class="[^"]*print:min-h-0/', $html, 'The body would be screen-tall on paper.');

        // The card is one object and must not be sliced across two sheets.
        $this->assertStringContainsString('print:break-inside-avoid', $html);
    }
}
