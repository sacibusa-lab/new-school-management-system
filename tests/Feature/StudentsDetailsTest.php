<?php

namespace Tests\Feature;

use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\Invoice;
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
 * The module's own student register.
 *
 * Three things are worth checking here. The filter is two dropdowns that mean what
 * they say — a class is a year group plus a section, and the section is on the class
 * rather than on the student, which is the sort of join that looks right on one row
 * and wrong across a whole school. The fees column reads two sums rather than fifty
 * reads. And removal is a soft delete, so a child taken off by mistake is put back
 * rather than reconstructed.
 */
class StudentsDetailsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $year;

    private SchoolLevel $jss1;

    private SchoolLevel $jss2;

    private Section $sectionA;

    private Section $sectionB;

    private SchoolClass $jss1a;

    private SchoolClass $jss1b;

    private SchoolClass $jss2a;

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

        $this->sectionA = Section::create(['name' => 'A', 'order' => 1]);
        $this->sectionB = Section::create(['name' => 'B', 'order' => 2]);

        $this->jss1a = $this->schoolClass($this->jss1, $this->sectionA, 'JSS1A');
        $this->jss1b = $this->schoolClass($this->jss1, $this->sectionB, 'JSS1B');
        $this->jss2a = $this->schoolClass($this->jss2, $this->sectionA, 'JSS2A');
    }

    /* ------------------------------------------------------------------ */
    /* The list itself */
    /* ------------------------------------------------------------------ */

    public function test_the_register_lists_a_student_with_their_number_and_guardian(): void
    {
        $this->student($this->jss1a, 'Ada', 'Okonkwo', [
            'admission_number' => 'SAC/2026/014',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students'))
            ->assertOk()
            ->assertSee('Student List')
            ->assertSee('Ada Okonkwo')
            // No photograph on file, so the initials stand in for one rather than
            // leaving the column empty.
            ->assertSee('AO')
            ->assertSee('SAC/2026/014')
            ->assertSee('Mrs Okonkwo')
            ->assertSee('08031234567')
            ->assertSee('Fees Progress')
            ->assertSee('Bulk Delete');
    }

    public function test_the_register_does_not_open_for_someone_without_the_permission(): void
    {
        // No role at all, so no students.view. A roll of every child in the school is
        // the office's to hold, not the staff room's.
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('admin.students-results.students'))
            ->assertForbidden();
    }

    public function test_it_says_so_when_no_class_and_section_pair_matches(): void
    {
        $this->student($this->jss1a, 'Ada', 'Okonkwo');

        // JSS2 has no B in this school.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students', [
                'class' => $this->jss2->id,
                'section' => $this->sectionB->id,
            ]))
            ->assertOk()
            ->assertSee('Nobody to show')
            ->assertDontSee('Ada Okonkwo');
    }

    /* ------------------------------------------------------------------ */
    /* The two dropdowns */
    /* ------------------------------------------------------------------ */

    public function test_it_can_be_narrowed_to_one_class(): void
    {
        $this->student($this->jss1a, 'Ada', 'Okonkwo');
        $this->student($this->jss2a, 'Bola', 'Adeyemi');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students', ['class' => $this->jss1->id]))
            ->assertOk()
            ->assertSee('Ada Okonkwo')
            ->assertDontSee('Bola Adeyemi');
    }

    public function test_it_can_be_narrowed_to_one_section(): void
    {
        $this->student($this->jss1a, 'Ada', 'Okonkwo');
        $this->student($this->jss1b, 'Bola', 'Adeyemi');

        // The section is on the class, not on the student, so this is the join that
        // has to be read through the class they are in.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students', ['section' => $this->sectionA->id]))
            ->assertOk()
            ->assertSee('Ada Okonkwo')
            ->assertDontSee('Bola Adeyemi');
    }

    public function test_a_class_and_a_section_together_pick_out_one_class(): void
    {
        $this->student($this->jss1a, 'Ada', 'Okonkwo');
        $this->student($this->jss1b, 'Bola', 'Adeyemi');
        $this->student($this->jss2a, 'Chidi', 'Nwosu');

        // JSS1 and A together are JSS1A: not JSS1B, which shares the section, and not
        // JSS2A, which shares the class.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students', [
                'class' => $this->jss1->id,
                'section' => $this->sectionA->id,
            ]))
            ->assertOk()
            ->assertSee('Ada Okonkwo')
            ->assertDontSee('Bola Adeyemi')
            ->assertDontSee('Chidi Nwosu');
    }

    /* ------------------------------------------------------------------ */
    /* Fees progress */
    /* ------------------------------------------------------------------ */

    public function test_the_fees_column_shows_the_share_that_has_been_paid(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Okonkwo');

        $this->invoice($student, total: 100_000, paid: 25_000);

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students'))
            ->assertOk()
            ->assertSee('25%');
    }

    public function test_a_cancelled_invoice_is_left_out_of_the_fees_column(): void
    {
        $student = $this->student($this->jss1a, 'Ada', 'Okonkwo');

        $this->invoice($student, total: 100_000, paid: 50_000);
        $this->invoice($student, total: 900_000, paid: 0, status: 'cancelled');

        // 50,000 of 100,000 — the cancelled nine hundred thousand is not owed and must
        // not drag the figure down to nothing.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students'))
            ->assertOk()
            ->assertSee('50%');
    }

    public function test_a_student_with_no_invoice_is_not_reported_as_having_paid_nothing(): void
    {
        $this->student($this->jss1a, 'Ada', 'Okonkwo');

        // A confident 0% would say the family owes everything. Nothing raised is a
        // different thing from nothing paid.
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.students'))
            ->assertOk()
            ->assertSee('No invoice');
    }

    /* ------------------------------------------------------------------ */
    /* Taking somebody off the register */
    /* ------------------------------------------------------------------ */

    public function test_a_student_is_taken_off_the_register_by_the_bin_on_their_row(): void
    {
        $this->student($this->jss1a, 'Ada', 'Okonkwo');
        $gone = $this->student($this->jss1a, 'Bola', 'Adeyemi');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.students.destroy', $gone))
            ->assertRedirect()
            ->assertSessionHas('status', 'Bola Adeyemi was taken off the register.');

        // Kept, not destroyed: a child removed by mistake is put back rather than
        // reconstructed from paper.
        $this->assertSoftDeleted('students', ['id' => $gone->id]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.students-results.students'))
            ->assertOk()
            ->getContent();

        // Read against the table rather than the page: the flash from the delete names
        // the student, so the page as a whole still contains them. What matters is that
        // the row has gone.
        $table = str($html)->after('<tbody')->before('</tbody>')->value();

        $this->assertStringContainsString('Ada Okonkwo', $table);
        $this->assertStringNotContainsString('Bola Adeyemi', $table);
    }

    public function test_the_ticked_students_are_taken_off_together(): void
    {
        $ada = $this->student($this->jss1a, 'Ada', 'Okonkwo');
        $bola = $this->student($this->jss1a, 'Bola', 'Adeyemi');
        $chidi = $this->student($this->jss1a, 'Chidi', 'Nwosu');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.students.destroy-selected'), [
                'students' => [$ada->id, $bola->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '2 students were taken off the register.');

        $this->assertSoftDeleted('students', ['id' => $ada->id]);
        $this->assertSoftDeleted('students', ['id' => $bola->id]);

        // The one that was not ticked is untouched, and still on the register.
        $this->assertDatabaseHas('students', ['id' => $chidi->id, 'deleted_at' => null]);
    }

    public function test_ticking_nobody_is_refused_rather_than_quietly_doing_nothing(): void
    {
        $this->student($this->jss1a, 'Ada', 'Okonkwo');

        $this->actingAs($this->admin)
            ->delete(route('admin.students-results.students.destroy-selected'), ['students' => []])
            ->assertSessionHasErrors('students');

        $this->assertDatabaseCount('students', 1);
    }

    public function test_removing_a_student_is_closed_to_a_role_that_can_only_read_them(): void
    {
        // A teacher may see the roll — that is students.view — but taking a child off
        // it is not the same permission, and is not theirs to do.
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $student = $this->student($this->jss1a, 'Ada', 'Okonkwo');

        $this->actingAs($teacher)
            ->delete(route('admin.students-results.students.destroy', $student))
            ->assertForbidden();

        $this->actingAs($teacher)
            ->delete(route('admin.students-results.students.destroy-selected'), [
                'students' => [$student->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('students', ['id' => $student->id, 'deleted_at' => null]);
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    private function schoolClass(SchoolLevel $level, Section $section, string $name): SchoolClass
    {
        return SchoolClass::create([
            'level_id' => $level->id,
            'section_id' => $section->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /** @param  array<string,mixed>  $extra */
    private function student(SchoolClass $class, string $firstName, string $lastName, array $extra = []): Student
    {
        return Student::create($extra + [
            'student_number' => 'SAC/'.fake()->unique()->numberBetween(100000, 999999),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'level_id' => $class->level_id,
            'school_class_id' => $class->id,
            'academic_session_id' => $this->year->id,
            'status' => StudentStatus::Active->value,
        ]);
    }

    private function invoice(Student $student, float $total, float $paid, string $status = 'unpaid'): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV/'.fake()->unique()->numberBetween(100000, 999999),
            'student_id' => $student->id,
            'academic_session_id' => $this->year->id,
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => $paid,
            'balance' => $total - $paid,
            'status' => $status,
            'issued_at' => now(),
        ]);
    }
}
