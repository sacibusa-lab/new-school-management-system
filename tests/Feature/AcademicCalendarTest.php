<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Assessment;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\SessionTerm;
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
 * The calendar: sessions are years, terms are shared by all of them.
 *
 * The thing that changed, and the thing these tests are mostly about, is that a
 * term is no longer a row per session. There is one First Term; it runs in every
 * session; only *when* it runs differs, and those dates are kept per session so
 * that starting the next year does not rewrite what last year's report cards said.
 *
 * Deletion is the other half. Every foreign key pointing at a session or a term is
 * ON DELETE CASCADE — applicants, students, examinations, invoices, assessments
 * and results all vanish silently with the session they belong to — so the guard
 * cannot be the database's.
 */
class AcademicCalendarTest extends TestCase
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

        $this->termAt(1)->forceFill(['is_current' => true])->save();
        $this->calendar->setTermDates($this->thisYear, $this->termAt(1), '2026-09-01', '2026-12-18');
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

    private function termAt(int $position): Term
    {
        return Term::query()->where('position', $position)->sole();
    }

    private function addSession(array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(
            route('admin.settings.academic.sessions.store'),
            array_merge(['name' => '2028/2029'], $overrides),
        );
    }

    private function addTerm(string $name, array $overrides = [])
    {
        return $this->actingAs($this->admin)->post(
            route('admin.settings.academic.terms.store'),
            array_merge(['name' => $name, 'dates_in' => $this->thisYear->id], $overrides),
        );
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
    /* A term is the same term in every session                            */
    /* ------------------------------------------------------------------ */

    public function test_every_session_has_every_term_without_anybody_creating_them(): void
    {
        $this->assertSame(['First Term', 'Second Term', 'Third Term'], $this->thisYear->terms->pluck('name')->all());
        $this->assertSame(['First Term', 'Second Term', 'Third Term'], $this->nextYear->terms->pluck('name')->all());

        // The same records, not copies.
        $this->assertSame(
            $this->thisYear->terms->pluck('id')->all(),
            $this->nextYear->terms->pluck('id')->all(),
        );
    }

    /** The point of the whole change: last year's dates survive next year starting. */
    public function test_the_same_term_keeps_its_own_dates_in_each_session(): void
    {
        $this->calendar->setTermDates($this->nextYear, $this->termAt(1), '2027-09-06', '2027-12-17');

        $first = $this->termAt(1);

        $this->assertSame('2026-09-01', $first->datesIn($this->thisYear)?->starts_on?->format('Y-m-d'));
        $this->assertSame('2026-12-18', $first->datesIn($this->thisYear)?->ends_on?->format('Y-m-d'));

        $this->assertSame('2027-09-06', $first->datesIn($this->nextYear)?->starts_on?->format('Y-m-d'));
        $this->assertSame('2027-12-17', $first->datesIn($this->nextYear)?->ends_on?->format('Y-m-d'));

        // One term, one set of dates per session.
        $this->assertSame(1, Term::query()->where('position', 1)->count());
        $this->assertSame(2, SessionTerm::query()->where('term_id', $first->id)->count());
    }

    public function test_a_term_answers_without_dates_when_asked_about_no_session(): void
    {
        $this->assertNull($this->termAt(1)->datesIn(null));
    }

    public function test_moving_the_school_on_leaves_every_earlier_session_intact(): void
    {
        $student = $this->student($this->thisYear);

        $this->actingAs($this->admin)->put(route('admin.settings.academic.update'), [
            'academic_session_id' => $this->nextYear->id,
            'term_id' => $this->termAt(2)->id,
        ])->assertSessionHas('status');

        // The school is in the new session, in the term it chose.
        $this->assertTrue($this->nextYear->refresh()->is_current);
        $this->assertSame('Second Term', Term::current()?->name);

        // And the year it left is exactly as it was, still reachable.
        $this->assertSame($this->thisYear->id, $student->refresh()->academic_session_id);
        $this->assertSame(
            '2026-09-01',
            $this->termAt(1)->datesIn($this->thisYear)?->starts_on?->format('Y-m-d'),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Adding a session                                                    */
    /* ------------------------------------------------------------------ */

    public function test_a_session_can_be_added_and_arrives_with_every_term(): void
    {
        $this->addSession()->assertRedirect()->assertSessionHas('status');

        $session = AcademicSession::query()->where('name', '2028/2029')->sole();

        $this->assertSame(
            ['First Term', 'Second Term', 'Third Term'],
            $session->terms->pluck('name')->all(),
        );

        // No dates until somebody sets them.
        $this->assertNull($this->termAt(1)->datesIn($session)?->starts_on);
    }

    /** Moving the school is a separate, deliberate act. */
    public function test_a_new_session_does_not_become_the_session_the_school_is_in(): void
    {
        $this->addSession();

        $this->assertFalse(AcademicSession::query()->where('name', '2028/2029')->sole()->is_current);
        $this->assertTrue($this->thisYear->refresh()->is_current);
    }

    public function test_a_session_name_is_stored_in_one_shape_and_cannot_repeat(): void
    {
        $this->addSession(['name' => '2028 / 2029']);
        $this->assertSame(1, AcademicSession::query()->where('name', '2028/2029')->count());

        $this->addSession(['name' => '2028/2029'])->assertSessionHasErrors('name');
        $this->addSession(['name' => 'Next session'])->assertSessionHasErrors('name');
    }

    public function test_a_session_cannot_end_before_it_starts(): void
    {
        $this->addSession(['starts_on' => '2028-09-01', 'ends_on' => '2028-07-31'])
            ->assertSessionHasErrors('ends_on');
    }

    public function test_the_next_session_name_is_suggested_by_rolling_the_years_on(): void
    {
        $this->assertSame('2028/2029', $this->calendar->suggestNextSessionName());
    }

    /* ------------------------------------------------------------------ */
    /* Adding a term                                                       */
    /* ------------------------------------------------------------------ */

    public function test_a_term_added_belongs_to_every_session_at_once(): void
    {
        $this->addTerm('Fourth Term')->assertSessionHas('status');

        $fourth = $this->termAt(4);

        $this->assertSame('Fourth Term', $fourth->name);
        $this->assertTrue($this->thisYear->refresh()->terms->contains($fourth));
        $this->assertTrue($this->nextYear->refresh()->terms->contains($fourth));
    }

    public function test_a_term_takes_the_first_free_position(): void
    {
        $this->termAt(2)->delete();

        $this->addTerm('Second Term (moved)');

        $this->assertSame(2, Term::query()->where('name', 'Second Term (moved)')->sole()->position);
        $this->assertSame(
            ['First Term', 'Second Term (moved)', 'Third Term'],
            Term::query()->orderBy('position')->pluck('name')->all(),
        );
    }

    public function test_a_term_name_cannot_repeat_because_it_would_repeat_in_every_session(): void
    {
        $this->addTerm('First Term')->assertSessionHas('error');

        $this->assertSame(3, Term::query()->count());
    }

    public function test_dates_given_with_a_new_term_are_set_for_the_session_named(): void
    {
        $this->addTerm('Fourth Term', [
            'dates_in' => $this->nextYear->id,
            'starts_on' => '2028-04-24',
            'ends_on' => '2028-07-28',
        ]);

        $fourth = $this->termAt(4);

        $this->assertSame('2028-04-24', $fourth->datesIn($this->nextYear)?->starts_on?->format('Y-m-d'));
        $this->assertNull($fourth->datesIn($this->thisYear)?->starts_on);
    }

    /* ------------------------------------------------------------------ */
    /* Deleting                                                            */
    /* ------------------------------------------------------------------ */

    public function test_an_unused_session_can_be_deleted_and_the_terms_stay(): void
    {
        $this->addSession();
        $session = AcademicSession::query()->where('name', '2028/2029')->sole();

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.sessions.destroy', $session))
            ->assertSessionHas('status');

        // Queried fresh rather than refreshed: refreshing a deleted row throws.
        $this->assertFalse(AcademicSession::query()->where('name', '2028/2029')->exists());

        // The terms never belonged to the session, so deleting the year does not
        // delete them — nor the years that are still running them.
        $this->assertSame(3, Term::query()->count());
        $this->assertSame(3, $this->thisYear->refresh()->terms->count());
    }

    public function test_the_current_session_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.sessions.destroy', $this->thisYear))
            ->assertSessionHas('error');

        $this->assertTrue($this->thisYear->refresh()->exists);
    }

    public function test_a_session_holding_students_cannot_be_deleted_and_says_what_is_in_the_way(): void
    {
        $this->student($this->nextYear);
        $this->student($this->nextYear);

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.sessions.destroy', $this->nextYear));

        $message = (string) session('error');

        $this->assertStringContainsString('2 students', $message);
        $this->assertStringContainsString('2027/2028', $message);
        $this->assertTrue($this->nextYear->refresh()->exists);
        $this->assertSame(2, $this->nextYear->students()->count());
    }

    public function test_an_unused_term_can_be_deleted_from_every_session(): void
    {
        $third = $this->termAt(3);

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.terms.destroy', $third))
            ->assertSessionHas('status');

        $this->assertSame(2, Term::query()->count());
        $this->assertSame(0, SessionTerm::query()->where('term_id', $third->id)->count());
        $this->assertSame(['First Term', 'Second Term'], $this->thisYear->refresh()->terms->pluck('name')->all());
    }

    public function test_the_active_term_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.terms.destroy', $this->termAt(1)))
            ->assertSessionHas('error');

        $this->assertSame(3, Term::query()->count());
    }

    public function test_a_term_holding_results_cannot_be_deleted(): void
    {
        $third = $this->termAt(3);

        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $class = SchoolClass::create(['level_id' => $level->id, 'name' => 'JSS1A']);
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);

        Assessment::create([
            'name' => 'CA1',
            'academic_session_id' => $this->thisYear->id,
            'term_id' => $third->id,
            'school_class_id' => $class->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.settings.academic.terms.destroy', $third))
            ->assertSessionHas('error');

        $this->assertStringContainsString('1 assessment', (string) session('error'));
        $this->assertTrue($third->refresh()->exists);
    }

    /* ------------------------------------------------------------------ */
    /* Setting the dates of one session's term                             */
    /* ------------------------------------------------------------------ */

    public function test_term_dates_can_be_set_for_one_session_without_touching_another(): void
    {
        $this->actingAs($this->admin)->put(
            route('admin.settings.academic.terms.dates', $this->termAt(2)),
            [
                'academic_session_id' => $this->nextYear->id,
                'starts_on' => '2028-01-10',
                'ends_on' => '2028-04-06',
            ],
        )->assertSessionHas('status');

        $second = $this->termAt(2);

        $this->assertSame('2028-01-10', $second->datesIn($this->nextYear)?->starts_on?->format('Y-m-d'));
        $this->assertNull($second->datesIn($this->thisYear)?->starts_on);
    }

    /* ------------------------------------------------------------------ */
    /* Who may do any of this                                              */
    /* ------------------------------------------------------------------ */

    public function test_nobody_without_settings_permission_can_change_the_calendar(): void
    {
        $examOfficer = User::factory()->create();
        $examOfficer->assignRole('Exam Officer');

        $this->actingAs($examOfficer)->post(route('admin.settings.academic.sessions.store'), [
            'name' => '2028/2029',
        ])->assertForbidden();

        $this->actingAs($examOfficer)->post(route('admin.settings.academic.terms.store'), [
            'name' => 'Fourth Term',
        ])->assertForbidden();

        $this->actingAs($examOfficer)
            ->delete(route('admin.settings.academic.terms.destroy', $this->termAt(3)))
            ->assertForbidden();

        $this->assertSame(3, Term::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* The page                                                            */
    /* ------------------------------------------------------------------ */

    public function test_the_page_shows_sessions_and_terms_as_two_separate_lists(): void
    {
        $this->student($this->nextYear);

        $this->addSession(['name' => '2028/2029']);
        $unused = AcademicSession::query()->where('name', '2028/2029')->sole();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Terms')
            ->assertSee('Add a term')
            ->assertSee('Add an academic session')
            ->assertSee('the same in every session')
            ->assertSee('First Term')
            ->assertSee('Second Term')
            ->assertSee('Third Term')
            ->getContent();

        // The terms are listed once for the school, not once per session: three
        // terms means three date editors, however many sessions exist.
        $this->assertSame(3, substr_count($html, 'Set dates for a session'));

        // Deletions are offered only where they would work. Matched on the form
        // action: the bare URL is also a prefix of that term's dates endpoint.
        $this->assertStringNotContainsString(
            'action="' . route('admin.settings.academic.sessions.destroy', $this->thisYear) . '"',
            $html,
            'A delete button was offered for the current session.',
        );

        $this->assertStringNotContainsString(
            'action="' . route('admin.settings.academic.terms.destroy', $this->termAt(1)) . '"',
            $html,
            'A delete button was offered for the active term.',
        );

        $this->assertStringContainsString(
            'action="' . route('admin.settings.academic.sessions.destroy', $unused) . '"',
            $html,
        );

        $this->assertStringContainsString(
            'action="' . route('admin.settings.academic.terms.destroy', $this->termAt(3)) . '"',
            $html,
        );
    }
}
