<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Score;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\User;
use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Enums\ScoreSource;
use App\Models\AcademicSession;
use App\Models\SchoolLevel;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Light and dark.
 *
 * The theme is applied by an inline script in the head, before anything paints,
 * and nothing in the framework will notice if a layout forgets it — the page still
 * works, it just flashes white on every navigation for somebody who chose dark.
 * So the presence of that script and of the switch is what these tests hold.
 *
 * The documents are the other half: a merit list is paper, and paper is white,
 * whatever the screen is doing.
 */
class ThemeToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    private function examWithACandidate(): Exam
    {
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $exam = Exam::create([
            'title' => 'Entrance Examination 2026/2027',
            'academic_session_id' => $session->id,
            'level_id' => $level->id,
            'cutoff_mark' => 50,
            'status' => ExamStatus::Marking,
        ]);

        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);

        $paper = ExamSubject::create([
            'exam_id' => $exam->id,
            'subject_id' => $subject->id,
            'total_marks' => 100,
            'pass_mark' => 40,
            'sort_order' => 1,
        ]);

        $applicant = Applicant::create([
            'registration_number' => 'SAC-00001',
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
            'guardian_phone' => '08031234567',
            'level_applied_for_id' => $level->id,
            'academic_session_id' => $session->id,
            'status' => ApplicantStatus::Registered,
        ]);

        Score::create([
            'exam_id' => $exam->id,
            'exam_subject_id' => $paper->id,
            'applicant_id' => $applicant->id,
            'score' => 80,
            'source' => ScoreSource::Manual,
        ]);

        return $exam;
    }

    /* ------------------------------------------------------------------ */
    /* Every screen that is themed carries the plumbing                    */
    /* ------------------------------------------------------------------ */

    public function test_the_admin_console_can_switch_theme_without_flashing(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('saci-theme', $html, 'The pre-paint script is missing: the page would flash light mode.');
        $this->assertStringContainsString('Switch to dark mode', $html, 'The switch is missing from the admin console.');
    }

    public function test_the_public_site_can_switch_theme_without_flashing(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();

        $this->assertStringContainsString('saci-theme', $html);
        $this->assertStringContainsString('Switch to dark mode', $html);
    }

    public function test_the_sign_in_page_can_switch_theme_without_flashing(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('saci-theme', $html);
    }

    /**
     * The script has to run before the stylesheet settles, or the light page shows
     * for a frame. In the head, and inline: a bundled script runs too late.
     */
    public function test_the_theme_script_runs_in_the_head_and_not_from_the_bundle(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $head = substr($html, 0, strpos($html, '</head>'));

        $this->assertStringContainsString('saci-theme', $head);
    }

    /* ------------------------------------------------------------------ */
    /* The documents stay paper                                            */
    /* ------------------------------------------------------------------ */

    public function test_the_printed_documents_are_not_themed_at_all(): void
    {
        $exam = $this->examWithACandidate();

        foreach ([
            route('admin.admissions.merit', $exam),
            route('admin.admissions.letters', $exam),
            route('admin.exams.admit-cards', $exam),
        ] as $url) {
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString(
                'saci-theme',
                $html,
                "{$url} is themed. A document should look like a document, on paper and on screen.",
            );
        }
    }
}
