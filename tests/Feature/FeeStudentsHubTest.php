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
use App\Models\StudentVirtualAccount;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The students hub: the roll read one child at a time.
 *
 * Four things it has to get right. It carries the two things the office is asked about
 * across a counter — the account number a child's fees go into, and how far those fees
 * have got. It opens on the active roll, because that is who is meant by "the
 * students", and widening it is a click. It can be found from either number a child is
 * known by, because the parent on the telephone is holding one of them. And a child
 * nobody has billed is shown as such rather than as owing everything — those are two
 * different telephone calls, and only one of them is the school's fault.
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

    private SchoolClass $seniorClass;

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

        // A second year group, sharing section A with the first: that is what makes the
        // section filter worth testing on its own rather than as a pair with a class.
        $senior = SchoolLevel::create(['name' => 'SS1', 'order' => 2, 'is_active' => true]);

        $this->seniorClass = SchoolClass::create(['level_id' => $senior->id, 'section_id' => $sectionA->id, 'name' => 'SS1A', 'is_active' => true]);
    }

    public function test_it_reads_the_whole_active_roll_whichever_class_they_are_in(): void
    {
        $junior = $this->student($this->classA, 'SAC/2026/1001');
        $second = $this->student($this->classB, 'SAC/2026/1002', 'Ngozi');
        $senior = $this->student($this->seniorClass, 'SAC/2026/1003', 'Chidi');

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertOk()
            ->assertSee($junior->full_name)
            ->assertSee($second->full_name)
            ->assertSee($senior->full_name)
            // The count in the heading is the count of what is on the page.
            ->assertSee('3 children match');
    }

    public function test_a_year_group_narrows_the_roll(): void
    {
        $junior = $this->student($this->classA, 'SAC/2026/1001');
        $senior = $this->student($this->seniorClass, 'SAC/2026/1002', 'Chidi');

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', ['class' => $this->level->id.':0']))
            ->assertOk()
            ->assertSee($junior->full_name)
            ->assertDontSee($senior->full_name)
            ->getContent();

        // "SS1A" sits inside "JSS1A", so the year group is asserted on the counts
        // rather than on a class name that would match the one that was asked for.
        $this->assertStringContainsString('1 child match', $html);
    }

    /**
     * A class is a year group and a section together, so the filter asks for both at once.
     *
     * SS1A shares its letter with JSS1A. An arm chosen under JSS1 is a choice about JSS1,
     * so SS1A is not in the answer — the opposite of what this filter did while the section
     * was a control of its own, and the reason it is not one now.
     */
    public function test_an_arm_narrows_the_roll_to_that_class_alone(): void
    {
        $sectionA = Section::query()->where('name', 'A')->sole();

        $junior = $this->student($this->classA, 'SAC/2026/1001');
        $other = $this->student($this->classB, 'SAC/2026/1002', 'Ngozi');
        $senior = $this->student($this->seniorClass, 'SAC/2026/1003', 'Chidi');

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', [
                'class' => $this->level->id.':'.$sectionA->id,
            ]))
            ->assertOk()
            ->assertSee($junior->full_name)
            ->assertDontSee($other->full_name)
            ->assertDontSee($senior->full_name);
    }

    /**
     * The list is drawn under the year group, and every year group is offered whole as well
     * as arm by arm: "all of JSS1" is a question the office asks, and a list of arms cannot
     * express it.
     */
    public function test_the_class_filter_is_grouped_by_year_group(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<optgroup label="JSS1">', $html);
        $this->assertStringContainsString('<optgroup label="SS1">', $html);
        $this->assertStringContainsString('All of JSS1', $html);

        // The five options JSS1 offers are the year group whole and its two arms, and
        // nothing belonging to SS1 is filed under the JSS1 heading.
        $this->assertSame(3, substr_count($html, '<option value="'.$this->level->id.':'));
    }

    public function test_a_child_can_be_found_by_name_or_by_admission_number(): void
    {
        $wanted = $this->student($this->classA, 'SAC/2026/1001', 'Ngozi');
        $other = $this->student($this->classB, 'SAC/2026/1002');

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', ['q' => 'Ngozi']))
            ->assertOk()
            ->assertSee($wanted->full_name)
            ->assertDontSee($other->full_name);

        // The office has a parent on the telephone holding the number they were given
        // when the child was admitted.
        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', ['q' => 'SAC/2026/1002']))
            ->assertOk()
            ->assertSee($other->full_name)
            ->assertDontSee($wanted->full_name);
    }

    public function test_each_child_shows_what_they_still_owe_and_how_far_they_have_got(): void
    {
        $partly = $this->student($this->classA, 'SAC/2026/1001');
        $settled = $this->student($this->classB, 'SAC/2026/1002', 'Ngozi');

        $this->invoice($partly, 100000, 40000);
        $this->invoice($settled, 50000, 50000);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertOk()
            ->assertSee($partly->full_name)
            ->assertSee($settled->full_name)
            ->getContent();

        // What is left, and what it is left of.
        $this->assertStringContainsString('60,000.00', $html);
        $this->assertStringContainsString('100,000', $html);

        // Three states, not two: the one who has part-paid is told apart from the one
        // who has never paid, and both from the one who has settled.
        $this->assertStringContainsString('Part paid', $html);
        $this->assertStringContainsString('Settled', $html);
    }

    /**
     * A child nobody has billed is not a child who owes everything. A red pill on them
     * would be the page's mistake, and would send somebody to the wrong parent.
     */
    public function test_a_child_nobody_has_billed_says_so_rather_than_owing_everything(): void
    {
        $unbilled = $this->student($this->classA, 'SAC/2026/1001');

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertOk()
            ->assertSee($unbilled->full_name)
            ->assertSee('No bill')
            ->assertSee('Nothing billed');
    }

    public function test_the_account_number_a_child_pays_into_is_shown(): void
    {
        $withAccount = $this->student($this->classA, 'SAC/2026/1001');
        $without = $this->student($this->classB, 'SAC/2026/1002', 'Ngozi');

        StudentVirtualAccount::create([
            'student_id' => $withAccount->id,
            'customer_code' => 'CUS_existing',
            'bank_name' => 'Wema Bank',
            'account_number' => '1111111111',
            'account_name' => 'SACI SCHOOLS - ADA',
            'provider' => 'paystack',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertOk()
            ->assertSee('1111111111')
            ->assertSee('Wema Bank')
            // A child with nowhere to pay is marked rather than left blank, because
            // that is the line somebody acts on.
            ->assertSee('Not generated')
            ->assertSee($without->full_name);
    }

    /**
     * The page opens on the children who are on the roll. A child who has graduated is
     * off it, and has to be asked for — but asking for them has to work, because the
     * office still gets calls about them.
     */
    public function test_the_roll_opens_on_active_students_and_widens_when_asked(): void
    {
        $graduated = $this->student($this->classA, 'SAC/2026/1001', 'Ada', StudentStatus::Graduated);

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertOk()
            ->assertDontSee($graduated->full_name);

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', ['status' => StudentStatus::Graduated->value]))
            ->assertOk()
            ->assertSee($graduated->full_name);

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub', ['status' => '']))
            ->assertOk()
            ->assertSee($graduated->full_name);
    }

    public function test_somebody_outside_the_fee_desk_cannot_read_it(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertForbidden();
    }

    /**
     * The hub is read across a counter, and a face is what tells the office they have the
     * right child in front of them. The initials are a fallback for a child nobody has
     * photographed yet, not a replacement for the photograph.
     */
    public function test_the_photograph_is_shown_where_there_is_one(): void
    {
        $photographed = $this->student($this->classA, 'SAC/2026/1001');
        $photographed->update(['photo_path' => 'photos/students/ada.jpg']);

        $unphotographed = $this->student($this->classB, 'SAC/2026/1002', 'Zubairu');

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.students-hub'))
            ->assertOk()
            // asset('storage/…') rather than Storage::url(), which builds the host from
            // APP_URL and 404s on the host the office actually browses.
            ->assertSee('storage/photos/students/ada.jpg', false)
            ->assertSee('Photograph of '.$photographed->full_name)
            // Still something rather than a gap for the one with no photograph.
            ->assertSee($unphotographed->initials);
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    private function student(
        SchoolClass $class,
        string $number,
        string $first = 'Ada',
        StudentStatus $status = StudentStatus::Active,
    ): Student {
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
            'status' => $status->value,
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
