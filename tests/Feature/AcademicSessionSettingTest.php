<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the school is now.
 *
 * Two answers, and every figure the school reports is relative to them: an
 * invoice belongs to a session, a result belongs to a session and a term, an
 * attendance register belongs to a term. So each has to be single-valued, and
 * the rules that keep them that way are the whole point of this screen — a
 * session left current behind us is the stale answer to "which session are we
 * in?", and a term left current inside the session we have just left is the same
 * question answered twice.
 */
class AcademicSessionSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $thisYear;

    private AcademicSession $nextYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->thisYear = $this->makeSession('2026/2027', true);
        $this->nextYear = $this->makeSession('2027/2028', false);

        $this->termsFor($this->thisYear, current: 1);
    }

    /** Named for what it is: session() is the framework's own. */
    private function makeSession(string $name, bool $current): AcademicSession
    {
        return AcademicSession::create([
            'name' => $name,
            'starts_on' => $name === '2026/2027' ? '2026-09-01' : '2027-09-01',
            'ends_on' => $name === '2026/2027' ? '2027-07-31' : '2028-07-31',
            'is_current' => $current,
        ]);
    }

    /** The three terms a session runs, with one of them current. */
    private function termsFor(AcademicSession $session, int $current = 0): void
    {
        foreach ([1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'] as $position => $name) {
            $session->terms()->create([
                'name' => $name,
                'position' => $position,
                'is_current' => $position === $current,
            ]);
        }
    }

    private function setPeriod(AcademicSession $session, ?Term $term = null)
    {
        return $this->actingAs($this->admin)->put(route('admin.settings.academic.update'), [
            'academic_session_id' => $session->id,
            'term_id' => $term?->id,
        ]);
    }

    private function term(AcademicSession $session, string $name): Term
    {
        return $session->terms()->where('name', $name)->sole();
    }

    /* ------------------------------------------------------------------ */
    /* The screen                                                          */
    /* ------------------------------------------------------------------ */

    public function test_the_settings_page_says_which_session_and_term_the_school_is_in(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Academic session')
            ->assertSee('2026/2027')
            ->assertSee('First Term')
            ->assertSee('Set session and term');
    }

    public function test_only_a_setting_manager_can_move_the_school_on(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)->put(route('admin.settings.academic.update'), [
            'academic_session_id' => $this->nextYear->id,
        ])->assertForbidden();

        $this->assertTrue($this->thisYear->refresh()->is_current);
    }

    /* ------------------------------------------------------------------ */
    /* One session, one term                                               */
    /* ------------------------------------------------------------------ */

    public function test_moving_to_another_session_stops_the_old_one_being_current(): void
    {
        $this->termsFor($this->nextYear, current: 0);

        $this->setPeriod($this->nextYear)->assertRedirect()->assertSessionHas('status');

        $this->assertFalse($this->thisYear->refresh()->is_current);
        $this->assertTrue($this->nextYear->refresh()->is_current);
        $this->assertSame('2027/2028', AcademicSession::current()?->name);
    }

    public function test_moving_to_another_session_without_naming_a_term_takes_its_first(): void
    {
        $this->termsFor($this->nextYear);

        $this->setPeriod($this->nextYear);

        $this->assertSame('First Term', Term::current()?->name);
        $this->assertSame($this->nextYear->id, Term::current()?->academic_session_id);
    }

    /**
     * The stale case: a term left current in the session we have walked away from
     * would answer "which term are we in?" twice, and the second answer would be
     * the wrong one.
     */
    public function test_a_term_left_current_behind_us_is_no_longer_active(): void
    {
        $this->termsFor($this->nextYear);

        $this->assertSame('First Term', Term::current()?->name);

        $this->setPeriod($this->nextYear, $this->term($this->nextYear, 'Second Term'));

        $this->assertFalse($this->term($this->thisYear, 'First Term')->refresh()->is_current);
        $this->assertSame('Second Term', Term::current()?->name);
        $this->assertSame($this->nextYear->id, Term::current()?->academic_session_id);
    }

    public function test_choosing_a_later_term_inside_the_same_session_moves_the_active_term(): void
    {
        $this->setPeriod($this->thisYear, $this->term($this->thisYear, 'Third Term'));

        $this->assertSame('Third Term', Term::current()?->name);
        $this->assertFalse($this->term($this->thisYear, 'First Term')->refresh()->is_current);
        $this->assertTrue($this->thisYear->refresh()->is_current);
        $this->assertSame(1, Term::query()->where('is_current', true)->count());
    }

    public function test_a_term_belonging_to_another_session_is_refused(): void
    {
        $this->termsFor($this->nextYear);

        $this->setPeriod($this->thisYear, $this->term($this->nextYear, 'Third Term'))
            ->assertSessionHasErrors('term_id');

        // Nothing moved: the school is still where it was.
        $this->assertSame('First Term', Term::current()?->name);
        $this->assertTrue($this->thisYear->refresh()->is_current);
        $this->assertFalse($this->term($this->nextYear, 'Third Term')->refresh()->is_current);
    }

    /* ------------------------------------------------------------------ */
    /* A session with no terms                                             */
    /* ------------------------------------------------------------------ */

    public function test_a_session_with_no_terms_gets_the_three_standard_ones(): void
    {
        $this->assertSame(0, $this->nextYear->terms()->count());

        $response = $this->setPeriod($this->nextYear);

        $response->assertSessionHas('status');

        $names = $this->nextYear->terms()->orderBy('position')->pluck('name')->all();

        $this->assertSame(['First Term', 'Second Term', 'Third Term'], $names);
        $this->assertSame('First Term', Term::current()?->name);

        $this->assertStringContainsString(
            'created',
            (string) session('status'),
            'The office was not told terms had been created for the new session.',
        );
    }

    /**
     * Only ever when there are none. A school that renamed or reordered its terms
     * has said something, and the seeder setting the school up is not licence for
     * this screen to correct them.
     */
    public function test_terms_that_already_exist_are_left_exactly_as_they_are(): void
    {
        $this->termsFor($this->nextYear);

        $this->term($this->nextYear, 'First Term')->update(['name' => 'Michaelmas']);
        $this->nextYear->terms()->where('position', 3)->delete();

        $this->setPeriod($this->nextYear, $this->term($this->nextYear, 'Michaelmas'));

        $names = $this->nextYear->terms()->orderBy('position')->pluck('name')->all();

        $this->assertSame(['Michaelmas', 'Second Term'], $names);
        $this->assertSame('Michaelmas', Term::current()?->name);
    }

    /* ------------------------------------------------------------------ */
    /* What already hangs off it                                           */
    /* ------------------------------------------------------------------ */

    /** Moving on must not disturb the pupils already recorded against a session. */
    public function test_moving_the_school_on_leaves_the_students_where_they_are(): void
    {
        $student = Student::create([
            'academic_session_id' => $this->thisYear->id,
            'student_number' => 'SAC/2026/001',
            'admission_number' => 'SAC-00001',
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
        ]);

        $this->setPeriod($this->nextYear);

        $this->assertSame($this->thisYear->id, $student->refresh()->academic_session_id);
        $this->assertSame(1, $this->thisYear->students()->count());
    }
}
