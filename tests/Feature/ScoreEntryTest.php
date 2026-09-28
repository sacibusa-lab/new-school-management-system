<?php

namespace Tests\Feature;

use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Enums\ImportDriver;
use App\Enums\ScoreImportRowStatus;
use App\Enums\ScoreImportStatus;
use App\Enums\ScoreSource;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\SchoolLevel;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\ScoreImportRow;
use App\Models\Subject;
use App\Models\User;
use App\Services\Import\ScoreImportService;
use App\Services\Scores\ScoreEntryService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marks are money in an admissions office: they decide who gets a place. These
 * tests pin down the rules that keep a mistyped number from quietly deciding a
 * child's future — what a shorthand means, when a change is refused outright,
 * and who is allowed to sign a mark off.
 */
class ScoreEntryTest extends TestCase
{
    use RefreshDatabase;

    /** Types marks and is allowed to sign them off. */
    private User $officer;

    /** Types marks only — their work waits for somebody else. */
    private User $clerk;

    private AcademicSession $session;

    private SchoolLevel $level;

    private Exam $exam;

    /** @var array<string,ExamSubject> */
    private array $papers = [];

    private Applicant $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'is_current' => true,
            'is_admission_open' => true,
        ]);

        $this->level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $this->exam = Exam::create([
            'title' => 'Entrance Examination',
            'academic_session_id' => $this->session->id,
            'level_id' => $this->level->id,
            'cutoff_mark' => 50,
            'status' => ExamStatus::Ongoing,
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

        $this->candidate = Applicant::create([
            'registration_number' => 'SAC-00001',
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
            'guardian_name' => 'Mrs. Ngozi Okafor',
            'guardian_phone' => '08031234567',
            'guardian_email' => 'ngozi@example.com',
            'level_applied_for_id' => $this->level->id,
            'academic_session_id' => $this->session->id,
            'status' => ApplicantStatus::Registered,
        ]);

        $this->officer = User::factory()->create();
        $this->officer->assignRole('Exam Officer');

        $this->clerk = User::factory()->create();
        $this->clerk->assignRole('Teacher');
    }

    /** A blank slot waiting for a mark, the way the exam candidate list creates one. */
    private function slot(string $paper = 'MTH', ?Applicant $applicant = null): Score
    {
        return Score::create([
            'exam_id' => $this->exam->id,
            'exam_subject_id' => $this->papers[$paper]->id,
            'applicant_id' => ($applicant ?? $this->candidate)->id,
        ]);
    }

    private function type(User $user, string $paper, Score $score, string $value)
    {
        return $this->actingAs($user)->post(
            route('admin.scores.store', [$this->exam, $this->papers[$paper]]),
            ['scores' => [$score->id => $value]],
        );
    }

    public function test_a_mark_is_saved_and_signed_off_by_someone_who_can_verify(): void
    {
        $score = $this->slot();

        $this->type($this->officer, 'MTH', $score, '65')->assertRedirect();

        $score->refresh();

        $this->assertSame('65.00', $score->score);
        $this->assertFalse($score->is_absent);
        $this->assertSame($this->officer->id, $score->verified_by);
        $this->assertNotNull($score->verified_at);
        $this->assertSame(ScoreSource::Manual, $score->source);

        // Sitting a paper is recorded, and the examination moves on.
        $this->assertSame(ApplicantStatus::ExamCompleted, $this->candidate->refresh()->status);
        $this->assertSame(ExamStatus::Marking, $this->exam->refresh()->status);
    }

    public function test_work_typed_by_somebody_without_the_verification_permission_waits(): void
    {
        $score = $this->slot();

        $this->type($this->clerk, 'MTH', $score, '53')->assertRedirect();

        $score->refresh();

        $this->assertSame('53.00', $score->score);
        $this->assertNull($score->verified_by, 'a clerk cannot sign off their own typing');
        $this->assertNull($score->verified_at);
    }

    public function test_a_shorthand_letter_marks_the_candidate_absent(): void
    {
        $score = $this->slot();

        // Office staff write a dash or an X on the sheet, not a ticked checkbox.
        $this->type($this->officer, 'MTH', $score, 'X')->assertRedirect();

        $score->refresh();

        $this->assertTrue($score->is_absent);
        $this->assertNull($score->score);
        $this->assertNull($score->grade);
    }

    public function test_the_absent_checkbox_on_the_entry_screen_means_the_same_thing(): void
    {
        $score = $this->slot();

        $this->actingAs($this->officer)->post(
            route('admin.scores.store', [$this->exam, $this->papers['MTH']]),
            ['absent' => [$score->id => '1']],
        )->assertRedirect();

        $this->assertTrue($score->refresh()->is_absent);
    }

    public function test_a_mark_over_the_paper_total_is_refused_and_nothing_at_all_is_written(): void
    {
        $good = $this->slot();
        $bad = $this->slot('ENG');

        $this->actingAs($this->officer)->post(
            route('admin.scores.store', [$this->exam, $this->papers['MTH']]),
            ['scores' => [$good->id => '60', $bad->id => '140']],
        )->assertSessionHasErrors('scores.' . $bad->id);

        // The screen writes a sheet as a whole, so one bad box holds up the rest.
        $this->assertNull($good->refresh()->score, 'the good mark must not slip through');
        $this->assertNull($bad->refresh()->score);
    }

    public function test_a_letter_that_is_not_a_shorthand_is_refused(): void
    {
        $score = $this->slot();

        $this->type($this->officer, 'MTH', $score, 'Z')->assertSessionHasErrors('scores.' . $score->id);

        $this->assertNull($score->refresh()->score);
    }

    public function test_a_blank_box_clears_a_mark_back_to_unmarked(): void
    {
        $score = $this->slot();
        $score->update(['score' => 40, 'is_absent' => false]);

        $this->type($this->officer, 'MTH', $score, '')->assertRedirect();

        $score->refresh();

        $this->assertNull($score->score);
        $this->assertFalse($score->is_absent, 'blank is not the same as absent');
    }

    public function test_re_saving_an_unchanged_sheet_does_not_touch_the_rows(): void
    {
        $score = $this->slot();

        $this->type($this->officer, 'MTH', $score, '70')->assertRedirect();

        $stamped = $score->refresh()->updated_at;

        $this->travel(2)->minutes();

        $response = $this->type($this->officer, 'MTH', $score, '70');

        $response->assertSessionHas('status', 'No changes to save.');
        $this->assertTrue(
            $stamped->equalTo($score->refresh()->updated_at),
            'an untouched row must not be re-stamped as if it had just been entered',
        );
    }

    public function test_a_clerk_cannot_change_a_mark_that_has_already_been_signed_off(): void
    {
        $score = $this->slot();
        $this->type($this->officer, 'MTH', $score, '70');

        $this->type($this->clerk, 'MTH', $score, '95')->assertSessionHasErrors('scores.' . $score->id);

        $this->assertSame('70.00', $score->refresh()->score, 'a signed-off mark stays put');
    }

    public function test_the_exam_officer_can_correct_a_signed_off_mark(): void
    {
        $score = $this->slot();
        $this->type($this->officer, 'MTH', $score, '70');

        $this->type($this->officer, 'MTH', $score, '95')->assertRedirect();

        $score->refresh();

        $this->assertSame('95.00', $score->score);
        $this->assertSame($this->officer->id, $score->verified_by);
    }

    public function test_the_grid_saves_every_paper_in_one_go(): void
    {
        $maths = $this->slot();
        $english = $this->slot('ENG');

        // The grid route must win over the "{exam}/{examSubject}" wildcard.
        $this->actingAs($this->officer)
            ->get(route('admin.scores.grid', $this->exam))
            ->assertOk();

        $this->actingAs($this->officer)->post(route('admin.scores.grid.store', $this->exam), [
            'scores' => [
                $maths->id => '72',
                $english->id => 'A',
            ],
        ])->assertRedirect();

        $this->assertSame('72.00', $maths->refresh()->score);
        $this->assertTrue($english->refresh()->is_absent);
    }

    public function test_the_grid_refuses_a_whole_sheet_when_one_box_is_wrong(): void
    {
        $maths = $this->slot();
        $english = $this->slot('ENG');

        $this->actingAs($this->officer)
            ->post(route('admin.scores.grid.store', $this->exam), [
                'scores' => [
                    $maths->id => '72',
                    $english->id => 'nonsense',
                ],
            ])
            ->assertSessionHasErrors('scores.' . $english->id);

        $this->assertNull($maths->refresh()->score);
    }

    public function test_a_locked_examination_takes_no_more_marks(): void
    {
        $score = $this->slot();

        $this->exam->update(['results_locked' => true]);

        $this->type($this->officer, 'MTH', $score, '60')->assertSessionHas('error');

        $this->assertNull($score->refresh()->score);
    }

    public function test_only_marks_that_a_machine_read_are_queued_for_verification(): void
    {
        $typed = $this->slot();
        $typed->update(['score' => 55, 'source' => ScoreSource::Manual, 'verified_by' => $this->officer->id, 'verified_at' => now()]);

        $machine = $this->slot('ENG');
        $machine->update(['score' => 61, 'source' => ScoreSource::Ocr, 'confidence' => 0.62]);

        $blank = Score::create([
            'exam_id' => $this->exam->id,
            'exam_subject_id' => $this->papers['MTH']->id,
            'applicant_id' => Applicant::create([
                'registration_number' => 'SAC-00002',
                'first_name' => 'Aisha',
                'last_name' => 'Bello',
                'guardian_phone' => '08031234567',
                'guardian_email' => 'bello@example.com',
                'level_applied_for_id' => $this->level->id,
                'academic_session_id' => $this->session->id,
                'status' => ApplicantStatus::Registered,
            ])->id,
            'source' => ScoreSource::Ocr,
        ]);

        $awaiting = app(ScoreEntryService::class)->awaitingVerification($this->exam);

        $this->assertCount(1, $awaiting, 'only the machine read with a number on it is waiting');
        $this->assertSame($machine->id, $awaiting->first()->id);

        // An unmarked slot is "not marked yet", which is not the same as verified.
        $this->assertFalse($awaiting->contains('id', $blank->id));
        $this->assertFalse($awaiting->contains('id', $typed->id));
    }

    public function test_signing_off_a_machine_read_mark_stamps_the_verifier(): void
    {
        $score = $this->slot();
        $score->update(['score' => 61, 'source' => ScoreSource::AiVision, 'confidence' => 0.55]);

        $this->actingAs($this->officer)
            ->post(route('admin.scores.verify.store', $this->exam), ['verify' => [$score->id => '1']])
            ->assertRedirect();

        $score->refresh();

        $this->assertSame($this->officer->id, $score->verified_by);
        $this->assertNotNull($score->verified_at);
        $this->assertSame(0, app(ScoreEntryService::class)->awaitingCount($this->exam));
    }

    public function test_verify_all_clears_one_paper_without_touching_the_other(): void
    {
        $maths = $this->slot();
        $maths->update(['score' => 61, 'source' => ScoreSource::Ocr]);

        $other = Applicant::create([
            'registration_number' => 'SAC-00003',
            'first_name' => 'Aisha',
            'last_name' => 'Bello',
            'guardian_phone' => '08031234567',
            'guardian_email' => 'bello@example.com',
            'level_applied_for_id' => $this->level->id,
            'academic_session_id' => $this->session->id,
            'status' => ApplicantStatus::Registered,
        ]);

        $english = $this->slot('ENG', $other);
        $english->update(['score' => 44, 'source' => ScoreSource::Ocr]);

        $this->actingAs($this->officer)
            ->post(route('admin.scores.verify.store', $this->exam), [
                'exam_subject_id' => $this->papers['MTH']->id,
                'verify_all' => '1',
            ])
            ->assertRedirect();

        $this->assertNotNull($maths->refresh()->verified_at, 'the chosen paper is signed off');

        // The English sheet has not been looked at, so it stays in the queue.
        $this->assertNull($english->refresh()->verified_at);
        $this->assertSame(1, app(ScoreEntryService::class)->awaitingCount($this->exam));
    }

    public function test_a_typed_correction_is_saved_before_the_mark_is_signed_off(): void
    {
        $score = $this->slot();
        $score->update(['score' => 19, 'source' => ScoreSource::Ocr, 'confidence' => 0.40]);

        // OCR misread a 4 as a 1; the officer fixes it and accepts the row together.
        $this->actingAs($this->officer)->post(route('admin.scores.verify.store', $this->exam), [
            'scores' => [$score->id => '49'],
            'verify' => [$score->id => '1'],
        ])->assertRedirect();

        $score->refresh();

        $this->assertSame('49.00', $score->score, 'the correction is what gets verified');
        $this->assertNotNull($score->verified_at);
    }

    public function test_a_verification_can_be_sent_back_to_the_queue(): void
    {
        $score = $this->slot();
        $score->update(['score' => 61, 'source' => ScoreSource::Ocr, 'verified_by' => $this->officer->id, 'verified_at' => now()]);

        $this->actingAs($this->officer)
            ->post(route('admin.scores.verify.store', $this->exam), ['unverify' => [$score->id => '1']])
            ->assertRedirect();

        $score->refresh();

        $this->assertNull($score->verified_at);
        $this->assertNull($score->verified_by);
        $this->assertSame(1, app(ScoreEntryService::class)->awaitingCount($this->exam));

        // And it is back on the screen, so nobody has to remember it exists.
        $this->actingAs($this->officer)
            ->get(route('admin.scores.verify', $this->exam))
            ->assertOk()
            ->assertSee('Okafor');
    }

    public function test_somebody_who_cannot_verify_cannot_open_the_verification_desk(): void
    {
        $this->actingAs($this->clerk)->get(route('admin.scores.verify', $this->exam))->assertForbidden();
        $this->actingAs($this->clerk)->post(route('admin.scores.verify.store', $this->exam), [])->assertForbidden();
    }

    public function test_the_verification_desk_says_so_when_there_is_nothing_to_do(): void
    {
        $this->actingAs($this->officer)
            ->get(route('admin.scores.verify', $this->exam))
            ->assertOk()
            ->assertSee('Nothing is waiting to be verified');
    }

    public function test_the_verification_desk_shows_both_what_is_waiting_and_what_it_signed_off(): void
    {
        $signed = $this->slot();
        $signed->update([
            'score' => 70,
            'source' => ScoreSource::Ocr,
            'verified_by' => $this->officer->id,
            'verified_at' => now(),
        ]);

        $waiting = $this->slot('ENG');
        $waiting->update(['score' => 61, 'source' => ScoreSource::AiVision, 'confidence' => 0.55]);

        $response = $this->actingAs($this->officer)
            ->get(route('admin.scores.verify', $this->exam))
            ->assertOk()
            ->assertSee('Okafor')
            ->assertSee('61.00');

        // The shaky read is worth a second look, so it is called out.
        $response->assertSee('55% sure');

        // And a signature that turns out to be wrong can be taken back.
        $response->assertSee('Send back');
        $response->assertSee('signed off by');
    }

    /**
     * A sheet somebody typed by hand has already been through a person, so the
     * import desk's row-by-row review is the check.
     */
    public function test_a_committed_spreadsheet_counts_as_the_human_check(): void
    {
        $score = $this->commitImport(ImportDriver::Spreadsheet, '58');

        $this->assertSame($this->officer->id, $score->verified_by);
        $this->assertNotNull($score->verified_at);
    }

    /** A sheet read off a photograph is a guess, however tidy it looks. */
    public function test_a_machine_read_import_waits_to_be_verified(): void
    {
        $score = $this->commitImport(ImportDriver::AiVision, '58');

        $this->assertNull($score->verified_by, 'nobody has agreed with the machine yet');
        $this->assertNull($score->verified_at);
        $this->assertSame($this->officer->id, $score->entered_by, 'but we know who committed it');
        $this->assertSame(ScoreSource::AiVision, $score->source);
        $this->assertSame(1, app(ScoreEntryService::class)->awaitingCount($this->exam));
    }

    /** Push one reviewed row through the import desk and return the score it wrote. */
    private function commitImport(ImportDriver $driver, string $value): Score
    {
        $import = ScoreImport::create([
            'exam_id' => $this->exam->id,
            'original_name' => 'marks.' . $driver->value . '.csv',
            'file_path' => 'imports/marks.csv',
            'mime_type' => 'text/csv',
            'file_size' => 128,
            'driver' => $driver,
            'status' => ScoreImportStatus::NeedsReview,
            'rows_total' => 1,
            'rows_matched' => 1,
            'rows_unmatched' => 0,
            'uploaded_by' => $this->officer->id,
        ]);

        ScoreImportRow::create([
            'score_import_id' => $import->id,
            'row_number' => 1,
            'raw' => ['registration' => 'SAC-00001', 'score' => $value],
            'raw_identifier' => 'SAC-00001',
            'raw_name' => 'Chidera Okafor',
            'raw_score' => $value,
            'exam_subject_id' => $this->papers['MTH']->id,
            'matched_applicant_id' => $this->candidate->id,
            'status' => ScoreImportRowStatus::Matched,
            'confidence' => $driver->isImageBased() ? 0.71 : null,
        ]);

        app(ScoreImportService::class)->commit($import, $this->officer);

        return Score::query()
            ->where('exam_id', $this->exam->id)
            ->where('exam_subject_id', $this->papers['MTH']->id)
            ->firstOrFail();
    }
}
