<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermResult;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $year;

    private AcademicSession $nextYear;

    private SchoolClass $jss1a;

    private SchoolClass $jss2a;

    private SchoolClass $jss2b;

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
        $this->nextYear = AcademicSession::create([
            'name' => '2027/2028',
            'starts_on' => '2027-09-01',
        ]);

        $jss1 = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);
        $jss2 = SchoolLevel::create(['name' => 'JSS2', 'order' => 2, 'is_active' => true]);
        $sectionA = Section::create(['name' => 'A', 'order' => 1]);
        $sectionB = Section::create(['name' => 'B', 'order' => 2]);

        $this->jss1a = $this->schoolClass($jss1, $sectionA, 'JSS1A');
        $this->jss2a = $this->schoolClass($jss2, $sectionA, 'JSS2A');
        $this->jss2b = $this->schoolClass($jss2, $sectionB, 'JSS2B');
    }

    public function test_the_promotion_page_lists_a_class_with_each_student_and_their_average(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Student');
        $this->termResult($student, 62.5);

        $this->actingAs($this->admin)
            ->get($this->page($this->jss1a))
            ->assertOk()
            ->assertSee('Promotion')
            ->assertSee('2026/2027')
            ->assertSee('2027/2028')
            ->assertSee('Ada Student')
            ->assertSee('62.50')
            ->assertSee('Decision')
            ->assertSee('Move to')
            // JSS1A's default target is the same section one level up.
            ->assertSee('selected', false)
            ->assertSee('JSS2A');
    }

    public function test_a_promoted_student_moves_into_the_chosen_class_and_the_next_session(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Student');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.promotion.store'), [
                'school_class_id' => $this->jss1a->id,
                'from_session_id' => $this->year->id,
                'to_session_id' => $this->nextYear->id,
                'decisions' => [
                    $student->id => ['action' => 'promoted', 'class_id' => $this->jss2a->id],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $student->refresh();

        $this->assertSame($this->jss2a->id, $student->school_class_id);
        $this->assertSame($this->jss2a->level_id, $student->level_id);
        $this->assertSame($this->nextYear->id, $student->academic_session_id);

        $this->assertDatabaseHas('student_promotions', [
            'student_id' => $student->id,
            'from_academic_session_id' => $this->year->id,
            'to_academic_session_id' => $this->nextYear->id,
            'from_school_class_id' => $this->jss1a->id,
            'to_school_class_id' => $this->jss2a->id,
            'action' => 'promoted',
            'decided_by' => $this->admin->id,
        ]);
    }

    public function test_a_student_can_be_promoted_into_a_different_arm(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Student');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.promotion.store'), [
                'school_class_id' => $this->jss1a->id,
                'from_session_id' => $this->year->id,
                'to_session_id' => $this->nextYear->id,
                'decisions' => [
                    $student->id => ['action' => 'promoted', 'class_id' => $this->jss2b->id],
                ],
            ])
            ->assertSessionHas('status');

        // JSS1A into JSS2B: the year and the arm both change in one decision.
        $this->assertSame($this->jss2b->id, $student->refresh()->school_class_id);
    }

    public function test_a_repeating_student_sits_the_same_class_again_in_the_next_session(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Student');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.promotion.store'), [
                'school_class_id' => $this->jss1a->id,
                'from_session_id' => $this->year->id,
                'to_session_id' => $this->nextYear->id,
                'decisions' => [
                    $student->id => ['action' => 'repeated'],
                ],
            ])
            ->assertSessionHas('status');

        $student->refresh();

        $this->assertSame($this->jss1a->id, $student->school_class_id);
        $this->assertSame($this->nextYear->id, $student->academic_session_id);
        $this->assertDatabaseHas('student_promotions', [
            'student_id' => $student->id,
            'action' => 'repeated',
            'to_school_class_id' => $this->jss1a->id,
        ]);
    }

    public function test_a_student_who_left_keeps_their_session_and_is_marked_withdrawn(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Student');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.promotion.store'), [
                'school_class_id' => $this->jss1a->id,
                'from_session_id' => $this->year->id,
                'to_session_id' => $this->nextYear->id,
                'decisions' => [
                    $student->id => ['action' => 'withdrawn'],
                ],
            ])
            ->assertSessionHas('status');

        $student->refresh();

        // Still where they were: a student who has left must not turn up in next
        // year's register.
        $this->assertSame($this->year->id, $student->academic_session_id);
        $this->assertSame($this->jss1a->id, $student->school_class_id);
        $this->assertSame('withdrawn', $student->status->value);

        $this->assertDatabaseHas('student_promotions', [
            'student_id' => $student->id,
            'action' => 'withdrawn',
            'to_academic_session_id' => null,
            'to_school_class_id' => null,
        ]);
    }

    public function test_a_final_year_student_can_graduate(): void
    {
        $finalLevel = SchoolLevel::create(['name' => 'SS3', 'order' => 3, 'is_active' => true]);
        $section = Section::create(['name' => 'C', 'order' => 3]);
        $finalClass = $this->schoolClass($finalLevel, $section, 'SS3C');
        $student = $this->student($finalClass, 'Ada', 'Student');

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.promotion.store'), [
                'school_class_id' => $finalClass->id,
                'from_session_id' => $this->year->id,
                'to_session_id' => $this->nextYear->id,
                'decisions' => [
                    $student->id => ['action' => 'graduated'],
                ],
            ])
            ->assertSessionHas('status');

        $this->assertSame('graduated', $student->refresh()->status->value);
        $this->assertDatabaseHas('student_promotions', [
            'student_id' => $student->id,
            'action' => 'graduated',
        ]);
    }

    public function test_promoting_leaves_the_results_where_they_were_earned(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Student');
        $result = $this->termResult($student, 62.5);

        $this->actingAs($this->admin)
            ->post(route('admin.students-results.academics.promotion.store'), [
                'school_class_id' => $this->jss1a->id,
                'from_session_id' => $this->year->id,
                'to_session_id' => $this->nextYear->id,
                'decisions' => [
                    $student->id => ['action' => 'promoted', 'class_id' => $this->jss2a->id],
                ],
            ])
            ->assertSessionHas('status');

        // The report card still belongs to the year and class it was earned in.
        $this->assertSame($this->year->id, $result->refresh()->academic_session_id);
        $this->assertSame($this->jss1a->id, $result->school_class_id);
        $this->assertSame('62.50', $result->average);
    }

    public function test_promoting_requires_a_class_to_promote_into(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Student');

        $this->actingAs($this->admin)
            ->from($this->page($this->jss1a))
            ->post(route('admin.students-results.academics.promotion.store'), [
                'school_class_id' => $this->jss1a->id,
                'from_session_id' => $this->year->id,
                'to_session_id' => $this->nextYear->id,
                'decisions' => [
                    $student->id => ['action' => 'promoted', 'class_id' => null],
                ],
            ])
            ->assertSessionHasErrors('decisions');

        $this->assertDatabaseCount('student_promotions', 0);
        $this->assertSame($this->year->id, $student->refresh()->academic_session_id);
    }

    public function test_a_decision_cannot_be_taken_about_a_student_outside_the_class(): void
    {
        $mine = $this->student($this->jss1a, 'Ada', 'Student');
        $theirs = $this->student($this->jss2a, 'Bola', 'Pupil');

        $this->actingAs($this->admin)
            ->from($this->page($this->jss1a))
            ->post(route('admin.students-results.academics.promotion.store'), [
                'school_class_id' => $this->jss1a->id,
                'from_session_id' => $this->year->id,
                'to_session_id' => $this->nextYear->id,
                'decisions' => [
                    $mine->id => ['action' => 'repeated'],
                    $theirs->id => ['action' => 'withdrawn'],
                ],
            ])
            ->assertSessionHasErrors('decisions');

        // Nothing was written for either of them: the whole save is refused.
        $this->assertDatabaseCount('student_promotions', 0);
        $this->assertSame('active', $theirs->refresh()->status->value);
    }

    public function test_a_session_cannot_be_promoted_into_itself(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Student');

        $this->actingAs($this->admin)
            ->from($this->page($this->jss1a))
            ->post(route('admin.students-results.academics.promotion.store'), [
                'school_class_id' => $this->jss1a->id,
                'from_session_id' => $this->year->id,
                'to_session_id' => $this->year->id,
                'decisions' => [
                    $student->id => ['action' => 'repeated'],
                ],
            ])
            ->assertSessionHasErrors('to_session_id');

        $this->assertDatabaseCount('student_promotions', 0);
    }

    public function test_promoting_the_same_class_again_corrects_the_decision(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Student');

        $this->actingAs($this->admin)->post(route('admin.students-results.academics.promotion.store'), [
            'school_class_id' => $this->jss1a->id,
            'from_session_id' => $this->year->id,
            'to_session_id' => $this->nextYear->id,
            'decisions' => [
                $student->id => ['action' => 'promoted', 'class_id' => $this->jss2a->id],
            ],
        ])->assertSessionHas('status');

        $this->actingAs($this->admin)->post(route('admin.students-results.academics.promotion.store'), [
            'school_class_id' => $this->jss1a->id,
            'from_session_id' => $this->year->id,
            'to_session_id' => $this->nextYear->id,
            'decisions' => [
                $student->id => ['action' => 'repeated'],
            ],
        ])->assertSessionHas('status');

        // One decision, corrected — not two decisions side by side.
        $this->assertDatabaseCount('student_promotions', 1);
        $this->assertDatabaseHas('student_promotions', [
            'student_id' => $student->id,
            'action' => 'repeated',
            'from_school_class_id' => $this->jss1a->id,
            'to_school_class_id' => $this->jss1a->id,
        ]);
        $this->assertSame($this->jss1a->id, $student->refresh()->school_class_id);
    }

    public function test_users_without_academic_permission_cannot_view_or_save_promotions(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->get($this->page($this->jss1a))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->post(route('admin.students-results.academics.promotion.store'), [])
            ->assertForbidden();

        $this->assertDatabaseCount('student_promotions', 0);
    }

    private function page(SchoolClass $class): string
    {
        return route('admin.students-results.academics.promotion', [
            'from' => $this->year->id,
            'to' => $this->nextYear->id,
            'class' => $class->id,
        ]);
    }

    private function schoolClass(SchoolLevel $level, Section $section, string $name): SchoolClass
    {
        return SchoolClass::create([
            'level_id' => $level->id,
            'section_id' => $section->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function student(SchoolClass $class, string $firstName, string $lastName): Student
    {
        return Student::create([
            'student_number' => 'SAC/'.fake()->unique()->numberBetween(100000, 999999),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'level_id' => $class->level_id,
            'school_class_id' => $class->id,
            'academic_session_id' => $this->year->id,
        ]);
    }

    private function termResult(Student $student, float $average): TermResult
    {
        $term = Term::create([
            'academic_session_id' => $this->year->id,
            'name' => 'First Term',
            'position' => 1,
        ]);

        return TermResult::create([
            'student_id' => $student->id,
            'academic_session_id' => $this->year->id,
            'term_id' => $term->id,
            'school_class_id' => $student->school_class_id,
            'total_score' => 500,
            'average' => $average,
        ]);
    }
}
