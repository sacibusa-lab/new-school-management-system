<?php

namespace Tests\Feature;

use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\ResultPin;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PINs a parent buys to check a result.
 *
 * One to a student for one term, so two things are worth pinning down: a press of
 * Generate makes exactly one PIN for everybody on roll in that class, and pressing it
 * again fills the gaps rather than selling a family a second card for the same result.
 *
 * Nothing asks a parent for a PIN yet — checking a result still needs only the session,
 * the term and the admission number — so these tests are about the table and the
 * screen, and say nothing about what a parent can see.
 */
class ResultPinTest extends TestCase
{
    use RefreshDatabase;

    private AcademicSession $session;

    private Term $term;

    private SchoolLevel $level;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $this->term = Term::create(['name' => 'First Term', 'position' => 1, 'is_current' => true]);
        $this->level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    /* ------------------------------------------------------------------ */
    /* The screen */
    /* ------------------------------------------------------------------ */

    public function test_the_screen_asks_which_class_and_which_section(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.pins'))
            ->assertOk()
            ->assertSee('Select Ground')
            ->assertSee('First select the class')
            ->assertSee('Select the class first')
            ->assertSee('Generate')
            ->assertSee(route('admin.students-results.pins.generate'), false)
            // The page is built now: it is no longer the module's placeholder.
            ->assertDontSee('This page has not been built yet');
    }

    public function test_the_screen_offers_only_the_sections_a_class_actually_has(): void
    {
        $class = $this->class('JSS1A');
        $orphan = Section::create(['name' => 'D', 'order' => 4]);

        $content = $this->actingAs($this->admin)
            ->get(route('admin.students-results.pins'))
            ->assertOk()
            ->getContent();

        // The map the page greys the list out with: JSS1 has A, because A is a class,
        // and not D, because D is not one yet. Js::from renders as JSON.parse('…')
        // with the quotes escaped, so this matches the fragment the browser parses.
        $this->assertStringContainsString('sections\u0022:['.$class->section_id.']', $content);
        $this->assertStringNotContainsString('sections\u0022:['.$orphan->id.']', $content);
    }

    /* ------------------------------------------------------------------ */
    /* Generating */
    /* ------------------------------------------------------------------ */

