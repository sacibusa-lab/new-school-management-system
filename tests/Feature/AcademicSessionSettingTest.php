<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\SessionTerm;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use App\Services\Academics\AcademicCalendarService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where the school is now.
 *
 * Two answers, and every figure the school reports is relative to them: an invoice
 * belongs to a session, a result belongs to a session and a term, an attendance
 * register to a term. So each has to be single-valued, and the rules that keep
 * them that way are the whole point of this screen.
 *
 * The term is now the *same* term in every session — moving to a new year does not
 * produce a new First Term, it produces this year's dates for the one First Term
 * there is. Which is what makes last year's results still make sense.
 */
class AcademicSessionSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicCalendarService $calendar;

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

        $this->calendar = app(AcademicCalendarService::class);

        $this->thisYear = $this->makeSession('2026/2027', '2026-09-01', true);
        $this->nextYear = $this->makeSession('2027/2028', '2027-09-01', false);

        $this->calendar->ensureStandardTerms();
        $this->calendar->attachEveryTermTo($this->thisYear);
        $this->calendar->attachEveryTermTo($this->nextYear);

        $this->term(1)->forceFill(['is_current' => true])->save();
        $this->calendar->setTermDates($this->thisYear, $this->term(1), '2026-09-01', '2026-12-18');
    }

    private function makeSession(string $name, string $startsOn, bool $current): AcademicSession
    {
        return AcademicSession::create([
            'name' => $name,
            'starts_on' => $startsOn,
            'ends_on' => $startsOn === '2026-09-01' ? '2027-07-31' : '2028-07-31',
            'is_current' => $current,
        ]);
    }

    private function term(int $position): Term
    {
        return Term::query()->where('position', $position)->sole();
    }

    private function setPeriod(AcademicSession $session, ?Term $term = null)
    {
        return $this->actingAs($this->admin)->put(route('admin.settings.academic.update'), [
            'academic_session_id' => $session->id,
            'term_id' => $term?->id,
        ]);
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

    public function test_the_active_term_dates_shown_are_the_dates_for_this_session(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('1 Sep 2026 to 18 Dec 2026');
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
        $this->setPeriod($this->nextYear)->assertRedirect()->assertSessionHas('status');

        $this->assertFalse($this->thisYear->refresh()->is_current);
        $this->assertTrue($this->nextYear->refresh()->is_current);
        $this->assertSame('2027/2028', AcademicSession::current()?->name);
    }

    /** The term is not rebuilt for the new session: it is the same row. */
    public function test_moving_to_another_session_keeps_the_same_term_record(): void
    {
        $first = $this->term(1);

        $this->setPeriod($this->nextYear, $first);

        $this->assertSame(1, Term::query()->where('position', 1)->count());
        $this->assertSame($first->id, Term::current()?->id);
        $this->assertSame('First Term', Term::current()?->name);
    }

    public function test_moving_to_another_session_without_naming_a_term_takes_its_first(): void
    {
        $this->setPeriod($this->nextYear);

        $this->assertSame('First Term', Term::current()?->name);
    }

    public function test_a_term_is_valid_whichever_session_the_school_is_in(): void
    {
        // No longer "that term belongs to a different session": there are no
        // per-session terms to belong to. Any term can be made the active one.
        $this->setPeriod($this->nextYear, $this->term(3))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame('Third Term', Term::current()?->name);
        $this->assertSame(1, Term::query()->where('is_current', true)->count());
    }

    public function test_only_one_term_is_ever_active(): void
    {
        $this->setPeriod($this->thisYear, $this->term(3));

        $this->assertSame('Third Term', Term::current()?->name);
        $this->assertFalse($this->term(1)->refresh()->is_current);
        $this->assertSame(1, Term::query()->where('is_current', true)->count());
    }

    public function test_a_new_session_added_while_the_school_is_somewhere_else_is_still_usable(): void
    {
        $this->calendar->addSession('2028/2029', '2028-09-01', '2029-07-31', $this->admin);
        $newest = AcademicSession::query()->where('name', '2028/2029')->sole();

        $this->setPeriod($newest, $this->term(2))->assertSessionHas('status');

        $this->assertTrue($newest->refresh()->is_current);
        $this->assertSame('Second Term', Term::current()?->name);
    }

    /**
     * The dates are the only thing that is per session, and moving the school must
     * not be the thing that quietly rewrites them.
     */
    public function test_moving_the_school_on_does_not_rewrite_any_term_dates(): void
    {
        $this->setPeriod($this->nextYear, $this->term(1));

        $this->assertSame(
            '2026-09-01',
            $this->term(1)->datesIn($this->thisYear)?->starts_on?->format('Y-m-d'),
        );
        $this->assertNull($this->term(1)->datesIn($this->nextYear)?->starts_on);

        // One row of dates per session — two sessions, two rows — and moving the
        // school did not add or remove either of them.
        $this->assertSame(2, SessionTerm::query()->where('term_id', $this->term(1)->id)->count());
    }

    /* ------------------------------------------------------------------ */
    /* What already hangs off it                                           */
    /* ------------------------------------------------------------------ */

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

    /** A session that is not the current one is not "in" a term. */
    public function test_a_past_session_does_not_answer_with_todays_active_term(): void
    {
        $this->setPeriod($this->nextYear, $this->term(2));

        $this->assertNull($this->thisYear->refresh()->currentTerm());
        $this->assertSame('Second Term', $this->nextYear->refresh()->currentTerm()?->name);
    }
}
