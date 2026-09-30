<?php

namespace Tests\Feature;

use App\Enums\AdmissionDecisionStatus;
use App\Models\AcademicSession;
use App\Models\AdmissionDecision;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the office goes to print.
 *
 * Every one of these documents already existed, but on whatever desk produces it:
 * the merit list and the letters on the cutoff page, the admit cards on the
 * examination, the applicant list on its own page. So the question "where do I
 * print the admission list" had no answer short of knowing how this program is
 * put together.
 *
 * What matters here is that the page gathers them and that it offers nobody a
 * document they cannot open: a link that answers 403 is worse than no link.
 */
class PrintingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->session = AcademicSession::create(['name' => '2027/2028', 'is_current' => true]);

        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $this->exam = Exam::create([
            'title' => 'Entrance Examination 2027',
            'level_id' => $level->id,
            'academic_session_id' => $this->session->id,
            'exam_date' => now()->addWeek(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* The page */
    /* ------------------------------------------------------------------ */

    public function test_the_printing_page_gathers_every_document(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.admissions.printing'))
            ->assertOk()
            ->assertSee('The applicants')
            ->assertSee('For an examination')
            ->assertSee('Merit list')
            ->assertSee('Admission letters')
            ->assertSee('Waiting list')
            ->assertSee('Admit cards');
    }

    /** Each one opens the page that already owns it — nothing is printed from here. */
    public function test_it_links_to_the_pages_that_hold_the_documents(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.admissions.printing'))
            ->assertOk();

        $response->assertSee(route('admin.applicants.index'), false)
            ->assertSee(route('admin.admissions.merit', $this->exam), false)
            ->assertSee(route('admin.admissions.merit.csv', $this->exam), false)
            ->assertSee(route('admin.admissions.letters', $this->exam), false)
            ->assertSee(route('admin.admissions.waiting', $this->exam), false)
            ->assertSee(route('admin.exams.admit-cards', $this->exam), false);
    }

    /** The office is told how much paper it is about to use. */
    public function test_it_says_how_many_of_each_there_are(): void
    {
        $this->decision('Chidera', AdmissionDecisionStatus::Admitted);
        $this->decision('Ngozi', AdmissionDecisionStatus::Admitted);
        $this->decision('Emeka', AdmissionDecisionStatus::Deferred);

        $this->actingAs($this->admin)
            ->get(route('admin.admissions.printing'))
            ->assertOk()
            ->assertSee('2</span> admitted', false)
            ->assertSee('1</span> on the waiting list', false);
    }

    /** One examination at a time, and the documents follow the one chosen. */
    public function test_another_examination_can_be_chosen(): void
    {
        $other = Exam::create([
            'title' => 'Entrance Examination 2026',
            'level_id' => $this->exam->level_id,
            'academic_session_id' => $this->session->id,
            'exam_date' => now()->subYear(),
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.admissions.printing', ['exam' => $other->id]))
            ->assertOk()
            ->assertSee(route('admin.admissions.merit', $other), false)
            ->assertDontSee(route('admin.admissions.merit', $this->exam), false);
    }

    /** No examination yet is a state to explain, not a page of dead links. */
    public function test_it_says_so_when_there_is_no_examination_to_print_for(): void
    {
        Exam::query()->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.admissions.printing'))
            ->assertOk()
            ->assertSee('No examination this session yet')
            ->assertSee('Needs an examination')
            // The lists that do not need one are still there.
            ->assertSee('Open the list');
    }

    /* ------------------------------------------------------------------ */
    /* Who may print what */
    /* ------------------------------------------------------------------ */

    public function test_the_page_is_closed_to_a_role_without_the_permission(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->get(route('admin.admissions.printing'))
            ->assertForbidden();
    }

    /**
     * A document the office cannot open is not offered to them: an admissions
     * officer holds the letters but not the cutoff desk, and the merit list is
     * theirs all the same — while the letters are not, for a role without them.
     */
    public function test_only_the_documents_the_office_may_open_are_offered(): void
    {
        $bursar = User::factory()->create();
        $bursar->assignRole('Bursar / Accounts');

        // The Bursar may see admissions, and has no letters permission.
        $this->assertTrue($bursar->can('admissions.view'));
        $this->assertFalse($bursar->can('admissions.letters'));

        $response = $this->actingAs($bursar)
            ->get(route('admin.admissions.printing'))
            ->assertOk();

        $response->assertSee(route('admin.admissions.merit', $this->exam), false)
            ->assertDontSee(route('admin.admissions.letters', $this->exam), false)
            ->assertDontSee('Admission letters');
    }

    /* ------------------------------------------------------------------ */

    private function decision(string $firstName, AdmissionDecisionStatus $decision): void
    {
        $applicant = Applicant::create([
            'registration_number' => 'SAC-'.str_pad((string) (Applicant::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'first_name' => $firstName,
            'last_name' => 'Okafor',
            'level_applied_for_id' => $this->exam->level_id,
            'academic_session_id' => $this->session->id,
            'status' => 'registered',
        ]);

        AdmissionDecision::create([
            'applicant_id' => $applicant->id,
            'exam_id' => $this->exam->id,
            'decision' => $decision,
            'average_score' => 70,
            'cutoff_mark' => 50,
        ]);
    }
}
