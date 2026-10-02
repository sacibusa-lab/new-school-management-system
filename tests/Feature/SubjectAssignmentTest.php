<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectAssignmentTest extends TestCase
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

    public function test_the_assignment_page_has_assign_and_list_views(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.subjects.assignments'))
            ->assertOk()
            // The page opens on the register of what is already assigned rather than
            // on the form — the Sl column and the search box belong to the list alone.
            ->assertSee('Sl')
            ->assertSee('Search...')
            ->assertSee('Assign List')
            ->assertSee('Assign');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.subjects.assignments', ['tab' => 'assign']))
            ->assertOk()
            ->assertDontSee('Search...')
            ->assertSee('Class')
            ->assertSee('Section')
            ->assertSee('Subjects');
    }

    public function test_active_subjects_are_assigned_to_the_selected_class_for_the_current_session(): void
    {
        $session = $this->createAcademicSession('2026/2027', true);
        $schoolClass = $this->schoolClass('JSS1', 'A');
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true]);
        $teacher = User::factory()->create(['name' => 'Ada Teacher']);
        $teacher->assignRole('Teacher');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.subjects.assignments.store'), [
                'level_id' => $schoolClass->level_id,
                'section_id' => $schoolClass->section_id,
                'subject_ids' => [$subject->id],
                'teachers' => [$subject->id => $teacher->id],
            ])
            ->assertRedirect(route('admin.students-results.academics.subjects.assignments', ['tab' => 'list']))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('class_subject', [
            'school_class_id' => $schoolClass->id,
            'subject_id' => $subject->id,
            'academic_session_id' => $session->id,
            'teacher_id' => $teacher->id,
        ]);

        // The page opens on the list, which names the class, its section, the
        // subjects on it, and the teacher taking each one.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.subjects.assignments'))
            ->assertOk()
            ->assertSee('JSS1')
            ->assertSee('A')
            ->assertSee('Mathematics')
            ->assertSee('Ada Teacher');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.subjects.assignments', ['tab' => 'assign']))
            ->assertOk()
            ->assertSee('Ada Teacher');
    }

    public function test_a_section_that_does_not_belong_to_the_selected_class_is_rejected(): void
    {
        $this->createAcademicSession('2026/2027', true);
        $firstClass = $this->schoolClass('JSS1', 'A');
        $otherClass = $this->schoolClass('JSS2', 'B');
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true]);

        $this->actingAs($this->admin)
            ->from(route('admin.students-results.academics.subjects.assignments', ['tab' => 'assign']))
            ->post(route('admin.students-results.academics.subjects.assignments.store'), [
                'level_id' => $firstClass->level_id,
                'section_id' => $otherClass->section_id,
                'subject_ids' => [$subject->id],
            ])
            ->assertSessionHasErrors('section_id');

        $this->assertDatabaseCount('class_subject', 0);
    }

    public function test_inactive_subjects_cannot_be_added_to_a_class(): void
    {
        $this->createAcademicSession('2026/2027', true);
        $schoolClass = $this->schoolClass('JSS1', 'A');
        $subject = Subject::create(['name' => 'Old Subject', 'code' => 'OLD', 'is_active' => false]);

        $this->actingAs($this->admin)
            ->from(route('admin.students-results.academics.subjects.assignments', ['tab' => 'assign']))
            ->post(route('admin.students-results.academics.subjects.assignments.store'), [
                'level_id' => $schoolClass->level_id,
                'section_id' => $schoolClass->section_id,
                'subject_ids' => [$subject->id],
            ])
            ->assertSessionHasErrors('subject_ids.0');

        $this->assertDatabaseCount('class_subject', 0);
    }

    public function test_a_user_without_the_teacher_role_cannot_be_assigned_to_a_subject(): void
    {
        $this->createAcademicSession('2026/2027', true);
        $schoolClass = $this->schoolClass('JSS1', 'A');
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true]);
        $staffMember = User::factory()->create();

        $this->actingAs($this->admin)
            ->from(route('admin.students-results.academics.subjects.assignments', ['tab' => 'assign']))
            ->post(route('admin.students-results.academics.subjects.assignments.store'), [
                'level_id' => $schoolClass->level_id,
                'section_id' => $schoolClass->section_id,
                'subject_ids' => [$subject->id],
                'teachers' => [$subject->id => $staffMember->id],
            ])
            ->assertSessionHasErrors('teachers');

        $this->assertDatabaseCount('class_subject', 0);
    }

    public function test_updating_assignments_changes_only_the_current_session(): void
    {
        $previousSession = $this->createAcademicSession('2025/2026', false);
        $currentSession = $this->createAcademicSession('2026/2027', true);
        $schoolClass = $this->schoolClass('JSS1', 'A');
        $english = Subject::create(['name' => 'English Language', 'code' => 'ENG', 'is_active' => true]);
        $mathematics = Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true]);
        $previousTeacher = User::factory()->create();
        $previousTeacher->assignRole('Teacher');
        $currentTeacher = User::factory()->create();
        $currentTeacher->assignRole('Teacher');
        $this->attach($schoolClass, $english, $previousSession, $previousTeacher->id);
        $this->attach($schoolClass, $mathematics, $currentSession);

        $this->actingAs($this->admin)
            ->put(route('admin.students-results.academics.subjects.assignments.update', $schoolClass), [
                'subject_ids' => [$english->id],
                'teachers' => [$english->id => $currentTeacher->id],
            ])
            ->assertRedirect(route('admin.students-results.academics.subjects.assignments', ['tab' => 'list']));

        $this->assertDatabaseHas('class_subject', [
            'school_class_id' => $schoolClass->id,
            'subject_id' => $english->id,
            'academic_session_id' => $previousSession->id,
            'teacher_id' => $previousTeacher->id,
        ]);
        $this->assertDatabaseHas('class_subject', [
            'school_class_id' => $schoolClass->id,
            'subject_id' => $english->id,
            'academic_session_id' => $currentSession->id,
            'teacher_id' => $currentTeacher->id,
        ]);
        $this->assertDatabaseMissing('class_subject', [
            'school_class_id' => $schoolClass->id,
            'subject_id' => $mathematics->id,
            'academic_session_id' => $currentSession->id,
        ]);
    }

    public function test_removing_an_assignment_does_not_remove_previous_session_rows(): void
    {
        $previousSession = $this->createAcademicSession('2025/2026', false);
        $currentSession = $this->createAcademicSession('2026/2027', true);
        $schoolClass = $this->schoolClass('JSS1', 'A');
        $subject = Subject::create(['name' => 'Mathematics', 'code' => 'MTH', 'is_active' => true]);
        $this->attach($schoolClass, $subject, $previousSession);
        $this->attach($schoolClass, $subject, $currentSession);

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.academics.subjects.assignments.destroy', $schoolClass))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('class_subject', [
            'school_class_id' => $schoolClass->id,
            'subject_id' => $subject->id,
            'academic_session_id' => $previousSession->id,
        ]);
        $this->assertDatabaseMissing('class_subject', [
            'school_class_id' => $schoolClass->id,
            'subject_id' => $subject->id,
            'academic_session_id' => $currentSession->id,
        ]);
    }

    public function test_users_without_academic_permission_cannot_view_or_save_assignments(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->get(route('admin.students-results.academics.subjects.assignments'))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->post(route('admin.students-results.academics.subjects.assignments.store'), [])
            ->assertForbidden();

        $this->assertDatabaseCount('class_subject', 0);
    }

    private function createAcademicSession(string $name, bool $isCurrent): AcademicSession
    {
        return AcademicSession::create([
            'name' => $name,
            'is_current' => $isCurrent,
        ]);
    }

    private function schoolClass(string $levelName, string $sectionName): SchoolClass
    {
        $level = SchoolLevel::create([
            'name' => $levelName,
            'order' => 1,
            'is_active' => true,
        ]);
        $section = Section::create([
            'name' => $sectionName,
            'order' => 1,
        ]);

        return SchoolClass::create([
            'level_id' => $level->id,
            'section_id' => $section->id,
            'name' => $levelName.$sectionName,
            'is_active' => true,
        ]);
    }

    private function attach(
        SchoolClass $schoolClass,
        Subject $subject,
        AcademicSession $session,
        ?int $teacherId = null,
    ): void {
        DB::table('class_subject')->insert([
            'school_class_id' => $schoolClass->id,
            'subject_id' => $subject->id,
            'academic_session_id' => $session->id,
            'teacher_id' => $teacherId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
