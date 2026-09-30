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
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The paperwork the office hands out.
 *
 * The decisions were already being made and stored correctly; what was missing
 * was the paper that leaves the building — the merit list on the noticeboard, the
 * letter a family takes home, the admit card shown at the gate, and the list of
 * children waiting for a place that came free. Each is a different view of the
 * same rows, so the thing worth guarding is that each shows the right people and
 * that promoting from the waiting list cannot quietly overshoot the places.
 */
class AdmissionPaperworkTest extends TestCase
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
        $this->seed(RolePermissionSeeder::class);
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

        foreach ([['MTH', 'Mathematics'], ['ENG', 'English Language']] as $i => [$code, $name]) {
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
        $this->admin->assignRole('Super Admin');
    }

    /** @param array<string,float> $marks keyed by paper code */
    private function sitExam(string $firstName, string $lastName, array $marks): Applicant
    {
        $applicant = Applicant::create([
            'registration_number' => 'SAC-'.str_pad((string) Applicant::query()->count() + 1, 5, '0', STR_PAD_LEFT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'guardian_name' => 'Mrs. '.$lastName,
            'guardian_phone' => '08031234567',
            'guardian_email' => strtolower($firstName).'@example.com',
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

    /** Give the level a fixed number of places, so the waiting list fills up. */
    private function setPlaces(int $slots): void
    {
        AdmissionSetting::create([
            'academic_session_id' => $this->session->id,
            'level_id' => $this->level->id,
            'cutoff_mark' => 50,
            'subject_pass_mark' => 40,
            'available_slots' => $slots,
            'is_active' => true,
        ]);
    }

    private function decisionFor(Applicant $applicant): AdmissionDecision
    {
        return AdmissionDecision::query()->where('applicant_id', $applicant->id)->sole();
    }

    /** One admitted child and one who passed but arrived after the places went. */
    private function threeCandidatesWithTwoPlaces(): array
    {
        $this->setPlaces(2);

        // Deliberately sat out of name order, so ordering has to be the merit
        // order rather than the order they happened to be registered in.
        $third = $this->sitExam('Zainab', 'Yusuf', ['MTH' => 60, 'ENG' => 60]);
        $top = $this->sitExam('Amaka', 'Obi', ['MTH' => 90, 'ENG' => 90]);
        $second = $this->sitExam('Bola', 'Adeyemi', ['MTH' => 80, 'ENG' => 70]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        return [$top, $second, $third];
    }

    /* ------------------------------------------------------------------ */
    /* The merit list */
    /* ------------------------------------------------------------------ */

    public function test_the_merit_list_prints_every_candidate_in_merit_order(): void
    {
        [$top, $second, $third] = $this->threeCandidatesWithTwoPlaces();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.admissions.merit', $this->exam))
            ->assertOk()
            ->assertSee('Merit list');

        $body = $response->getContent();

        // Ranked by average, not by when they registered.
        $this->assertSame(1, $this->decisionFor($top)->position);
        $this->assertSame(2, $this->decisionFor($second)->position);
        $this->assertSame(3, $this->decisionFor($third)->position);

        $this->assertLessThan(
            strpos($body, 'Bola Adeyemi'),
            strpos($body, 'Amaka Obi'),
        );

        $this->assertLessThan(
            strpos($body, 'Zainab Yusuf'),
            strpos($body, 'Bola Adeyemi'),
        );

        // The figures the sheet is for, not just the names.
        $response->assertSee('90%')->assertSee('Admitted')->assertSee('Deferred');
    }

    public function test_the_merit_list_says_so_when_nothing_has_been_computed(): void
    {
        $this->sitExam('Amaka', 'Obi', ['MTH' => 90, 'ENG' => 90]);

        $this->actingAs($this->admin)
            ->get(route('admin.admissions.merit', $this->exam))
            ->assertOk()
            ->assertSee('has not been computed');
    }

    public function test_the_merit_list_can_be_downloaded_for_excel(): void
    {
        $this->threeCandidatesWithTwoPlaces();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.admissions.merit.csv', $this->exam))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $this->assertStringContainsString(
            'attachment; filename="merit-list-entrance-examination-20262027.csv"',
            $response->headers->get('Content-Disposition'),
        );

        $csv = $response->getContent();

        // A byte-order mark, or Excel reads the accented names as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);

        $rows = array_map('str_getcsv', explode("\n", trim($csv)));

        // The mark rides on the first cell, so strip it before reading the header.
        $this->assertSame('Position', ltrim($rows[0][0], "\xEF\xBB\xBF"));
        $this->assertSame('Amaka Obi', $rows[1][2]);
        // Amaka 90, Bola 75, Zainab 60 — the same order the sheet shows.
        $this->assertSame('75.00', $rows[2][6]);
        $this->assertSame('Admitted', $rows[1][10]);
        $this->assertSame('Deferred', $rows[3][10]);
    }

    public function test_the_merit_list_is_closed_to_people_who_may_not_view_admissions(): void
    {
        $this->threeCandidatesWithTwoPlaces();

        $outsider = User::factory()->create();
        $outsider->assignRole('Teacher');

        $this->actingAs($outsider)
            ->get(route('admin.admissions.merit', $this->exam))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('admin.admissions.merit.csv', $this->exam))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* The admission letters */
    /* ------------------------------------------------------------------ */

    public function test_a_letter_is_written_for_every_admitted_candidate_and_no_one_else(): void
    {
        [$top, $second, $third] = $this->threeCandidatesWithTwoPlaces();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.admissions.letters', $this->exam))
            ->assertOk();

        $response->assertSee('Amaka Obi')->assertSee('Bola Adeyemi');
        // The third passed the cutoff but there was no place left for her.
        $response->assertDontSee('Zainab Yusuf');

        // The school's own letterhead and the number each child was given.
        $response->assertSee(Setting::get('school_name'));
        $response->assertSee($top->registration_number);

        $this->assertSame(
            AdmissionDecisionStatus::Deferred,
            $this->decisionFor($third)->decision,
        );
    }

    public function test_the_letters_page_says_so_before_anyone_is_admitted(): void
    {
        $this->sitExam('Amaka', 'Obi', ['MTH' => 20, 'ENG' => 20]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        $this->actingAs($this->admin)
            ->get(route('admin.admissions.letters', $this->exam))
            ->assertOk()
            ->assertSee('Nobody has been admitted');
    }

    /**
     * The signature the office uploads is printed on the letter, above the name it
     * belongs to — on the page the office prints and on the sheet of letters for a
     * whole examination.
     */
    public function test_an_admission_letter_carries_the_uploaded_signature(): void
    {
        [$top] = $this->threeCandidatesWithTwoPlaces();

        Setting::put('signature_image', 'branding/signature.png');
        Setting::flush();

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.letter', $top))
            ->assertOk()
            ->assertSee('storage/branding/signature.png', false);

        $this->actingAs($this->admin)
            ->get(route('admin.admissions.letters', $this->exam))
            ->assertOk()
            ->assertSee('storage/branding/signature.png', false);
    }

    /**
     * The PDF draws its own copy of the signature, inlined: DomPDF does not fetch an
     * image over HTTP, so a letter pointing at the site would have a broken picture
     * in the middle of it. A clean 200 is what proves it built.
     */
    public function test_the_letter_pdf_builds_with_a_signature_inlined(): void
    {
        [$top] = $this->threeCandidatesWithTwoPlaces();

        Storage::fake('public');
        Storage::disk('public')->put(
            'branding/signature.png',
            (string) UploadedFile::fake()->image('signature.png')->get(),
        );

        Setting::put('signature_image', 'branding/signature.png');
        Setting::flush();

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.letter.pdf', $top))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /** Nothing uploaded is not a gap on the page: the letter reads as it always did. */
    public function test_a_letter_with_no_signature_uploaded_reads_as_it_always_did(): void
    {
        [$top] = $this->threeCandidatesWithTwoPlaces();

        $this->assertNull(Setting::get('signature_image'));

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.letter', $top))
            ->assertOk()
            ->assertSee('Letter of Admission')
            ->assertSee('Principal')
            ->assertDontSee('<img', false);

        $this->actingAs($this->admin)
            ->get(route('admin.applicants.letter.pdf', $top))
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* The waiting list */
    /* ------------------------------------------------------------------ */

    public function test_the_waiting_list_lists_only_those_held_back_and_closest_to_the_line_first(): void
    {
        [, , $third] = $this->threeCandidatesWithTwoPlaces();

        $nearMiss = $this->sitExam('Chidi', 'Nwosu', ['MTH' => 70, 'ENG' => 70]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        // Chidi is above the cutoff but the two places are gone.
        $this->assertSame(AdmissionDecisionStatus::Deferred, $this->decisionFor($nearMiss)->decision);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.admissions.waiting', $this->exam))
            ->assertOk();

        $response->assertSee('Chidi Nwosu')->assertSee('Zainab Yusuf');
        // Somebody already admitted has no business on the waiting list.
        $response->assertDontSee('Amaka Obi');

        $body = $response->getContent();

        // 70 beats 60, so the near miss is first in line.
        $this->assertLessThan(
            strpos($body, 'Zainab Yusuf'),
            strpos($body, 'Chidi Nwosu'),
        );

        $this->assertSame(4, $this->decisionFor($third)->position);
    }

    /* ------------------------------------------------------------------ */
    /* Promoting from the waiting list */
    /* ------------------------------------------------------------------ */

    public function test_a_waiting_candidate_can_be_given_a_place_that_came_free(): void
    {
        [$top, , $third] = $this->threeCandidatesWithTwoPlaces();

        // One of the two admitted families never turns up, which frees a place.
        $this->admissions->override(
            $this->decisionFor($top),
            AdmissionDecisionStatus::Withdrawn,
            'Never reported.',
            $this->admin,
        );

        $response = $this->actingAs($this->admin)->post(
            route('admin.admissions.waiting.promote', $this->exam),
            ['decisions' => [$this->decisionFor($third)->id]],
        );

        $response->assertRedirect()->assertSessionHas('status');

        $decision = $this->decisionFor($third);

        $this->assertSame(AdmissionDecisionStatus::Admitted, $decision->decision);
        $this->assertSame('Promoted from the waiting list.', $decision->remarks);
        $this->assertFalse((bool) $decision->is_auto);
        $this->assertSame(ApplicantStatus::Admitted, $third->refresh()->status);
    }

    public function test_promotion_stops_at_the_number_of_places_that_are_free(): void
    {
        [, , $third] = $this->threeCandidatesWithTwoPlaces();

        $nearMiss = $this->sitExam('Chidi', 'Nwosu', ['MTH' => 70, 'ENG' => 70]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        // Both places are taken, so there is nothing to give away yet.
        $this->actingAs($this->admin)
            ->post(route('admin.admissions.waiting.promote', $this->exam), [
                'decisions' => [$this->decisionFor($third)->id, $this->decisionFor($nearMiss)->id],
            ])
            ->assertSessionHas('error');

        $this->assertSame(AdmissionDecisionStatus::Deferred, $this->decisionFor($third)->decision);
        $this->assertSame(AdmissionDecisionStatus::Deferred, $this->decisionFor($nearMiss)->decision);
    }

    public function test_a_candidate_who_was_never_on_the_waiting_list_cannot_be_promoted(): void
    {
        [$top, $second, $third] = $this->threeCandidatesWithTwoPlaces();

        $nearMiss = $this->sitExam('Chidi', 'Nwosu', ['MTH' => 70, 'ENG' => 70]);

        $this->admissions->compute($this->exam, $this->admin);
        $this->admissions->applyCutoff($this->exam, $this->admin);

        // A place comes free...
        $this->admissions->override(
            $this->decisionFor($top),
            AdmissionDecisionStatus::Withdrawn,
            'Never reported.',
            $this->admin,
        );

        // ...but the office has already turned Chidi away by hand, so the place
        // must stay theirs to give, not go to whoever the screen was pointed at.
        $rejected = $this->decisionFor($nearMiss);

        $this->admissions->override($rejected, AdmissionDecisionStatus::Rejected, 'Registered after the deadline.', $this->admin);

        $this->actingAs($this->admin)->post(
            route('admin.admissions.waiting.promote', $this->exam),
            ['decisions' => [$this->decisionFor($second)->id, $rejected->id]],
        );

        $this->assertSame(AdmissionDecisionStatus::Admitted, $this->decisionFor($second)->decision);
        $this->assertSame(AdmissionDecisionStatus::Rejected, $rejected->refresh()->decision);
        $this->assertSame('Registered after the deadline.', $rejected->remarks);
        $this->assertSame(AdmissionDecisionStatus::Deferred, $this->decisionFor($third)->decision);
    }

    public function test_promoting_nobody_is_refused_with_a_readable_message(): void
    {
        $this->threeCandidatesWithTwoPlaces();

        $this->actingAs($this->admin)
            ->post(route('admin.admissions.waiting.promote', $this->exam), ['decisions' => []])
            ->assertSessionHasErrors('decisions');
    }

    public function test_only_the_cutoff_desk_may_promote_from_the_waiting_list(): void
    {
        [, , $third] = $this->threeCandidatesWithTwoPlaces();

        $officer = User::factory()->create();
        $officer->assignRole('Admission Officer');

        $this->actingAs($officer)
            ->post(route('admin.admissions.waiting.promote', $this->exam), [
                'decisions' => [$this->decisionFor($third)->id],
            ])
            ->assertForbidden();

        $this->assertSame(AdmissionDecisionStatus::Deferred, $this->decisionFor($third)->decision);
    }

    /* ------------------------------------------------------------------ */
    /* The admit cards */
    /* ------------------------------------------------------------------ */

    public function test_an_admit_card_is_produced_for_every_candidate(): void
    {
        $this->threeCandidatesWithTwoPlaces();

        $response = $this->actingAs($this->admin)
            ->get(route('admin.exams.admit-cards', $this->exam))
            ->assertOk()
            ->assertSee('Admit card');

        // Every candidate who sat the paper, whether or not they got a place.
        $response->assertSee('Amaka Obi')
            ->assertSee('Bola Adeyemi')
            ->assertSee('Zainab Yusuf')
            ->assertSee('Admit cards');

        // No photograph on file, so the card falls back to the candidate's initials
        // rather than an empty space.
        $response->assertSee('AO');
    }

    public function test_the_admit_cards_say_so_when_nobody_is_registered(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.exams.admit-cards', $this->exam))
            ->assertOk()
            ->assertSee('No candidates registered');
    }

    public function test_every_admit_card_carries_the_school_crest(): void
    {
        $this->threeCandidatesWithTwoPlaces();

        Setting::query()->where('key', 'school_logo')->update(['value' => 'branding/crest.png']);
        Setting::flush();

        $content = $this->actingAs($this->admin)
            ->get(route('admin.exams.admit-cards', $this->exam))
            ->assertOk()
            ->getContent();

        // One crest per card, not one per sheet: the cards are cut apart at the
        // gate, and a card without the school's mark on it is not worth much.
        $this->assertSame(3, substr_count($content, 'storage/branding/crest.png'));
    }

    public function test_a_card_falls_back_to_the_school_monogram_before_a_crest_is_uploaded(): void
    {
        $this->threeCandidatesWithTwoPlaces();

        // "Saci Schools" in the seeded settings, so the monogram is SS. Matched on
        // the markup rather than the letters: JSS1 is on every card too.
        $this->actingAs($this->admin)
            ->get(route('admin.exams.admit-cards', $this->exam))
            ->assertOk()
            ->assertDontSee('storage/branding')
            ->assertSee('aria-hidden="true">SS</span>', false);
    }
}
