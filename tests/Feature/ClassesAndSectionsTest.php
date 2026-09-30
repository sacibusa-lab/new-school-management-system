<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Assessment;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shape of the school: sections, class names, and the classes they make.
 *
 * The office's own order is the thing being pinned down here — sections first, then
 * class names, then JSS1A out of JSS1 and section A — because it is easy to get
 * backwards and impossible to notice until somebody tries to build a class and finds
 * there is nothing to build it from. The deletes matter as much: a class that holds
 * children, or a section that holds classes, must refuse rather than quietly take
 * the children's class away.
 */
class ClassesAndSectionsTest extends TestCase
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

    /* ------------------------------------------------------------------ */
    /* The page */
    /* ------------------------------------------------------------------ */

    public function test_the_page_opens_with_both_lists_on_it(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes'))
            ->assertOk()
            ->assertSee('Create Class')
            ->assertSee('Create Section')
            ->assertSee('Class Name')
            ->assertSee('Section');
    }

    /** The Section tab is the one that answers "there is nothing in the dropdown". */
    public function test_the_page_opens_on_the_class_tab_and_can_be_opened_on_the_section_tab(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes'))
            ->assertOk()
            ->assertSee('x-data="{ tab: \'class\' }"', false);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes', ['tab' => 'section']))
            ->assertOk()
            ->assertSee('x-data="{ tab: \'section\' }"', false);
    }

    public function test_the_whole_page_is_closed_to_a_role_without_the_permission(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->assertFalse($teacher->can('academics.manage'));

        $this->actingAs($teacher)
            ->get(route('admin.students-results.academics.classes'))
            ->assertForbidden();

        // And not only the page: the writes are gated too.
        $this->actingAs($teacher)
            ->post(route('admin.students-results.academics.classes.sections.store'), ['section_name' => 'Z'])
            ->assertForbidden();

        $this->assertSame(0, Section::query()->count());
    }

    /**
     * This page is named with an & in it, which is how the header was found printing
     * a title twice: Blade escapes a section's content when the section is defined,
     * so escaping it again on the way out showed the office "&amp;".
     */
    public function test_a_title_with_an_ampersand_in_it_is_escaped_once(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<title>Classes &amp; Sections', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Sections */
    /* ------------------------------------------------------------------ */

    public function test_a_section_is_added_as_written_and_read_back_as_a_letter(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.sections.store'), ['section_name' => ' a '])
            ->assertSessionHas('status');

        // " a " and "A" are the same section, not two.
        $this->assertSame(['A'], Section::query()->pluck('name')->all());
    }

    public function test_a_section_that_is_already_there_is_refused_with_the_reason(): void
    {
        Section::create(['name' => 'A', 'order' => 1]);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.sections.store'), ['section_name' => 'A'])
            ->assertSessionHasErrors(['section_name' => 'That section is already there.']);

        $this->assertSame(1, Section::query()->count());
    }

    public function test_an_unused_section_can_be_deleted(): void
    {
        $section = Section::create(['name' => 'D', 'order' => 4]);

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.classes.sections.destroy', $section))
            ->assertSessionHas('status');

        $this->assertFalse(Section::query()->where('id', $section->id)->exists());
    }

    /** A section's classes go with it, so it cannot go while it has any. */
    public function test_a_section_in_use_cannot_be_deleted_and_says_which_classes_use_it(): void
    {
        $section = Section::create(['name' => 'A', 'order' => 1]);
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $this->class($level, $section, 'JSS1A');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.classes.sections.destroy', $section))
            ->assertSessionHas('error', 'Section A is still used by 1 class. Delete or move those classes first.');

        $this->assertTrue(Section::query()->where('id', $section->id)->exists());
    }

    /* ------------------------------------------------------------------ */
    /* Class names */
    /* ------------------------------------------------------------------ */

    public function test_a_class_name_is_added_and_takes_the_next_position_by_default(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.names.store'), ['class_name' => 'jss1'])
            ->assertSessionHas('status');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.names.store'), ['class_name' => 'SS1'])
            ->assertSessionHas('status');

        $this->assertSame(['JSS1', 'SS1'], SchoolLevel::query()->orderBy('order')->pluck('name')->all());
        $this->assertSame([1, 2], SchoolLevel::query()->orderBy('order')->pluck('order')->all());
    }

    public function test_a_class_name_that_is_already_there_is_refused_with_the_reason(): void
    {
        SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.names.store'), ['class_name' => 'JSS1'])
            ->assertSessionHasErrors(['class_name' => 'That class is already there.']);

        $this->assertSame(1, SchoolLevel::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Renaming a class                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * JSS1A is JSS1 and section A put together, so renaming JSS1 has to rename its
     * classes as well — otherwise a class called SS1 would go on running JSS1A.
     */
    public function test_renaming_a_class_takes_its_classes_with_it(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);
        $this->class($level, $a, 'JSS1A');
        $this->class($level, $b, 'JSS1B');

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.academics.classes.names.update', $level), ['class_name' => 'JSS2'])
            ->assertSessionHas('status');

        $this->assertSame('JSS2', $level->refresh()->name);
        $this->assertSame(['JSS2A', 'JSS2B'], SchoolClass::query()->orderBy('name')->pluck('name')->all());
    }

    /**
     * The pencil opens the same form with that class in it, which is what the office
     * asked for: one screen for the job rather than a second one for editing.
     */
    public function test_the_pencil_loads_that_class_into_the_form(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes', ['edit' => $level->id]))
            ->assertOk()
            ->assertSee('Edit Class')
            ->assertSee('value="JSS1"', false)
            ->assertDontSee('Create Class');
    }

    public function test_renaming_a_class_onto_another_name_is_refused(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        SchoolLevel::create(['name' => 'JSS2', 'order' => 2]);
        $this->class($level, Section::create(['name' => 'A', 'order' => 1]), 'JSS1A');

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.academics.classes.names.update', $level), ['class_name' => 'JSS2'])
            ->assertSessionHas('error', 'JSS2 is already there.');

        // Nothing moved: the class and its class are as they were.
        $this->assertSame('JSS1', $level->refresh()->name);
        $this->assertSame(['JSS1A'], SchoolClass::query()->pluck('name')->all());
    }

    public function test_a_class_name_with_nothing_against_it_goes_and_takes_its_classes_with_it(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $section = Section::create(['name' => 'A', 'order' => 1]);
        $this->class($level, $section, 'JSS1A');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.classes.names.destroy', $level))
            ->assertSessionHas('status');

        $this->assertSame(0, SchoolLevel::query()->count());
        $this->assertSame(0, SchoolClass::query()->count());
    }

    public function test_a_class_name_holding_a_student_is_refused_and_names_what_is_in_the_way(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $section = Section::create(['name' => 'A', 'order' => 1]);
        $class = $this->class($level, $section, 'JSS1A');

        $this->student($class, $level);

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.classes.names.destroy', $level))
            ->assertSessionHas('error', 'JSS1 still has 1 student against it. Move them before deleting the class.');

        $this->assertTrue(SchoolLevel::query()->where('id', $level->id)->exists());
    }

    /** An applicant applies to a class name, so the class name cannot go under them. */
    public function test_a_class_name_applicants_applied_for_cannot_be_deleted(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        Applicant::create([
            'registration_number' => 'SAC-00001',
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
            'level_applied_for_id' => $level->id,
            'academic_session_id' => AcademicSession::create(['name' => '2026/2027', 'is_current' => true])->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.classes.names.destroy', $level))
            ->assertSessionHas('error', 'JSS1 still has 1 applicant against it. Move them before deleting the class.');
    }

    /* ------------------------------------------------------------------ */
    /* A class: a class name and a section */
    /* ------------------------------------------------------------------ */

    public function test_a_class_is_named_from_the_class_name_and_the_section(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);

        foreach ([$a, $b] as $section) {
            $this->actingAs($this->admin)
                ->post(route('admin.students-results.academics.classes.store-class', $level), [
                    'section_id' => $section->id,
                ])
                ->assertSessionHas('status');
        }

        $classes = SchoolClass::query()->orderBy('name')->get();

        $this->assertSame(['JSS1A', 'JSS1B'], $classes->pluck('name')->all());
        $this->assertSame([$level->id, $level->id], $classes->pluck('level_id')->all());
        $this->assertSame([$a->id, $b->id], $classes->pluck('section_id')->all());
    }

    public function test_the_same_class_cannot_be_made_twice(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $section = Section::create(['name' => 'A', 'order' => 1]);
        $this->class($level, $section, 'JSS1A');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.store-class', $level), [
                'section_id' => $section->id,
            ])
            ->assertSessionHas('error', 'JSS1A is already there.');

        $this->assertSame(1, SchoolClass::query()->count());
    }

    /** A class cannot be built out of a section that is not there. */
    public function test_a_class_cannot_be_made_from_a_section_that_does_not_exist(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.store-class', $level), ['section_id' => 99])
            ->assertSessionHasErrors(['section_id']);

        $this->assertSame(0, SchoolClass::query()->count());
    }

    public function test_an_empty_class_can_be_deleted(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $class = $this->class($level, Section::create(['name' => 'A', 'order' => 1]), 'JSS1A');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.classes.destroy-class', $class))
            ->assertSessionHas('status');

        $this->assertFalse(SchoolClass::query()->where('id', $class->id)->exists());
    }

    /** Deleting the empty class of a class name is fine — JSS1B that nobody sat in. */
    public function test_a_class_with_students_or_marks_cannot_be_deleted(): void
    {
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $term = Term::create(['name' => 'First Term', 'position' => 1, 'is_current' => true]);
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $class = $this->class($level, Section::create(['name' => 'A', 'order' => 1]), 'JSS1A');

        $this->student($class, $level, $session);

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.classes.destroy-class', $class))
            ->assertSessionHas('error', 'JSS1A still holds 1 student. Move them before deleting the class.');

        Assessment::create([
            'name' => 'CA1',
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'school_class_id' => $class->id,
            'subject_id' => Subject::create(['name' => 'Mathematics', 'code' => 'MTH'])->id,
        ]);

        // Now two different things are in the way, and both are named.
        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.classes.destroy-class', $class))
            ->assertSessionHas('error', 'JSS1A still holds 1 student and 1 assessment. Move them before deleting the class.');

        $this->assertTrue(SchoolClass::query()->where('id', $class->id)->exists());
    }

    /* ------------------------------------------------------------------ */

    private function class(SchoolLevel $level, Section $section, string $name): SchoolClass
    {
        return SchoolClass::create([
            'level_id' => $level->id,
            'section_id' => $section->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function student(SchoolClass $class, SchoolLevel $level, ?AcademicSession $session = null): Student
    {
        return Student::create([
            'student_number' => 'SAC/2026/'.str_pad((string) (Student::query()->count() + 1), 3, '0', STR_PAD_LEFT),
            'first_name' => 'Chidera',
            'last_name' => 'Okafor',
            'level_id' => $level->id,
            'school_class_id' => $class->id,
            'academic_session_id' => $session?->id ?? AcademicSession::create(['name' => '2027/2028'])->id,
        ]);
    }
}
