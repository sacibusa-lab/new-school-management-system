<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Assessment;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\Academics\AcademicCalendarService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adding and deleting sessions and terms.
 *
 * Adding is the easy half. The half that needs guarding is deletion, because every
 * foreign key pointing at a session or a term is ON DELETE CASCADE: applicants,
 * students, examinations, invoices, assessments and results all vanish silently
 * with the session they belong to. A stray "2027/2028" created by accident is
 * worth deleting; a session holding a hundred children is emphatically not.
 *
 * So the tests below are mostly about refusal, and about the refusal naming what
 * is in the way rather than saying "no".
 */
class AcademicCalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $thisYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->thisYear = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
        ]);

        foreach ([1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'] as $position => $name) {
            $this->thisYear->terms()->create([
                'name' => $name,
                'position' => $position,
                'is_current' => $position === 1,
            ]);
        }
    }

    private function addSession(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(
            route('admin.settings.academic.sessions.store'),
            array_merge(['name' => '2027/2028', 'with_terms' => '1'], $overrides),
        );
    }

    private function addTerm(AcademicSession $session, string $name, array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(
            route('admin.settings.academic.terms.store'),
            array_merge(['academic_session_id' => $session->id, 'name' => $name], $overrides),
        );
    }

    private function term(AcademicSession $session, string $name): Term
    {
        return $session->terms()->where('name', $name)->sole();
    }

    private function student(AcademicSession $session): Student
    {
        $number = $session->students()->count() + 1;

        return Student::create([
            'academic_session_id' => $session->id,
            'student_number' => 'SAC/' . $session->startYear() . '/' . str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'admission_number' => 'SAC-' . str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Adding a session                                                    */
    /* ------------------------------------------------------------------ */

    public function test_a_session_can_be_added_and_comes_with_its_three_terms(): void
    {
        $this->addSession()->assertRedirect()->assertSessionHas('status');

        $session = AcademicSession::query()->where('name', '2027/2028')->sole();

        $this->assertSame(
            ['First Term', 'Second Term', 'Third Term'],
            $session->terms()->orderBy('position')->pluck('name')->all(),
        );
    }

    /** Moving the school is a separate, deliberate act. */
    public function test_a_new_session_does_not_become_the_session_the_school_is_in(): void
    {
        $this->addSession();

        $this->assertFalse(AcademicSession::query()->where('name', '2027/2028')->sole()->is_current);
        $this->assertTrue($this->thisYear->refresh()->is_current);
    }

    public function test_the_terms_can_be_left_out_when_a_session_is_added(): void
    {
        $this->addSession(['with_terms' => null]);

        $this->assertSame(0, AcademicSession::query()->where('name', '2027/2028')->sole()->terms()->count());
    }

    public function test_a_session_name_is_stored_in_one_shape(): void
    {
        $this->addSession(['name' => '2027 / 2028']);

        $this->assertSame('2027/2028', AcademicSession::query()->where('name', '2027/2028')->sole()->name);
    }

    public function test_the_same_session_cannot_be_added_twice_however_it_is_spaced(): void
    {
        $this->addSession(['name' => ' 2027 / 2028 ']);
        $this->addSession(['name' => '2027/2028'])->assertSessionHasErrors('name');

        $this->assertSame(1, AcademicSession::query()->where('name', '2027/2028')->count());
    }

    public function test_a_session_name_that_is_not_two_years_is_refused(): void
    {
        $this->addSession(['name' => 'Next session'])->assertSessionHasErrors('name');

        $this->assertSame(1, AcademicSession::query()->count());
    }

    public function test_a_session_cannot_end_before_it_starts(): void
    {
        $this->addSession([
            'starts_on' => '2027-09-01',
            'ends_on' => '2027-07-31',
        ])->assertSessionHasErrors('ends_on');
    }

    public function test_the_next_session_name_is_suggested_by_rolling_the_years_on(): void
    {
        $this->assertSame('2027/2028', app(AcademicCalendarService::class)->suggestNextSessionName());
    }

    /* ------------------------------------------------------------------ */
    /* Adding a term                                                       */
    /* ------------------------------------------------------------------ */

    public function test_a_term_can_be_added_to_a_session(): void
    {
        $this->addTerm($this->thisYear, 'Fourth Term', ['starts_on' => '2027-04-26']);

        $term = $this->term($this->thisYear, 'Fourth Term');

        $this->assertSame(4, $term->position);
        $this->assertFalse($term->is_current);
    }

    /** A term added after a deletion takes the gap, not the end. */
    public function test_a_new_term_takes_the_first_free_position(): void
    {
        $this->term($this->thisYear, 'Second Term')->delete();

        $this->addTerm($this->thisYear, 'Second Term (moved)');

        $this->assertSame(2, $this->term($this->thisYear, 'Second Term (moved)')->position);
        $this->assertSame(
            ['First Term', 'Second Term (moved)', 'Third Term'],
            $this->thisYear->terms()->orderBy('position')->pluck('name')->all(),
        );
    }

    public function test_the_same_term_cannot_be_added_twice_to_one_session(): void
    {
        $this->addTerm($this->thisYear, 'First Term')->assertSessionHas('error');

        $this->assertSame(3, $this->thisYear->terms()->count());
    }

    public function test_a_term_can_be_added_to_a_different_session_with_the_same_name(): void
    {
        $next = AcademicSession::create(['name' => '2027/2028', 'is_current' => false]);

        $this->addTerm($next, 'First Term')->assertSessionHas('status');

        $this->assertSame(1, $next->terms()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Deleting                                                            */
    /* ------------------------------------------------------------------ */

    public function test_an_unused_session_can_be_deleted_along_with_its_terms(): void
    {
        $this->addSession();
        $session = AcademicSession::query()->where('name', '2027/2028')->sole();

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.sessions.destroy', $session))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, AcademicSession::query()->where('name', '2027/2028')->count());
        $this->assertSame(0, Term::query()->where('academic_session_id', $session->id)->count());
    }

    /** Deleting the session the school is in would leave every screen without an answer. */
    public function test_the_current_session_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.sessions.destroy', $this->thisYear))
            ->assertSessionHas('error');

        $this->assertTrue($this->thisYear->refresh()->exists);
        $this->assertTrue($this->thisYear->is_current);
    }

    /**
     * The reason this guard exists at all: the foreign key would have taken the
     * child with the session, without a word.
     */
    public function test_a_session_holding_students_cannot_be_deleted_and_says_so(): void
    {
        $next = AcademicSession::create(['name' => '2027/2028', 'is_current' => false]);
        $this->student($next);

        $response = $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.sessions.destroy', $next));

        $response->assertSessionHas('error');

        $message = (string) session('error');

        $this->assertStringContainsString('1 student', $message);
        $this->assertStringContainsString('2027/2028', $message);

        $this->assertTrue($next->refresh()->exists);
        $this->assertSame(1, $next->students()->count());
    }

    public function test_the_message_counts_everything_that_is_in_the_way(): void
    {
        $next = AcademicSession::create(['name' => '2027/2028', 'is_current' => false]);
        $this->student($next);
        $this->student($next);

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.sessions.destroy', $next));

        $this->assertStringContainsString('2 students', (string) session('error'));
    }

    public function test_an_unused_term_can_be_deleted(): void
    {
        $term = $this->term($this->thisYear, 'Third Term');

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.terms.destroy', $term))
            ->assertSessionHas('status');

        $this->assertSame(2, $this->thisYear->terms()->count());
    }

    public function test_the_active_term_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.terms.destroy', $this->term($this->thisYear, 'First Term')))
            ->assertSessionHas('error');

        $this->assertSame(3, $this->thisYear->terms()->count());
    }

    public function test_a_term_holding_assessments_cannot_be_deleted(): void
    {
        $term = $this->term($this->thisYear, 'Third Term');

        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $class = SchoolClass::create(['level_id' => $level->id, 'name' => 'JSS1A']);
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);

        Assessment::create([
            'name' => 'CA1',
            'academic_session_id' => $this->thisYear->id,
            'term_id' => $term->id,
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.terms.destroy', $term))
            ->assertSessionHas('error');

        $this->assertStringContainsString('1 assessment', (string) session('error'));
        $this->assertTrue($term->refresh()->exists);
    }

    /* ------------------------------------------------------------------ */
    /* Who may do any of this                                              */
    /* ------------------------------------------------------------------ */

    public function test_nobody_without_settings_permission_can_change_the_calendar(): void
    {
        $examOfficer = User::factory()->create();
        $examOfficer->assignRole('Exam Officer');

        $term = $this->term($this->thisYear, 'Third Term');

        $this->actingAs($examOfficer)->post(route('admin.settings.academic.sessions.store'), [
            'name' => '2027/2028',
        ])->assertForbidden();

        $this->actingAs($examOfficer)
            ->delete(route('admin.settings.academic.sessions.destroy', $this->thisYear))
            ->assertForbidden();

        $this->actingAs($examOfficer)->post(route('admin.settings.academic.terms.store'), [
            'academic_session_id' => $this->thisYear->id,
            'name' => 'Fourth Term',
        ])->assertForbidden();

        $this->actingAs($examOfficer)
            ->delete(route('admin.settings.academic.terms.destroy', $term))
            ->assertForbidden();

        $this->assertSame(3, $this->thisYear->terms()->count());
    }

    /* ------------------------------------------------------------------ */
    /* The page                                                            */
    /* ------------------------------------------------------------------ */

    public function test_the_page_shows_the_calendar_and_offers_the_deletions_that_would_work(): void
    {
        $next = AcademicSession::create(['name' => '2027/2028', 'is_current' => false]);
        $this->student($next);

        $unused = AcademicSession::create(['name' => '2028/2029', 'is_current' => false]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Sessions and terms')
            ->assertSee('Add an academic session')
            ->assertSee('Add term')
            ->getContent();

        // Nothing is offered for the session in use, or one holding a child.
        $this->assertStringNotContainsString(
            route('admin.settings.academic.sessions.destroy', $this->thisYear),
            $html,
            'A delete button was offered for the current session.',
        );

        $this->assertStringNotContainsString(
            route('admin.settings.academic.sessions.destroy', $next),
            $html,
            'A delete button was offered for a session holding a student.',
        );

        // An empty one, and the term that is not active, are both offered.
        $this->assertStringContainsString(route('admin.settings.academic.sessions.destroy', $unused), $html);
        $this->assertStringContainsString(
            route('admin.settings.academic.terms.destroy', $this->term($this->thisYear, 'Third Term')),
            $html,
        );
    }

    public function test_the_page_says_what_is_held_against_a_session_that_cannot_be_deleted(): void
    {
        $next = AcademicSession::create(['name' => '2027/2028', 'is_current' => false]);
        $this->student($next);

        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Holds 1 student');
    }
}
