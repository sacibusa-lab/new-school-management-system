<?php

namespace Tests\Feature;

use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Enums\ScoreImportRowStatus;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\SchoolLevel;
use App\Models\Score;
use App\Models\Subject;
use App\Models\User;
use App\Services\Import\ScoreImportService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A scoresheet is a school's own document, and every school lays it out
 * differently. What matters is that the paper a mark belongs to is never guessed:
 * a mark filed against the wrong paper is worse than a mark that was never read,
 * because it looks correct.
 */
class ScoresheetImportTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private AcademicSession $session;

    private SchoolLevel $level;

    private Exam $exam;

    /** @var array<string,ExamSubject> */
    private array $papers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        Storage::fake('local');

        $this->officer = User::factory()->create();
        $this->officer->assignRole('Exam Officer');

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

        $this->candidate('SAC-00001', 'Chidera', 'Okafor');
        $this->candidate('SAC-00002', 'Aisha', 'Bello');
    }

    private function candidate(string $number, string $first, string $last): Applicant
    {
        $applicant = Applicant::create([
            'registration_number' => $number,
            'first_name' => $first,
            'last_name' => $last,
            'guardian_name' => 'Mrs. ' . $last,
            'guardian_phone' => '08031234567',
            'guardian_email' => strtolower($last) . '@example.com',
            'level_applied_for_id' => $this->level->id,
            'academic_session_id' => $this->session->id,
            'status' => ApplicantStatus::Registered,
        ]);

        // Registering candidates is what creates the blank score slot for each paper.
        foreach ($this->papers as $paper) {
            Score::create([
                'exam_id' => $this->exam->id,
                'exam_subject_id' => $paper->id,
                'applicant_id' => $applicant->id,
            ]);
        }

        return $applicant;
    }

    /** Push a sheet through the real upload and read path. */
    private function read(string $csv, string $name = 'marks.csv', ?ExamSubject $paper = null)
    {
        $import = app(ScoreImportService::class)->store(
            $this->exam,
            $paper,
            UploadedFile::fake()->createWithContent($name, $csv),
            $this->officer,
        );

        return app(ScoreImportService::class)->process($import);
    }

    public function test_a_sheet_with_one_column_per_paper_is_read_as_one_row_per_mark(): void
    {
        $import = $this->read(
            "Registration number,Name,Mathematics,English Language,General Paper\n"
            . "SAC-00001,Chidera Okafor,78,65,82\n"
            . "SAC-00002,Aisha Bello,41,55,38\n",
        );

        $this->assertSame(6, $import->rows_total, 'two candidates across three papers');

        $rows = $import->rows()->get();

        $this->assertTrue(
            $rows->every(fn ($row) => $row->status === ScoreImportRowStatus::Matched),
            'every mark should match a candidate: ' . $rows->pluck('message')->implode(' | '),
        );

        // Each mark is filed against the paper whose column it came from.
        $chidera = Applicant::query()->where('registration_number', 'SAC-00001')->sole();

        $filed = $rows
            ->where('matched_applicant_id', $chidera->id)
            ->mapWithKeys(fn ($row) => [$row->examSubject->subject->name => (float) $row->raw_score]);

        $this->assertSame(['Mathematics' => 78.0, 'English Language' => 65.0, 'General Paper' => 82.0], $filed->all());
    }

    public function test_a_blank_cell_means_the_paper_is_unmarked_and_is_not_read_as_an_absence(): void
    {
        // General Paper has not been marked for either candidate. Reading those
        // blanks would record both of them absent for a paper nobody has looked at.
        $import = $this->read(
            "Registration number,Mathematics,English Language,General Paper\n"
            . "SAC-00001,78,65,\n"
            . "SAC-00002,41,55,\n",
        );

        $this->assertSame(4, $import->rows_total, 'only the four marked cells become rows');

        $this->assertSame(
            [],
            $import->rows()->get()
                ->filter(fn ($row) => $row->examSubject->subject->code === 'GPR')
                ->pluck('raw_score')
                ->all(),
            'no General Paper row should exist at all',
        );
    }

    public function test_a_column_named_after_no_paper_on_the_examination_is_reported_not_dropped(): void
    {
        $import = $this->read(
            "Registration number,Mathematics,Further Mathematics\n"
            . "SAC-00001,78,60\n",
        );

        // The paper we know is read...
        $this->assertSame(1, $import->rows_total);
        $this->assertSame('78.00', $import->rows()->sole()->raw_score);

        // ...and the column we could not place is named out loud, because those
        // marks are NOT in the system and the office has to be told.
        $this->assertContains('Further Mathematics', $import->refresh()->meta['unread_headings'] ?? []);

        $this->actingAs($this->officer)
            ->get(route('admin.imports.show', $import))
            ->assertOk()
            ->assertSee('Some columns were not read')
            ->assertSee('Further Mathematics');
    }

    /**
     * "Further Mathematics" describes a different paper from "Mathematics", and a
     * mark filed under the wrong paper still looks right. So the reader refuses to
     * guess rather than folding one into the other.
     */
    public function test_a_paper_whose_name_merely_contains_another_is_not_confused_with_it(): void
    {
        $further = Subject::create(['name' => 'Further Mathematics', 'code' => 'FMT', 'is_active' => true]);

        $paper = ExamSubject::create([
            'exam_id' => $this->exam->id,
            'subject_id' => $further->id,
            'total_marks' => 100,
            'pass_mark' => 40,
            'sort_order' => 3,
        ]);

        $import = $this->read(
            "Registration number,Mathematics,Further Mathematics\n"
            . "SAC-00001,78,60\n",
        );

        $rows = $import->rows()->get();

        $this->assertSame(2, $rows->count());

        $filed = $rows->mapWithKeys(fn ($row) => [$row->examSubject->subject->code => (float) $row->raw_score]);

        $this->assertSame(['MTH' => 78.0, 'FMT' => 60.0], $filed->all());
        $this->assertSame([], $import->meta['unread_headings'] ?? []);
    }

    public function test_headings_that_abbreviate_or_add_noise_still_name_the_paper(): void
    {
        // The three ways a school writes the same paper: the full name, the code,
        // and a shorter word that can only mean one thing.
        $import = $this->read(
            "Registration number,Maths,Eng,General Paper Score\n"
            . "SAC-00001,78,66,54\n",
        );

        $this->assertSame([], $import->meta['unread_headings'] ?? [], 'all three should be understood');

        $filed = $import->rows()->get()
            ->mapWithKeys(fn ($row) => [$row->examSubject->subject->code => (float) $row->raw_score]);

        $this->assertSame(['MTH' => 78.0, 'ENG' => 66.0, 'GPR' => 54.0], $filed->all());
    }

    public function test_only_the_first_of_two_columns_naming_the_same_paper_is_read(): void
    {
        // Two columns for one paper is a sheet problem, not two sets of marks.
        // Guessing between them is the one thing that must not happen.
        $import = $this->read(
            "Registration number,Mathematics,MTH\n"
            . "SAC-00001,78,60\n",
        );

        $rows = $import->rows()->get();

        $this->assertSame(1, $rows->count(), 'the first column wins');
        $this->assertSame(78.0, (float) $rows->first()->raw_score);

        $this->assertContains('MTH', $import->refresh()->meta['unread_headings'] ?? []);
    }

    public function test_the_single_subject_sheet_still_reads_as_before(): void
    {
        // The old shape: one paper, a Score column, no subject named anywhere.
        $import = $this->read(
            "Reg No,Name,Score\n"
            . "SAC-00001,Chidera Okafor,64\n",
            paper: $this->papers['MTH'],
        );

        $this->assertSame(1, $import->rows_total);

        $row = $import->rows()->sole();

        $this->assertSame(ScoreImportRowStatus::Matched, $row->status);
        $this->assertSame($this->papers['MTH']->id, $row->exam_subject_id);
        $this->assertSame('64.00', $row->raw_score);
    }

    public function test_a_long_sheet_with_its_own_subject_column_still_reads(): void
    {
        // One mark per line, with the paper named in a column of its own.
        $import = $this->read(
            "Registration number,Subject,Score\n"
            . "SAC-00001,Mathematics,71\n"
            . "SAC-00001,English Language,58\n"
            . "SAC-00002,Mathematics,49\n",
        );

        $this->assertSame(3, $import->rows_total);

        foreach ($import->rows()->get() as $row) {
            $this->assertSame(ScoreImportRowStatus::Matched, $row->status, (string) $row->message);
        }

        $this->assertSame(
            ['Mathematics', 'English Language', 'Mathematics'],
            $import->rows()->get()->map(fn ($row) => $row->examSubject->subject->name)->all(),
        );
    }

    public function test_committing_a_sheet_writes_each_mark_to_its_own_paper(): void
    {
        $import = $this->read(
            "Registration number,Mathematics,English Language,General Paper\n"
            . "SAC-00001,78,65,82\n",
        );

        app(ScoreImportService::class)->commit($import, $this->officer);

        $chidera = Applicant::query()->where('registration_number', 'SAC-00001')->sole();

        $written = Score::query()
            ->where('exam_id', $this->exam->id)
            ->where('applicant_id', $chidera->id)
            ->with('examSubject.subject')
            ->get()
            ->mapWithKeys(fn (Score $score) => [$score->examSubject->subject->name => (float) $score->score])
            ->all();

        $this->assertSame(['Mathematics' => 78.0, 'English Language' => 65.0, 'General Paper' => 82.0], $written);
    }

    public function test_a_mark_over_the_papers_total_is_refused(): void
    {
        $this->papers['MTH']->update(['total_marks' => 50]);

        $import = $this->read(
            "Registration number,Mathematics\n"
            . "SAC-00001,78\n",
        );

        $row = $import->rows()->sole();

        $this->assertSame(ScoreImportRowStatus::Invalid, $row->status);
        $this->assertStringContainsString('outside the allowed range', (string) $row->message);
    }

    public function test_the_upload_screen_renders_and_scopes_the_subject_list_to_the_examination(): void
    {
        // Worth a test of its own: nothing else touches this page, so a bad class
        // reference here would only ever show up as a 500 in the browser.
        $this->actingAs($this->officer)
            ->get(route('admin.imports.index'))
            ->assertOk()
            ->assertSee('A sheet can carry every paper at once');

        // Without an examination chosen, every paper is listed under its own
        // examination's name rather than as a bare name from nowhere.
        $response = $this->actingAs($this->officer)
            ->get(route('admin.imports.index', ['exam' => $this->exam->id]))
            ->assertOk();

        $response->assertSee('Mathematics');

        $options = $this->subjectOptionLabels($response->getContent());

        $this->assertContains('Mathematics', $options);
        $this->assertNotContains('Entrance Examination 2026/2027 — Mathematics', $options);
    }

    public function test_the_subject_list_offers_no_paper_from_another_examination(): void
    {
        $other = Exam::create([
            'title' => 'Second Sitting',
            'academic_session_id' => $this->session->id,
            'level_id' => $this->level->id,
            'status' => ExamStatus::Scheduled,
        ]);

        $subject = Subject::create(['name' => 'Further Mathematics', 'code' => 'FMT', 'is_active' => true]);

        ExamSubject::create([
            'exam_id' => $other->id,
            'subject_id' => $subject->id,
            'total_marks' => 100,
            'pass_mark' => 40,
        ]);

        $response = $this->actingAs($this->officer)
            ->get(route('admin.imports.index', ['exam' => $this->exam->id]))
            ->assertOk();

        // A paper from the other examination is not something you can attach to
        // this one, so it must not be offered.
        $this->assertNotContains('Further Mathematics', $this->subjectOptionLabels($response->getContent()));
    }

    /** @return array<int,string> */
    private function subjectOptionLabels(string $html): array
    {
        preg_match_all(
            '/<select[^>]*name="exam_subject_id".*?<\/select>/s',
            $html,
            $selects,
        );

        if ($selects[0] === []) {
            return [];
        }

        preg_match_all('/<option[^>]*>(.*?)<\/option>/s', $selects[0][0], $options);

        return array_map(fn (string $label) => trim(html_entity_decode($label)), $options[1]);
    }

    public function test_the_scoresheet_template_has_a_column_per_paper_and_lists_the_candidates(): void
    {
        $response = $this->actingAs($this->officer)->get(route('admin.exams.scoresheet-template', $this->exam));

        $response->assertOk();

        $csv = $response->getContent();

        $this->assertStringContainsString('Registration number', $csv);
        $this->assertStringContainsString('Mathematics', $csv);
        $this->assertStringContainsString('English Language', $csv);
        $this->assertStringContainsString('General Paper', $csv);

        // Filled in for the office, so the numbers are not keyed in twice.
        $this->assertStringContainsString('SAC-00001', $csv);
        $this->assertStringContainsString('Chidera Okafor', $csv);
        $this->assertStringContainsString('SAC-00002', $csv);

        $this->assertStringContainsString('scoresheet-', (string) $response->headers->get('content-disposition'));
    }

    public function test_the_template_round_trips_back_through_the_reader(): void
    {
        // Download it, fill in the marks, upload it again: that is the workflow,
        // so it must not be a shape the reader cannot read.
        $csv = $this->actingAs($this->officer)
            ->get(route('admin.exams.scoresheet-template', $this->exam))
            ->getContent();

        $lines = explode("\n", trim($csv));
        $header = str_getcsv($lines[0]);

        // Drop the byte-order mark, then write a mark into every paper column.
        $header[0] = trim($header[0], "\xEF\xBB\xBF");

        $filled = [implode(',', $header)];

        foreach (['SAC-00001', 'SAC-00002'] as $i => $number) {
            $values = [$number, 'Candidate ' . $i];

            foreach (array_slice($header, 2) as $paper) {
                $values[] = $paper === 'Mathematics' ? '78' : '';
            }

            $filled[] = implode(',', $values);
        }

        $import = $this->read(implode("\n", $filled) . "\n", 'filled-in.csv');

        // Only the Mathematics column carries marks; the blank papers produce
        // nothing at all rather than an absence.
        $this->assertSame(2, $import->rows_total);
        $this->assertTrue($import->rows()->get()->every(
            fn ($row) => $row->status === ScoreImportRowStatus::Matched && $row->examSubject->subject->code === 'MTH',
        ));
    }
}
