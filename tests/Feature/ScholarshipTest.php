<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Scholarship;
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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What a student is let off.
 *
 * An award is money, so these are the rules that matter: it is approved by somebody,
 * it comes off the bills themselves rather than being worked out at read time, it
 * takes the oldest debt first, and an award that has not been decided on changes
 * nothing at all.
 */
class ScholarshipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private Term $term;

    private SchoolLevel $level;

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
    }

    public function test_an_approved_award_comes_off_the_bill(): void
    {
        $student = $this->student();
        $invoice = $this->invoice($student, 100000, '2026-09-05', 'INV/2026/00001');

        $this->award($student, 25000)->assertRedirect();

        $invoice->refresh();

        $this->assertSame(25000.0, (float) $invoice->discount);
        $this->assertSame(75000.0, (float) $invoice->total);
        $this->assertSame(75000.0, (float) $invoice->balance);

        $award = Scholarship::query()->sole();

        $this->assertSame('approved', $award->status);
        // Somebody's name belongs against money taken off a bill.
        $this->assertSame($this->admin->id, $award->approved_by);
        $this->assertNotNull($award->approved_at);
    }

    public function test_an_award_takes_the_oldest_bill_first(): void
    {
        $student = $this->student();
        $older = $this->invoice($student, 30000, '2026-09-05', 'INV/2026/00001');
        $newer = $this->invoice($student, 50000, '2026-10-01', 'INV/2026/00002');

        $this->award($student, 40000);

        $this->assertSame(30000.0, (float) $older->fresh()->discount, 'The older bill should be cleared first.');
        $this->assertSame(10000.0, (float) $newer->fresh()->discount, 'The rest should go to the newer bill.');
        $this->assertSame(40000.0, (float) $newer->fresh()->total);
    }

    public function test_an_award_waiting_for_a_decision_changes_nothing(): void
    {
        $student = $this->student();
        $invoice = $this->invoice($student, 100000, '2026-09-05', 'INV/2026/00001');

        $this->actingAs($this->admin)
            ->post(route('admin.fees.scholarships.store'), $this->awardBody($student, 25000))
            ->assertRedirect();

        $this->assertSame('pending', Scholarship::query()->sole()->status);
        $this->assertSame(0.0, (float) $invoice->fresh()->discount);
        $this->assertSame(100000.0, (float) $invoice->fresh()->total);
    }

    public function test_the_same_award_cannot_be_applied_twice(): void
    {
        $student = $this->student();
        $invoice = $this->invoice($student, 100000, '2026-09-05', 'INV/2026/00001');

        $this->award($student, 25000);

        $award = Scholarship::query()->sole();

        // Pressing Approve again must not take another 25,000 off.
        $this->actingAs($this->admin)
            ->post(route('admin.fees.scholarships.approve', $award))
            ->assertSessionHas('error');

        $this->assertSame(25000.0, (float) $invoice->fresh()->discount);
    }

    public function test_withdrawing_an_award_puts_the_money_back_on_the_bill(): void
    {
        $student = $this->student();
        $invoice = $this->invoice($student, 100000, '2026-09-05', 'INV/2026/00001');

        $this->award($student, 25000);

        $this->assertSame(75000.0, (float) $invoice->fresh()->total);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.scholarships.reject', Scholarship::query()->sole()))
            ->assertRedirect();

        $this->assertSame(0.0, (float) $invoice->fresh()->discount);
        $this->assertSame(100000.0, (float) $invoice->fresh()->total);
        $this->assertSame('rejected', Scholarship::query()->sole()->status);
    }

    public function test_somebody_outside_the_fee_desk_cannot_award_or_approve(): void
    {
        $student = $this->student();
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)->get(route('admin.fees.scholarships.index'))->assertForbidden();

        $this->actingAs($teacher)
            ->post(route('admin.fees.scholarships.store'), $this->awardBody($student, 25000))
            ->assertForbidden();

        $this->assertSame(0, Scholarship::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    /** Record an award and approve it in one go, the way the form does. */
    private function award(Student $student, float $amount): TestResponse
    {
        return $this->actingAs($this->admin)
            ->post(route('admin.fees.scholarships.store'), $this->awardBody($student, $amount) + ['approve' => '1']);
    }

    /** @return array<string,mixed> */
    private function awardBody(Student $student, float $amount): array
    {
        return [
            'student_id' => $student->id,
            'academic_session_id' => $this->session->id,
            'term_id' => $this->term->id,
            'type' => 'scholarship',
            'amount' => $amount,
            'description' => 'Best entrance result',
        ];
    }

    private function invoice(Student $student, float $amount, string $issuedOn, string $number): Invoice
    {
        $invoice = Invoice::create([
            'invoice_number' => $number,
            'student_id' => $student->id,
            'academic_session_id' => $this->session->id,
            'term_id' => $this->term->id,
            'subtotal' => $amount,
            'total' => $amount,
            'balance' => $amount,
            'status' => InvoiceStatus::Unpaid,
            'issued_at' => $issuedOn,
        ]);

        $invoice->items()->create(['description' => 'Tuition', 'amount' => $amount, 'is_compulsory' => true]);

        return $invoice;
    }

    /** @param  array<string,mixed>  $extra */
    private function student(array $extra = []): Student
    {
        $section = Section::firstOrCreate(['name' => 'A'], ['order' => 1]);

        $class = SchoolClass::firstOrCreate(
            ['name' => 'JSS1A'],
            ['level_id' => $this->level->id, 'section_id' => $section->id, 'is_active' => true],
        );

        return Student::create($extra + [
            'student_number' => 'SAC/2026/8001',
            'admission_number' => 'SAC-08001',
            'first_name' => 'Ada',
            'last_name' => 'Okonkwo',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
            'level_id' => $this->level->id,
            'school_class_id' => $class->id,
            'academic_session_id' => $this->session->id,
            'status' => StudentStatus::Active->value,
            'admitted_at' => now(),
        ]);
    }
}
