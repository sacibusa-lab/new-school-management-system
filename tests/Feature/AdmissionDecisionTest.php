<?php

namespace Tests\Feature;

use App\Enums\AdmissionDecisionStatus;
use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Enums\ScoreSource;
use App\Models\AcademicSession;
use App\Models\AdmissionDecision;
use App\Models\AdmissionSetting;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\SchoolLevel;
use App\Models\Score;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\User;
use App\Services\Admissions\AdmissionService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The cutoff desk's two promises.
 *
 * Recalculating the merit list must never unmake a decision, and applying the
 * cutoff must never reverse one a person made by hand. Both were broken: a
 * recalculation reset every verdict to Pending, which left a candidate marked
 * rejected on the decision row and admitted on the applicant record — so the
 * public page congratulated somebody it had just failed.
 */
class AdmissionDecisionTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;

    private SchoolLevel $level;

    private Exam $exam;

    private AdmissionService $admissions;

    private User $admin;

    /** @var array<string,ExamSubject> */
    private array $papers = [];

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
            'status' => ExamStatus::Marking,
        ]);

        foreach ([['MTH', 'Mathematics'], ['ENG', 'English Language'], ['GPR', 'General Paper']] as $i => [$code, $name]) {
            $subject = Subject::create(['name' => $name, 'code' => $code, 'is_active' => true]);

            $this->papers[$code] = ExamSubject::create([
                'exam_id' => $this->exam->id,
                'subject_id' => $subject->id,
                'total_marks' => 100,
                'pass_mark' => 40,
                'sort_order' => $i,
            ]);
        }

        $this->admissions = app(AdmissionService::class);
        $this->admin = User::factory()->create();
    }

    /** @param array<string,float> $marks keyed by paper code */
    private function sitExam(string $registration, array $marks): Applicant
    {
        $applicant = Applicant::create([
            'registration_number' => $registration,
            'first_name' => 'Candidate',
            'last_name' => $registration,
            'guardian_name' => 'Mrs. Example',
            'guardian_phone' => '08031234567',
            'guardian_email' => 'parent@example.com',
            'level_applied_for_id' => $this->level->id,
            'academic_session_id' => $this->session->id,
            'status' => ApplicantStatus::Registered,
        ]);

        foreach ($marks as $code => $mark) {
            Score::create([
                'exam_id' => $this->exam->id,
                'exam_subject_id' => $this->papers[$code]->id,
                'applicant_id' => $applicant->id,
                'score' => $mark,
                'source' => ScoreSource::Manual,
            ]);
        }

        return $applicant;
    }

    private function decisionFor(Applicant $applicant): AdmissionDecision
    {
        return AdmissionDecision::query()->where('applicant_id', $applicant->id)->sole();
    }

    /* ------------------------------------------------------------------ */
    /* A recalculation must not unmake a decision                          */
    /* ------------------------------------------------------------------ */

    public function test_recomputing_the_merit_list_keeps_the_verdict_it_already_gave(): void
    {
        $this->sitExam('SAC-00001', ['MTH' => 90, 'ENG' => 80, 'GPR' => 55]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        $this->assertSame(
            AdmissionDecisionStatus::Admitted,
            AdmissionDecision::query()->sole()->decision,
        );

        $this->admissions->compute($this->exam, $this->admin);

        $decision = AdmissionDecision::query()->sole();

        $this->assertSame(AdmissionDecisionStatus::Admitted, $decision->decision);
        $this->assertTrue($decision->is_auto);
        $this->assertNotNull($decision->decided_at);
    }

    public function test_a_late_mark_refreshes_the_figures_without_touching_the_verdict(): void
    {
        $this->sitExam('SAC-00001', ['MTH' => 90, 'ENG' => 80, 'GPR' => 55]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        // One paper is re-marked, and the merit list is recalculated.
        Score::query()->where('exam_subject_id', $this->papers['MTH']->id)->update(['score' => 70]);

        $this->admissions->compute($this->exam, $this->admin);

        $decision = AdmissionDecision::query()->sole();

        $this->assertSame(205.0, (float) $decision->total_score);
        $this->assertSame(68.33, (float) $decision->average_score);
        $this->assertSame(AdmissionDecisionStatus::Admitted, $decision->decision);
    }

    public function test_a_brand_new_merit_row_starts_out_pending(): void
    {
        $this->sitExam('SAC-00001', ['MTH' => 90, 'ENG' => 80, 'GPR' => 55]);

        $this->admissions->compute($this->exam, $this->admin);

        $this->assertSame(
            AdmissionDecisionStatus::Pending,
            AdmissionDecision::query()->sole()->decision,
        );
    }

    public function test_a_hand_made_decision_survives_a_recalculation(): void
    {
        $applicant = $this->sitExam('SAC-00002', ['MTH' => 35, 'ENG' => 42, 'GPR' => 38]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        $this->assertSame(AdmissionDecisionStatus::Rejected, $this->decisionFor($applicant)->decision);

        // The office decides otherwise, by hand.
        $this->admissions->override(
            $this->decisionFor($applicant),
            AdmissionDecisionStatus::Admitted,
            "Admitted on the head teacher's recommendation.",
            $this->admin,
        );

        // A recalculation must not take that back.
        $this->admissions->compute($this->exam, $this->admin);

        $decision = $this->decisionFor($applicant);

        $this->assertSame(AdmissionDecisionStatus::Admitted, $decision->decision);
        $this->assertFalse($decision->is_auto);
        $this->assertSame('Admitted on the head teacher\'s recommendation.', $decision->remarks);
        $this->assertSame(ApplicantStatus::Admitted, $applicant->refresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /* The cutoff must not reverse a hand-made decision                    */
    /* ------------------------------------------------------------------ */

    public function test_applying_the_cutoff_leaves_a_hand_made_decision_alone(): void
    {
        $above = $this->sitExam('SAC-00001', ['MTH' => 90, 'ENG' => 80, 'GPR' => 55]);
        $below = $this->sitExam('SAC-00002', ['MTH' => 20, 'ENG' => 30, 'GPR' => 25]);

        $this->admissions->compute($this->exam, $this->admin);

        // The office admits the failing candidate by hand, below the cutoff.
        $this->admissions->override(
            $this->decisionFor($below),
            AdmissionDecisionStatus::Admitted,
            "Admitted on the head teacher's recommendation.",
            $this->admin,
        );

        $result = $this->admissions->applyCutoff($this->exam, $this->admin);

        $this->assertSame(1, $result['kept'], 'The hand-made decision should be reported as kept.');
        $this->assertSame(1, $result['admitted'], 'Only the candidate above the line is decided.');
        $this->assertSame(0, $result['rejected']);

        $this->assertSame(AdmissionDecisionStatus::Admitted, $this->decisionFor($below)->decision);
        $this->assertFalse($this->decisionFor($below)->is_auto);
        $this->assertSame(ApplicantStatus::Admitted, $below->refresh()->status);

        // Everyone else was still decided.
        $this->assertSame(AdmissionDecisionStatus::Admitted, $this->decisionFor($above)->decision);
        $this->assertTrue($this->decisionFor($above)->is_auto);
    }

    public function test_a_place_taken_by_hand_counts_towards_the_slots(): void
    {
        AdmissionSetting::create([
            'academic_session_id' => $this->session->id,
            'level_id' => $this->level->id,
            'cutoff_mark' => 50,
            'subject_pass_mark' => 50,
            'available_slots' => 1,
            'is_active' => true,
        ]);

        $weaker = $this->sitExam('SAC-00001', ['MTH' => 60, 'ENG' => 60, 'GPR' => 60]);
        $stronger = $this->sitExam('SAC-00002', ['MTH' => 90, 'ENG' => 90, 'GPR' => 90]);

        $this->admissions->compute($this->exam, $this->admin);

        // The single place is given to the weaker candidate, by hand.
        $this->admissions->override(
            $this->decisionFor($weaker),
            AdmissionDecisionStatus::Admitted,
            null,
            $this->admin,
        );

        $result = $this->admissions->applyCutoff($this->exam, $this->admin);

        // The stronger candidate is above the line, but the place has gone.
        $this->assertSame(0, $result['admitted']);
        $this->assertSame(1, $result['waiting']);
        $this->assertSame(1, $result['kept']);
        $this->assertSame(AdmissionDecisionStatus::Admitted, $this->decisionFor($weaker)->decision);
        $this->assertSame(ApplicantStatus::Shortlisted, $stronger->refresh()->status);
    }

    /* ------------------------------------------------------------------ */
    /* What the parent then sees                                           */
    /* ------------------------------------------------------------------ */

    public function test_a_failed_candidate_is_told_so_and_offered_the_resit_button(): void
    {
        $this->sitExam('SAC-00002', ['MTH' => 35, 'ENG' => 42, 'GPR' => 38]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        $this->get(route('public.status', ['registration_number' => 'SAC-00002']))
            ->assertOk()
            ->assertSee('Not admitted on this occasion')
            ->assertSee('Register for the resit examination')
            ->assertDontSee('you have been admitted');
    }

    /**
     * The bug in one test: a decision corrected by hand used to be wiped by the
     * next recalculation, while the applicant record kept the old verdict — so
     * the page congratulated a candidate the decision row had failed.
     */
    public function test_the_page_and_the_decision_row_cannot_disagree_after_a_recalculation(): void
    {
        $this->sitExam('SAC-00002', ['MTH' => 35, 'ENG' => 42, 'GPR' => 38]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        $this->admissions->compute($this->exam, $this->admin);

        $decision = AdmissionDecision::query()->sole();

        $this->assertSame(AdmissionDecisionStatus::Rejected, $decision->decision);
        $this->assertSame(ApplicantStatus::Rejected, $decision->applicant->refresh()->status);

        $this->get(route('public.status', ['registration_number' => 'SAC-00002']))
            ->assertOk()
            ->assertSee('Not admitted on this occasion')
            ->assertDontSee('you have been admitted');
    }
}
