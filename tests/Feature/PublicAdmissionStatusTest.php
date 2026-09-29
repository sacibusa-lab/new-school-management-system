<?php

namespace Tests\Feature;

use App\Enums\AdmissionDecisionStatus;
use App\Enums\ApplicantStatus;
use App\Models\AcademicSession;
use App\Models\AdmissionDecision;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\Student;
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
            'status' => \App\Enums\ExamStatus::Completed,
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

    private function search(string $number = 'SAC-00001')
    {
        return $this->get(route('public.status', ['registration_number' => $number]));
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
}
