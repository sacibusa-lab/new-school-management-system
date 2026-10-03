<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\Invoice;
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
 * The students hub: the school read by what it owes.
 *
 * Three things it has to get right. It answers "which class is behind" before
 * anything is chosen, because that is the question the office arrives with. It reads
 * a class's children only when a class is named, because a list of every bill in the
 * school is a list nobody reads. And a child who has not been billed at all is shown
 * rather than left out — that child is the reason somebody opened the page.
 */
class FeeStudentsHubTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private Term $term;

    private SchoolLevel $level;

    private SchoolClass $classA;

    private SchoolClass $classB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
        ]);

        $this->term = Term::create(['name' => 'First Term', 'position' => 1, 'is_current' => true]);
        $this->level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);

        // A year group holds one class per section, so two arms need two sections.
        $sectionA = Section::create(['name' => 'A', 'order' => 1]);
        $sectionB = Section::create(['name' => 'B', 'order' => 2]);

        $this->classA = SchoolClass::create(['level_id' => $this->level->id, 'section_id' => $sectionA->id, 'name' => 'JSS1A', 'is_active' => true]);
        $this->classB = SchoolClass::create(['level_id' => $this->level->id, 'section_id' => $sectionB->id, 'name' => 'JSS1B', 'is_active' => true]);
    }

    public function test_it_reads_the_school_class_by_class_before_anything_is_chosen(): void
    {
        $junior = $this->student($this->classA, 'SAC/2026/1001');
        $this->student($this->classB, 'SAC/2026/1002', 'Ngozi');

        $this->invoice($junior, 100000, 40000);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub'))->assertOk()->getContent();

        $this->assertStringContainsString('JSS1A', $html);
        $this->assertStringContainsString('JSS1B', $html);

        // What was billed, what came in, and what is left, for the class it belongs to.
        $this->assertStringContainsString('100,000.00', $html);
        $this->assertStringContainsString('40,000.00', $html);
        $this->assertStringContainsString('60,000.00', $html);

        // JSS1B's child has no bill, so the class shows a child and no money.
        $this->assertStringContainsString('1 with no bill at all', $html);
    }

    /** Names come out only once a class is named. */
    public function test_it_does_not_list_children_until_a_class_is_chosen(): void
    {
        $child = $this->student($this->classA, 'SAC/2026/1001');
        $this->invoice($child, 100000, 0);

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertOk()
            ->assertDontSee($child->full_name);

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', ['class' => $this->classA->id]))
            ->assertOk()
            ->assertSee($child->full_name)
            ->assertSee($child->student_number);
    }

    public function test_a_class_reads_its_children_by_what_they_owe(): void
    {
        $owing = $this->student($this->classA, 'SAC/2026/1001');
        $settled = $this->student($this->classA, 'SAC/2026/1002', 'Ngozi');

        $this->invoice($owing, 100000, 40000);
        $this->invoice($settled, 50000, 50000);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', ['class' => $this->classA->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($owing->full_name, $html);
        $this->assertStringContainsString($settled->full_name, $html);

        // The one who still owes is on the page with their balance; the one who has
        // settled shows a zero rather than being dropped.
        $this->assertStringContainsString('60,000.00', $html);
        $this->assertStringContainsString('0.00', $html);
    }

    /**
     * A child nobody has billed is the reason somebody opened this page — so the page
     * says so rather than leaving them off it.
     */
    public function test_a_child_with_no_bill_is_shown_rather_than_left_out(): void
    {
        $unbilled = $this->student($this->classA, 'SAC/2026/1001');

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', ['class' => $this->classA->id]))
            ->assertOk()
            ->assertSee($unbilled->full_name)
            ->assertSee('No bill raised');
    }

    public function test_the_year_group_narrows_the_classes_and_lists_its_children(): void
    {
        $other = SchoolLevel::create(['name' => 'SS1', 'order' => 2, 'is_active' => true]);
        $section = Section::firstOrCreate(['name' => 'A'], ['order' => 1]);
        $otherClass = SchoolClass::create(['level_id' => $other->id, 'section_id' => $section->id, 'name' => 'SS1A', 'is_active' => true]);

        $junior = $this->student($this->classA, 'SAC/2026/1001');
        $senior = $this->student($otherClass, 'SAC/2026/1002', 'Chidi');

        $this->invoice($junior, 100000, 0);
        $this->invoice($senior, 100000, 0);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', ['level' => $this->level->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($junior->full_name, $html);

        // The senior is in another year group, so neither the child nor their class is
        // read. Asserted on the name rather than on the class's, because "SS1A" sits
        // inside "JSS1A" and would match the year group that was asked for.
        $this->assertStringNotContainsString($senior->full_name, $html);
        $this->assertStringNotContainsString('>SS1A<', $html);
    }

    public function test_somebody_outside_the_fee_desk_cannot_read_it(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    private function student(SchoolClass $class, string $number, string $first = 'Ada'): Student
    {
        return Student::create([
            'student_number' => $number,
            'admission_number' => 'SAC-'.str_replace(['/', '.'], '', $number),
            'first_name' => $first,
            'last_name' => 'Okonkwo',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
            'level_id' => $class->level_id,
            'school_class_id' => $class->id,
            'academic_session_id' => $this->session->id,
            'status' => StudentStatus::Active->value,
            'admitted_at' => now(),
        ]);
    }

    private function invoice(Student $student, float $total, float $paid): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV/2026/'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'student_id' => $student->id,
            'academic_session_id' => $this->session->id,
            'term_id' => $this->term->id,
            'subtotal' => $total,
            'discount' => 0,
            'total' => $total,
            'amount_paid' => $paid,
            'balance' => $total - $paid,
            'status' => $paid <= 0
                ? InvoiceStatus::Unpaid
                : ($paid >= $total ? InvoiceStatus::Paid : InvoiceStatus::Partial),
            'issued_at' => now(),
        ]);
    }
}
