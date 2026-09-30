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
            ->assertSee('Class List')
            ->assertSee('Create Section')
            ->assertSee('Section List');
    }

    /** The arrangement the office drew: #, class name, its sections, then actions. */
    public function test_the_class_list_is_a_table_of_names_with_their_sections(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);
        $this->class($level, $a, 'JSS1A');
        $this->class($level, $b, 'JSS1B');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes'))
            ->assertOk()
            ->assertSee('Class Name')
            ->assertSee('Action')
            ->assertSee('JSS1')
            ->assertSee('A')
            ->assertSee('B');
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
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes', ['tab' => 'teacher']))
            ->assertOk()
            ->assertSee("x-data=\"{ tab: 'teacher' }\"", false);
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

        $this->actingAs($teacher)
            ->post(route('admin.students-results.academics.classes.teacher.store'), [
                'level_id' => 1, 'section_id' => 1, 'form_teacher_id' => 1,
            ])
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

    /**
     * The form is a class name and a section, which is what a class is — so typing
     * JSS1 and picking A makes JSS1A, and the class name comes into being with it.
     */
    public function test_a_class_is_made_from_the_name_and_the_section_in_one_step(): void
    {
        $a = Section::create(['name' => 'A', 'order' => 1]);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.names.store'), [
                'class_name' => 'jss1',
                'section_id' => $a->id,
            ])
            ->assertSessionHas('status');

        $level = SchoolLevel::query()->sole();

        $this->assertSame('JSS1', $level->name);
        $this->assertSame(['JSS1A'], SchoolClass::query()->pluck('name')->all());
        $this->assertSame($level->id, SchoolClass::query()->sole()->level_id);
    }

    /** The second section of a class that is already there: JSS1 + B is JSS1B. */
    public function test_the_same_class_name_picks_up_another_section(): void
    {
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);

        foreach ([$a, $b] as $section) {
            $this->actingAs($this->admin)
                ->post(route('admin.students-results.academics.classes.names.store'), [
                    'class_name' => 'JSS1',
                    'section_id' => $section->id,
                ])
                ->assertSessionHas('status');
        }

        // One class name, two classes — not two class names.
        $this->assertSame(1, SchoolLevel::query()->count());
        $this->assertSame(['JSS1A', 'JSS1B'], SchoolClass::query()->orderBy('name')->pluck('name')->all());
    }

    public function test_the_same_class_cannot_be_made_twice(): void
    {
        $a = Section::create(['name' => 'A', 'order' => 1]);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.names.store'), ['class_name' => 'JSS1', 'section_id' => $a->id])
            ->assertSessionHas('status');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.names.store'), ['class_name' => 'JSS1', 'section_id' => $a->id])
            ->assertSessionHas('error', 'JSS1A is already there.');

        $this->assertSame(1, SchoolClass::query()->count());
    }

    /** A class cannot be built out of a section that is not there. */
    public function test_a_class_cannot_be_made_from_a_section_that_does_not_exist(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.names.store'), ['class_name' => 'JSS1', 'section_id' => 99])
            ->assertSessionHasErrors(['section_id']);

        $this->assertSame(0, SchoolLevel::query()->count());
        $this->assertSame(0, SchoolClass::query()->count());
    }

    /** With no sections there is nothing to build a class from, and the page says so. */
    public function test_the_form_says_where_to_go_when_there_are_no_sections_yet(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes'))
            ->assertOk()
            ->assertSee('No sections yet')
            ->assertSee('Create one on the')
            ->assertDontSee('name="section_id"', false);
    }

    /* ------------------------------------------------------------------ */
    /* Renaming a class */
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
            ->put(route('admin.students-results.academics.classes.names.update', $level), [
                'class_name' => 'JSS2',
                // Both sections are kept: the form submits the set it should end up with.
                'sections' => [$a->id, $b->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('JSS2', $level->refresh()->name);
        $this->assertSame(['JSS2A', 'JSS2B'], SchoolClass::query()->orderBy('name')->pluck('name')->all());
    }

    /**
     * The pencil opens an Edit Class tab with that row in it: the name, and the
     * sections it has as tags.
     */
    public function test_the_pencil_opens_the_edit_tab_with_that_class_in_it(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);
        $this->class($level, $a, 'JSS1A');
        $this->class($level, $b, 'JSS1B');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes', ['edit' => $level->id]))
            ->assertOk()
            ->assertSee('Edit Class')
            ->assertSee('Update')
            ->assertSee('value="JSS1"', false)
            // The sections it has are in the tag box, and the ones it has not are in
            // the picker for adding them.
            ->assertSee('name="sections[]"', false)
            ->assertSee('+ add a section')
            // It opens on the Edit tab. The other two are still in the page, hidden
            // behind the tabs, so what says "this is the one showing" is the form that
            // carries the section set.
            ->assertSee("tab: 'edit'", false)
            ->assertSee('form="edit-class"', false);
    }

    /**
     * Update is one submit: the name, and the sections the class should end up with.
     * Adding a section here is the same act as adding it from the Class tab, and
     * dropping a tag is the same act as deleting that class.
     */
    public function test_update_renames_the_class_and_sets_which_sections_it_has(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);
        $c = Section::create(['name' => 'C', 'order' => 3]);
        $this->class($level, $a, 'JSS1A');
        $this->class($level, $b, 'JSS1B');

        // Renamed, B dropped, C added.
        $this->actingAs($this->admin)
            ->put(route('admin.students-results.academics.classes.names.update', $level), [
                'class_name' => 'JSS2',
                'sections' => [$a->id, $c->id],
            ])
            ->assertSessionHas('status');

        $this->assertSame('JSS2', $level->refresh()->name);
        $this->assertSame(['JSS2A', 'JSS2C'], SchoolClass::query()->orderBy('name')->pluck('name')->all());
    }

    /**
     * A section that cannot be dropped is reported and the rest of the form is saved:
     * one class holding children is no reason to refuse the rename next to it.
     */
    public function test_a_section_that_cannot_be_dropped_is_reported_and_the_rest_is_saved(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);
        $classA = $this->class($level, $a, 'JSS1A');
        $this->class($level, $b, 'JSS1B');
        $this->student($classA, $level);

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.academics.classes.names.update', $level), [
                'class_name' => 'JSS2',
                // Both sections are being dropped; A holds the child.
                'sections' => [],
            ])
            ->assertSessionHas('warning', 'JSS2A still holds 1 student. Move them before deleting the class.');

        // The rename and the empty section's removal went through.
        $this->assertSame('JSS2', $level->refresh()->name);
        $this->assertSame(['JSS2A'], SchoolClass::query()->pluck('name')->all());
    }

    public function test_renaming_a_class_onto_another_name_is_refused(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        SchoolLevel::create(['name' => 'JSS2', 'order' => 2]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $this->class($level, $a, 'JSS1A');

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.academics.classes.names.update', $level), [
                'class_name' => 'JSS2',
                'sections' => [],
            ])
            ->assertSessionHas('error', 'JSS2 is already there.');

        // Nothing moved: not the name, and not the section that was being dropped.
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

    public function test_the_same_class_cannot_be_made_twice_by_the_row_form(): void
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
    /* The class teacher of a class */
    /* ------------------------------------------------------------------ */

    /**
     * The tab is the office's two panels: the allocation form, and the list it
     * produces. A class nobody has been given reads as unset rather than as a blank
     * cell, and which school the allocation belongs to is named on every row.
     */
    public function test_the_class_teacher_tab_is_an_allocation_form_and_the_list_it_fills(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);
        $classA = $this->class($level, $a, 'JSS1A');
        $this->class($level, $b, 'JSS1B');
        $teacher = $this->teacher();

        $classA->update(['form_teacher_id' => $teacher->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes', ['tab' => 'teacher']))
            ->assertOk()
            ->assertSee("x-data=\"{ tab: 'teacher' }\"", false)
            ->assertSee('Class Teacher Allocation')
            ->assertSee('Class Teacher List')
            ->assertSee('name="level_id"', false)
            ->assertSee('name="section_id"', false)
            ->assertSee('name="form_teacher_id"', false)
            ->assertSee('Branch')
            ->assertSee(Setting::get('school_name'))
            ->assertSee($teacher->name)
            // JSS1A has one, JSS1B is the row that has not.
            ->assertSee($level->name)
            ->assertSee('Not set');
    }

    /**
     * The class is named the way the school says it — the class, then the section —
     * and that pair is the class the teacher is put in charge of. Its neighbour is
     * left alone: JSS1A having one says nothing whatever about JSS1B.
     */
    public function test_a_class_teacher_is_allocated_to_the_class_the_form_names(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);
        $classA = $this->class($level, $a, 'JSS1A');
        $classB = $this->class($level, $b, 'JSS1B');
        $teacher = $this->teacher();

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.teacher.store'), [
                'level_id' => $level->id,
                'section_id' => $a->id,
                'form_teacher_id' => $teacher->id,
            ])
            ->assertRedirect(route('admin.students-results.academics.classes', ['tab' => 'teacher']))
            ->assertSessionHas('status', "{$teacher->name} is now the class teacher of JSS1A.");

        $this->assertSame($teacher->id, $classA->refresh()->form_teacher_id);
        $this->assertNull($classB->refresh()->form_teacher_id);
    }

    /**
     * Allocating to a class that already has one replaces them. That is what the
     * form means — and it is why the pencil is only a way of loading a row of it.
     */
    public function test_allocating_again_replaces_the_teacher_who_was_there(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $class = $this->class($level, $a, 'JSS1A');

        $first = $this->teacher('Chidera Okafor');
        $second = $this->teacher('Ngozi Eze');

        $class->update(['form_teacher_id' => $first->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.teacher.store'), [
                'level_id' => $level->id,
                'section_id' => $a->id,
                'form_teacher_id' => $second->id,
            ])
            ->assertSessionHas('status', 'Ngozi Eze is now the class teacher of JSS1A.');

        $this->assertSame($second->id, $class->refresh()->form_teacher_id);
    }

    /**
     * JSS1 and C make nothing at all, and a teacher cannot be put in charge of a
     * class that does not exist. The office is told which one is missing rather than
     * having a class quietly invented for them.
     */
    public function test_a_class_teacher_cannot_be_allocated_to_a_class_that_is_not_there(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $c = Section::create(['name' => 'C', 'order' => 3]);
        $this->class($level, $a, 'JSS1A');
        $teacher = $this->teacher();

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.teacher.store'), [
                'level_id' => $level->id,
                'section_id' => $c->id,
                'form_teacher_id' => $teacher->id,
            ])
            ->assertSessionHas('error', 'JSS1C is not a class yet. Create it on the Class tab first.');

        $this->assertSame(['JSS1A'], SchoolClass::query()->pluck('name')->all());
    }

    /**
     * A class between teachers is left empty rather than keeping the name of the one
     * who has gone, which is a record of somebody who is not there any more.
     */
    public function test_the_class_teacher_can_be_taken_off_a_class(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $class = $this->class($level, $a, 'JSS1A');
        $class->update(['form_teacher_id' => $this->teacher()->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.classes.teacher.destroy', $class))
            ->assertRedirect(route('admin.students-results.academics.classes', ['tab' => 'teacher']))
            ->assertSessionHas('status', 'JSS1A has no class teacher now.');

        $this->assertNull($class->refresh()->form_teacher_id);
    }

    /**
     * The form offers teachers, so the guard behind it has to mean teachers too: a
     * bursar posting the id of their own account must be refused rather than quietly
     * put in charge of a class.
     */
    public function test_an_account_that_is_not_a_teacher_cannot_be_given_a_class(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $class = $this->class($level, $a, 'JSS1A');

        $bursar = User::factory()->create(['name' => 'Ngozi the bursar']);
        $bursar->assignRole('Bursar / Accounts');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.teacher.store'), [
                'level_id' => $level->id,
                'section_id' => $a->id,
                'form_teacher_id' => $bursar->id,
            ])
            ->assertSessionHas('error', 'Ngozi the bursar is not a teacher. Add them on the Add Teachers page first.');

        $this->assertNull($class->refresh()->form_teacher_id);
    }

    /**
     * The class, the section and the teacher all have to be named: half an
     * allocation is not one, and the form says so field by field.
     */
    public function test_the_allocation_form_asks_for_all_three(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $this->class($level, $a, 'JSS1A');
        $this->teacher();

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.classes.teacher.store'), [])
            ->assertSessionHasErrors(['level_id', 'section_id', 'form_teacher_id']);
    }

    /** The pencil fills the allocation form with that row, on the same tab. */
    public function test_the_pencil_fills_the_allocation_form_with_that_class(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $b = Section::create(['name' => 'B', 'order' => 2]);
        $classA = $this->class($level, $a, 'JSS1A');
        $classB = $this->class($level, $b, 'JSS1B');
        $teacher = $this->teacher();

        $classB->update(['form_teacher_id' => $teacher->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes', ['teacher' => $classB->id]))
            ->assertOk()
            ->assertSee("x-data=\"{ tab: 'teacher' }\"", false)
            ->assertSee('value="'.$level->id.'" selected', false)
            ->assertSee('value="'.$b->id.'" selected', false)
            ->assertSee('value="'.$teacher->id.'" selected', false);
    }

    /**
     * Nobody on the staff yet: the allocation form is replaced by the way to fix
     * that, and the list stays — which classes still have no class teacher is the
     * thing worth knowing in that state.
     */
    public function test_the_class_teacher_tab_says_where_to_go_when_there_are_no_teachers_yet(): void
    {
        $level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1]);
        $a = Section::create(['name' => 'A', 'order' => 1]);
        $this->class($level, $a, 'JSS1A');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes', ['tab' => 'teacher']))
            ->assertOk()
            ->assertSee('No teachers yet')
            ->assertSee('Add a teacher')
            ->assertSee('Class Teacher List')
            ->assertDontSee('name="form_teacher_id"', false);
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

    private function teacher(string $name = 'Chidera Okafor'): User
    {
        $teacher = User::factory()->create(['name' => $name]);
        $teacher->assignRole('Teacher');

        return $teacher;
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
