<?php

namespace Tests\Feature;

use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\SchoolLevel;
use App\Models\Score;
use App\Models\Setting;
use App\Models\Subject;
use App\Services\Admissions\ResitService;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\SmsTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resits are the one place in the pipeline where it is easy to hand a candidate
 * the wrong paper, so these tests pin down exactly which subjects get copied.
 */
class ResitTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;

    private SchoolLevel $level;

    private Exam $exam;

    /** @var array<string,ExamSubject> */
    private array $papers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(SmsTemplateSeeder::class);
        Setting::flush();

        $this->session = AcademicSession::create([
            'name' => '2025/2026',
            'is_current' => true,
            'is_admission_open' => true,
        ]);

        $this->level = SchoolLevel::create(['name' => 'JSS 1', 'order' => 1]);

        $this->exam = Exam::create([
            'title' => 'Entrance Examination',
            'academic_session_id' => $this->session->id,
            'level_id' => $this->level->id,
            'cutoff_mark' => 50,
            'status' => ExamStatus::Marking,
        ]);

        // Three papers, each out of 100, passing at 40.
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
    }

    private function applicant(string $number, array $marks): Applicant
    {
        $applicant = Applicant::create([
            'registration_number' => $number,
            'first_name' => 'Test',
            'last_name' => 'Candidate',
            'phone' => '08031234567',
            'guardian_name' => 'Mr Test',
            'guardian_phone' => '08031234567',
            'level_applied_for_id' => $this->level->id,
            'academic_session_id' => $this->session->id,
            'status' => ApplicantStatus::Rejected,
        ]);

        foreach ($marks as $code => $mark) {
            Score::create([
                'exam_id' => $this->exam->id,
                'exam_subject_id' => $this->papers[$code]->id,
                'applicant_id' => $applicant->id,
                'score' => $mark,
            ]);
        }

        return $applicant;
    }

    public function test_only_the_papers_the_candidate_failed_are_copied_across(): void
    {
        $applicant = $this->applicant('SAC-00001', ['MTH' => 80, 'ENG' => 20, 'GPR' => 30]);

        $resit = app(ResitService::class)->createResit($this->exam, [$applicant->id]);

        $this->assertTrue($resit->is_resit);
        $this->assertSame($this->exam->id, $resit->resit_of_exam_id);
        $this->assertSame(2, $resit->resit_round, 'the first resit of a sitting is round 2');

        // English and General Paper only — nobody re-sits a subject they passed.
        $codes = $resit->examSubjects->map(fn ($paper) => $paper->subject->code)->sort()->values()->all();
        $this->assertSame(['ENG', 'GPR'], $codes);

        $rows = Score::query()->where('exam_id', $resit->id)->get();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows->every(fn (Score $row) => $row->is_resit));
        $this->assertTrue($rows->every(fn (Score $row) => $row->attempt === 2));
        $this->assertTrue($rows->every(fn (Score $row) => $row->score === null), 'a resit starts unmarked');
    }

    public function test_the_copied_papers_keep_their_totals_and_pass_marks(): void
    {
        $applicant = $this->applicant('SAC-00001', ['MTH' => 10, 'ENG' => 10, 'GPR' => 10]);

        $resit = app(ResitService::class)->createResit($this->exam, [$applicant->id]);

        foreach ($resit->examSubjects as $paper) {
            $this->assertSame('100.00', $paper->total_marks);
            $this->assertSame('40.00', $paper->pass_mark);
        }
    }

    public function test_a_candidate_who_passed_every_paper_but_missed_the_cutoff_resits_the_whole_exam(): void
    {
        // 45 in each: passes every paper (>= 40) but averages 45 against a 50 cutoff.
        $applicant = $this->applicant('SAC-00002', ['MTH' => 45, 'ENG' => 45, 'GPR' => 45]);

        $resit = app(ResitService::class)->createResit($this->exam, [$applicant->id]);

        $this->assertCount(3, $resit->examSubjects);
    }

    public function test_an_absence_counts_as_a_failure(): void
    {
        $applicant = $this->applicant('SAC-00003', ['MTH' => 90, 'ENG' => 90]);

        Score::create([
            'exam_id' => $this->exam->id,
            'exam_subject_id' => $this->papers['GPR']->id,
            'applicant_id' => $applicant->id,
            'score' => null,
            'is_absent' => true,
        ]);

        $resit = app(ResitService::class)->createResit($this->exam, [$applicant->id]);

        $this->assertCount(1, $resit->examSubjects);
        $this->assertSame('GPR', $resit->examSubjects->first()->subject->code);
    }

    public function test_admitted_candidates_are_not_in_the_resit_pool(): void
    {
        $failed = $this->applicant('SAC-00001', ['MTH' => 10]);
        $this->applicant('SAC-00002', ['MTH' => 90])->forceFill([
            'status' => ApplicantStatus::Admitted,
        ])->save();

        $pool = app(ResitService::class)->candidates($this->exam);

        $this->assertSame([$failed->id], $pool->pluck('id')->all());
    }

    public function test_self_registration_twice_does_not_open_a_second_sitting(): void
    {
        $applicant = $this->applicant('SAC-00001', ['MTH' => 10, 'ENG' => 90, 'GPR' => 90]);

        $service = app(ResitService::class);

        $first = $service->selfRegister($applicant);
        $second = $service->selfRegister($applicant->fresh());

        $this->assertFalse($first['already']);
        $this->assertTrue($second['already'], 'the second attempt reuses the sitting already booked');
        $this->assertSame($first['exam']->id, $second['exam']->id);

        $this->assertSame(1, Exam::query()->where('is_resit', true)->count());
        $this->assertSame(1, Score::query()->where('is_resit', true)->count(), 'no duplicate blank rows');
    }

    public function test_a_resit_cannot_be_created_without_candidates(): void
    {
        $this->expectException(\RuntimeException::class);

        app(ResitService::class)->createResit($this->exam, []);
    }

    public function test_resits_can_be_switched_off_by_settings(): void
    {
        Setting::put('resit_enabled', false, ['type' => 'bool']);

        $this->assertFalse(app(ResitService::class)->isEnabled());
    }

    /* ------------------------------------------------------------------ */
    /* The public self-service journey                                     */
    /* ------------------------------------------------------------------ */

    public function test_the_public_status_page_offers_a_resit_to_a_rejected_candidate(): void
    {
        $applicant = $this->applicant('SAC-00001', ['MTH' => 10, 'ENG' => 20, 'GPR' => 30]);

        $this->get('/admission?registration_number=' . $applicant->registration_number . '&surname=Candidate')
            ->assertOk()
            ->assertSee('Not admitted on this occasion')
            ->assertSee('Register for the resit examination');
    }

    public function test_an_admitted_candidate_is_not_offered_a_resit(): void
    {
        $applicant = $this->applicant('SAC-00001', ['MTH' => 90, 'ENG' => 90, 'GPR' => 90]);
        $applicant->forceFill(['status' => ApplicantStatus::Admitted])->save();

        $this->get('/admission?registration_number=' . $applicant->registration_number . '&surname=Candidate')
            ->assertOk()
            ->assertDontSee('Register for the resit examination');
    }

    public function test_a_rejected_candidate_can_book_a_resit_from_the_public_page(): void
    {
        $applicant = $this->applicant('SAC-00001', ['MTH' => 80, 'ENG' => 10, 'GPR' => 15]);

        $this->post('/admission/resit', [
            'registration_number' => $applicant->registration_number,
            'surname' => 'Candidate',
        ])->assertRedirect(route('public.status', [
            'registration_number' => 'SAC-00001',
            'surname' => 'Candidate',
        ]));

        $resit = Exam::query()->where('is_resit', true)->sole();

        $this->assertSame(2, $resit->resit_round);
        $this->assertCount(2, $resit->examSubjects, 'only English and General Paper were failed');

        // The page now confirms the booking instead of offering the button again.
        $this->get('/admission?registration_number=' . $applicant->registration_number . '&surname=Candidate')
            ->assertOk()
            ->assertSee('You are registered for a resit')
            ->assertDontSee('Register for the resit examination');
    }

    public function test_the_public_booking_is_refused_when_resits_are_switched_off(): void
    {
        $applicant = $this->applicant('SAC-00001', ['MTH' => 10]);
        Setting::put('resit_enabled', false, ['type' => 'bool']);

        $this->post('/admission/resit', [
            'registration_number' => $applicant->registration_number,
            'surname' => 'Candidate',
        ])->assertSessionHas('error');

        $this->assertSame(0, Exam::query()->where('is_resit', true)->count());
    }

    public function test_an_unknown_registration_number_cannot_book_a_resit(): void
    {
        // The number and the surname are checked together, and a pair that matches
        // nothing is reported the same way whichever half was wrong.
        $this->post('/admission/resit', [
            'registration_number' => 'SAC-99999',
            'surname' => 'Nobody',
        ])->assertSessionHasErrors('registration_number');

        $this->assertSame(0, Exam::query()->where('is_resit', true)->count());
    }

    public function test_a_candidate_who_never_sat_anything_cannot_book_a_resit(): void
    {
        $applicant = Applicant::create([
            'registration_number' => 'SAC-00042',
            'first_name' => 'Never',
            'last_name' => 'Sat',
            'guardian_phone' => '08031234567',
            'academic_session_id' => $this->session->id,
            'status' => ApplicantStatus::Registered,
        ]);

        $this->post('/admission/resit', [
            'registration_number' => $applicant->registration_number,
            'surname' => 'Sat',
        ])->assertSessionHas('error');

        $this->assertSame(0, Exam::query()->where('is_resit', true)->count());
    }
}