    public function test_generating_makes_one_pin_for_every_student_on_roll(): void
    {
        $class = $this->class('JSS1A', [['Chidera', 'Okafor'], ['Bola', 'Adeyemi'], ['Zainab', 'Yusuf']]);
        $other = $this->class('JSS1B', [['Amaka', 'Obi']]);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id])
            ->assertRedirect(route('admin.students-results.pins', ['class' => $class->id]))
            ->assertSessionHas('status', fn (string $message) => str_contains($message, '3 PIN(s) generated'));

        $this->assertSame(3, ResultPin::query()->count());
        $this->assertSame(
            0,
            ResultPin::query()->where('student_id', $other->students()->value('id'))->count(),
            'A PIN was made for a class nobody asked about.',
        );

        foreach (ResultPin::query()->get() as $pin) {
            $this->assertMatchesRegularExpression('/^\d{12}$/', $pin->pin);
            $this->assertSame($this->term->id, $pin->term_id);
            $this->assertSame($this->session->id, $pin->academic_session_id);
            $this->assertFalse($pin->isUsed());
        }
    }

    public function test_two_students_never_share_a_pin(): void
    {
        $class = $this->class('JSS1A', [['Chidera', 'Okafor'], ['Bola', 'Adeyemi'], ['Zainab', 'Yusuf']]);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id]);

        $this->assertSame(3, ResultPin::query()->distinct()->count('pin'));
    }

    /**
     * The normal way to use the screen: a child joins the class after the first batch,
     * so the office presses Generate again and only that child is given a card.
     */
    public function test_generating_again_only_fills_the_gaps(): void
    {
        $class = $this->class('JSS1A', [['Chidera', 'Okafor'], ['Bola', 'Adeyemi']]);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id]);

        $before = ResultPin::query()->pluck('pin', 'student_id');

        $joined = $this->student('Amaka', 'Obi', $class);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id])
            ->assertSessionHas('status', fn (string $message) => str_contains($message, '1 PIN(s) generated')
                && str_contains($message, '2 student(s) already had one'));

        $this->assertSame(3, ResultPin::query()->count());

        // The cards already handed out are still the cards in the table.
        foreach ($before as $studentId => $pin) {
            $this->assertSame($pin, ResultPin::query()->where('student_id', $studentId)->value('pin'));
        }

        $this->assertNotNull(ResultPin::query()->where('student_id', $joined->id)->first());
    }

    public function test_pressing_generate_with_nothing_missing_says_so(): void
    {
        $class = $this->class('JSS1A', [['Chidera', 'Okafor']]);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id]);
        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id])
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'already has a PIN'));

        $this->assertSame(1, ResultPin::query()->count());
    }

    /** A second term is a second card: the first term's PIN does not cover it. */
    public function test_a_pin_covers_one_term_and_not_the_next(): void
    {
        $class = $this->class('JSS1A', [['Chidera', 'Okafor']]);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id]);

        $second = Term::create(['name' => 'Second Term', 'position' => 2, 'is_current' => true]);
        $this->term->forceFill(['is_current' => false])->save();

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id])
            ->assertSessionHas('status', fn (string $message) => str_contains($message, 'Second Term'));

        $this->assertSame(2, ResultPin::query()->count());
        $this->assertSame(1, ResultPin::query()->where('term_id', $second->id)->count());
        $this->assertSame(1, ResultPin::query()->where('term_id', $this->term->id)->count());
    }

    /** Somebody withdrawn is not on roll, so they are not sold a card. */
    public function test_a_student_who_has_left_is_not_given_a_pin(): void
    {
        $class = $this->class('JSS1A', [['Chidera', 'Okafor']]);
        $left = $this->student('Bola', 'Adeyemi', $class);
        $left->update(['status' => StudentStatus::Withdrawn]);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id]);

        $this->assertSame(1, ResultPin::query()->count());
        $this->assertNull(ResultPin::query()->where('student_id', $left->id)->first());
    }

    public function test_a_class_with_nobody_on_roll_says_so_rather_than_looking_empty(): void
    {
        $class = $this->class('JSS1A');

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'no students in JSS1A'));

        $this->assertSame(0, ResultPin::query()->count());
    }

    /** JSS1 and D are only a class once somebody has made JSS1D. */
    public function test_a_level_and_section_that_is_not_a_class_is_refused_by_name(): void
    {
        $section = Section::create(['name' => 'D', 'order' => 4]);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $section->id])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'JSS1D is not a class yet'));

        $this->assertSame(0, ResultPin::query()->count());
    }

    public function test_the_class_and_the_section_are_both_asked_for(): void
    {
        $this->generate([])->assertSessionHasErrors(['level_id', 'section_id']);

        $this->assertSame(0, ResultPin::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Reading the list, and printing it */
    /* ------------------------------------------------------------------ */

    public function test_the_screen_lists_the_class_pins_and_who_has_none(): void
    {
        $class = $this->class('JSS1A', [['Chidera', 'Okafor']]);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id]);

        // Somebody who joined after the batch: on the list, and visibly without a card.
        $this->student('Amaka', 'Obi', $class);

        $pin = ResultPin::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.pins', ['class' => $class->id]))
            ->assertOk()
            ->assertSee('PINs for JSS1A')
            ->assertSee('1 of 2 issued')
            ->assertSee('Chidera Okafor')
            ->assertSee($pin->grouped())
            ->assertSee('Amaka Obi')
            ->assertSee('No PIN yet')
            ->assertSee('Issued');
    }

    public function test_the_sheet_prints_one_line_to_a_student(): void
    {
        $class = $this->class('JSS1A', [['Chidera', 'Okafor']]);

        $this->generate(['level_id' => $this->level->id, 'section_id' => $class->section_id]);

        $pin = ResultPin::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.pins.sheet', ['class' => $class->id]))
            ->assertOk()
            ->assertSee('Result checker PINs')
            ->assertSee('Chidera Okafor')
            ->assertSee($pin->grouped())
            ->assertSee('First Term');
    }

    /* ------------------------------------------------------------------ */
    /* Who may do it */
    /* ------------------------------------------------------------------ */

    /**
     * A PIN is the school's money, so generating them is not handed round the staff
     * room: the Exam Officer can compute results and still cannot make a card.
     */
    public function test_generating_pins_is_closed_to_role_without_the_permission(): void
    {
        $class = $this->class('JSS1A', [['Chidera', 'Okafor']]);

        $officer = User::factory()->create();
        $officer->assignRole('Exam Officer');

        $this->actingAs($officer)
            ->get(route('admin.students-results.pins'))
            ->assertForbidden();

        $this->actingAs($officer)
            ->post(route('admin.students-results.pins.generate'), [
                'level_id' => $this->level->id,
                'section_id' => $class->section_id,
            ])
            ->assertForbidden();

        $this->actingAs($officer)
            ->get(route('admin.students-results.pins.sheet', ['class' => $class->id]))
            ->assertForbidden();

        $this->assertSame(0, ResultPin::query()->count());
    }

    /* ------------------------------------------------------------------ */

    private function class(string $name, array $students = []): SchoolClass
    {
        $section = Section::firstOrCreate(['name' => substr($name, -1)], ['order' => 1]);

        $class = SchoolClass::create([
            'level_id' => $this->level->id,
            'section_id' => $section->id,
            'name' => $name,
            'is_active' => true,
        ]);

        foreach ($students as [$first, $last]) {
            $this->student($first, $last, $class);
        }

        return $class;
    }

    private function student(string $first, string $last, SchoolClass $class): Student
    {
        $next = Student::query()->withTrashed()->count() + 1;

        return Student::create([
            'academic_session_id' => $this->session->id,
            'level_id' => $this->level->id,
            'school_class_id' => $class->id,
            'student_number' => 'SAC/2026/'.str_pad((string) $next, 3, '0', STR_PAD_LEFT),
            'admission_number' => 'SAC-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT),
            'first_name' => $first,
            'last_name' => $last,
        ]);
    }

    private function generate(array $payload)
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.students-results.pins.generate'), $payload);
    }
}
