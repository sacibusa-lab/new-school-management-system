<?php

namespace Tests\Feature;

use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Class & Section Report.
 *
 * The page answers one question — how many are in JSS1 — and gets it wrong in three
 * ways that all look plausible on screen: a total that does not add up, an empty arm
 * quietly dropped because it has nobody in it, and a figure that disagrees with the
 * register it links to. So the numbers are read off the row they belong to rather than
 * searched for on the page, and the last test opens the register and counts.
 */
class ClassSectionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $year;

    private SchoolLevel $jss1;

    private SchoolLevel $jss2;

    private Section $a;

    private Section $b;

    private Section $c;

    /** Admission numbers are unique, so the fixtures count their own. */
    private int $roll = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->year = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'is_current' => true,
        ]);

        $this->jss1 = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);
        $this->jss2 = SchoolLevel::create(['name' => 'JSS2', 'order' => 2, 'is_active' => true]);

        $this->a = Section::create(['name' => 'A', 'order' => 1]);
        $this->b = Section::create(['name' => 'B', 'order' => 2]);
        $this->c = Section::create(['name' => 'C', 'order' => 3]);
    }

    /* ------------------------------------------------------------------ */
    /* The numbers */
    /* ------------------------------------------------------------------ */

    public function test_each_year_group_is_counted_and_totalled(): void
    {
        $jss1a = $this->classOf($this->jss1, $this->a);
        $jss1b = $this->classOf($this->jss1, $this->b);
        $jss2a = $this->classOf($this->jss2, $this->a);

        $this->students($jss1a, 3);
        $this->students($jss1b, 2);
        $this->students($jss2a, 4);

        $html = $this->report();

        $jss1 = $this->rowFor('JSS1', $html);

        $this->assertStringContainsString('A (3)', $jss1);
        $this->assertStringContainsString('B (2)', $jss1);
        // 3 + 2, printed as one figure rather than left to be added up by eye.
        $this->assertStringContainsString('> 5 <', $this->squeeze($jss1));

        $jss2 = $this->rowFor('JSS2', $html);

        $this->assertStringContainsString('A (4)', $jss2);
        $this->assertStringContainsString('> 4 <', $this->squeeze($jss2));
        // JSS2 has no B, so it must not be borrowing JSS1's arms.
        $this->assertStringNotContainsString('B (', $jss2);
    }

    /** An empty arm is one of the things the report is read to find out. */
    public function test_a_class_with_nobody_in_it_is_still_listed(): void
    {
        $this->classOf($this->jss1, $this->a);
        $this->classOf($this->jss1, $this->b);
        $this->classOf($this->jss1, $this->c);

        $jss1 = $this->rowFor('JSS1', $this->report());

        $this->assertStringContainsString('A (0)', $jss1);
        $this->assertStringContainsString('C (0)', $jss1);
    }

    public function test_a_year_group_with_no_classes_yet_says_so(): void
    {
        $jss1 = preg_replace('/\s+/', ' ', $this->rowFor('JSS1', $this->report()));

        $this->assertStringContainsString('No classes yet', $jss1);
        $this->assertStringContainsString('> 0 <', $jss1);
    }

    /**
     * A child admitted but not yet placed in a class is on the register — asking it for
     * their year group alone finds them — so they belong in the year group's figure, and
     * they have to be visible, because a total that does not add up to the lines printed
     * beside it is worse than no total at all.
     */
    public function test_a_student_with_no_class_yet_is_inside_the_total_and_shown(): void
    {
        $this->students($this->classOf($this->jss1, $this->a), 2);
        $this->students(null, 3, $this->jss1);

        $jss1 = $this->rowFor('JSS1', $this->report());

        $this->assertStringContainsString('A (2)', $jss1);
        $this->assertStringContainsString('3 not yet placed in a class', $jss1);
        $this->assertStringContainsString('> 5 <', $this->squeeze($jss1));
    }

    /**
     * A withdrawn arm is not something to advertise, but it is not a place to hide a
     * child either: it goes when it is empty, and stays while it still holds anybody.
     */
    public function test_a_withdrawn_class_is_left_out_unless_it_still_holds_students(): void
    {
        $this->students($this->classOf($this->jss1, $this->a), 1);
        $this->classOf($this->jss1, $this->b, active: false);
        $this->students($this->classOf($this->jss1, $this->c, active: false), 2);

        $jss1 = $this->rowFor('JSS1', $this->report());

        $this->assertStringNotContainsString('B (', $jss1);
        $this->assertStringContainsString('C (2)', $jss1);
        $this->assertStringContainsString('> 3 <', $this->squeeze($jss1));
    }

    /* ------------------------------------------------------------------ */
    /* The figure is the register's figure */
    /* ------------------------------------------------------------------ */

    public function test_the_number_beside_a_class_is_what_the_register_shows_for_it(): void
    {
        $this->students($this->classOf($this->jss1, $this->a), 3);

        $this->assertStringContainsString('A (3)', $this->rowFor('JSS1', $this->report()));

        $register = $this->actingAs($this->admin)
            ->get(route('admin.students-results.students', [
                'class' => $this->jss1->id,
                'section' => $this->a->id,
            ]))
            ->assertOk()
            ->getContent();

        // One tick box to a line, and the header's is not one of them.
        $this->assertSame(3, substr_count($register, 'name="students[]"'));
    }

    public function test_the_number_beside_a_class_opens_the_register_on_that_class(): void
    {
        $this->classOf($this->jss1, $this->a);

        $jss1 = $this->rowFor('JSS1', $this->report());

        // e() because this reads the HTML, where the query string's ampersand is escaped.
        $this->assertStringContainsString(
            e(route('admin.students-results.students', ['class' => $this->jss1->id, 'section' => $this->a->id])),
            $jss1,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Who may read it */
    /* ------------------------------------------------------------------ */

    /**
     * It hangs off Students Details, so it answers to that page's permission rather than
     * one of its own. The control panel asks only for a signed-in user, so what refuses
     * a stranger is the page's own gate — which is why it is worth pinning down.
     */
    public function test_the_report_answers_to_the_permission_of_the_register(): void
    {
        $url = route('admin.students-results.students.class-section-report');

        $this->actingAs(User::factory()->create())->get($url)->assertForbidden();

        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->assertTrue($teacher->can('students.view'));

        $this->actingAs($teacher)->get($url)->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    private function report(): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.students-results.students.class-section-report'))
            ->assertOk()
            ->getContent();
    }

    /**
     * The one row of the report belonging to a year group.
     *
     * Read this way round rather than by searching the page for "3", which appears in
     * half a dozen places once there is more than one year group on it.
     */
    private function rowFor(string $levelName, string $html): string
    {
        $body = (string) str($html)->after('<tbody')->before('</tbody>');

        foreach (explode('<tr', $body) as $row) {
            if (preg_match('/>\s*'.preg_quote($levelName, '/').'\s*</', $row) === 1) {
                return $row;
            }
        }

        $this->fail("The report has no row for {$levelName}.");
    }

    /**
     * A row with its whitespace collapsed, so a cell can be read as "> 5 <" whatever
     * the indentation around it.
     */
    private function squeeze(string $html): string
    {
        return (string) preg_replace('/\s+/', ' ', $html);
    }

    private function classOf(SchoolLevel $level, Section $section, bool $active = true): SchoolClass
    {
        return SchoolClass::create([
            'level_id' => $level->id,
            'section_id' => $section->id,
            'name' => $level->name.$section->name,
            'is_active' => $active,
        ]);
    }

    /**
     * Put children on the roll.
     *
     * A class of null with a level given is the child who has been admitted and not yet
     * placed, which the report counts inside the year group they are waiting in.
     */
    private function students(?SchoolClass $class, int $count, ?SchoolLevel $level = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->roll++;

            Student::create([
                'student_number' => 'SAC/2026/'.str_pad((string) $this->roll, 3, '0', STR_PAD_LEFT),
                'first_name' => 'Child',
                'last_name' => 'Number '.$this->roll,
                'level_id' => $class?->level_id ?? $level?->id,
                'school_class_id' => $class?->id,
                'academic_session_id' => $this->year->id,
                'status' => StudentStatus::Active->value,
                'admitted_at' => now(),
            ]);
        }
    }
}
