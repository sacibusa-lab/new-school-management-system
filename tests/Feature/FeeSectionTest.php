<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Payment;
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
 * The Fees section.
 *
 * It was committed with its controllers, models and routes and without a single
 * view, so every entry in the menu was a 500: the schema was designed and nothing
 * could be typed into it. The first test here is the one that would have caught
 * that — it opens each page and asks only that it renders.
 *
 * The rest are about money, which is the part where being wrong is expensive: a
 * bill has to be priced before it goes out, a payment must not exceed what is owed,
 * and a receipt has to say what is still outstanding.
 */
class FeeSectionTest extends TestCase
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

        $this->term = Term::create([
            'name' => 'First Term',
            'position' => 1,
            'is_current' => true,
        ]);

        $this->level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* Every page opens */
    /* ------------------------------------------------------------------ */

    public function test_every_page_in_the_fees_section_renders(): void
    {
        $structure = $this->pricedStructure();
        $invoice = $this->invoiceFor($this->student(), $structure);

        foreach ([
            route('admin.fees.categories.index'),
            route('admin.fees.structures.index'),
            route('admin.fees.structures.show', $structure),
            route('admin.invoices.index'),
            route('admin.invoices.show', $invoice),
            route('admin.payments.index'),
        ] as $url) {
            $this->actingAs($this->admin)->get($url)->assertOk();
        }
    }

    public function test_the_receipt_renders_for_a_payment(): void
    {
        $structure = $this->pricedStructure();
        $invoice = $this->invoiceFor($this->student(), $structure);

        $this->actingAs($this->admin)
            ->post(route('admin.invoices.payments.store', $invoice), [
                'amount' => 10000,
                'method' => 'cash',
            ])
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $payment = Payment::query()->sole();

        $this->actingAs($this->admin)
            ->get(route('admin.invoices.receipt', [$invoice, $payment]))
            ->assertOk()
            ->assertSee($payment->receipt_number)
            // What is still owed has to be on the paper a parent is handed.
            ->assertSee(number_format((float) $invoice->fresh()->balance, 2));
    }

    public function test_somebody_outside_the_fees_desk_cannot_open_the_section(): void
    {
        $student = User::factory()->create();
        $student->assignRole('Student');

        $this->actingAs($student)->get(route('admin.fees.categories.index'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.invoices.index'))->assertForbidden();
        $this->actingAs($student)->get(route('admin.payments.index'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Pricing the bill */
    /* ------------------------------------------------------------------ */

    public function test_a_fee_category_can_be_added(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.fees.categories.store'), [
                'name' => 'Boarding Fee',
                'code' => 'BOARD',
                'type' => 'hostel',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Boarding Fee', FeeCategory::query()->where('code', 'BOARD')->value('name'));
    }

    public function test_a_structure_counts_its_lines_and_their_total(): void
    {
        $structure = $this->structure();
        $tuition = $this->category('TUITION');
        $levy = $this->category('DEVLEVY');

        foreach ([[$tuition, 85000], [$levy, 15000]] as [$category, $amount]) {
            $this->actingAs($this->admin)
                ->post(route('admin.fees.structures.items.store', $structure), [
                    'fee_category_id' => $category->id,
                    'amount' => $amount,
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $structure->items()->count());
        $this->assertSame(100000.0, $structure->total());

        $this->actingAs($this->admin)
            ->get(route('admin.fees.structures.show', $structure))
            ->assertOk()
            ->assertSee('100,000.00');
    }

    /**
     * Saving the same category twice changes the price rather than billing the
     * school's children for two lots of tuition.
     */
    public function test_pricing_the_same_category_twice_replaces_the_amount(): void
    {
        $structure = $this->structure();
        $tuition = $this->category('TUITION');
        $url = route('admin.fees.structures.items.store', $structure);

        $this->actingAs($this->admin)->post($url, ['fee_category_id' => $tuition->id, 'amount' => 85000]);
        $this->actingAs($this->admin)->post($url, ['fee_category_id' => $tuition->id, 'amount' => 90000]);

        $this->assertSame(1, $structure->items()->count());
        $this->assertSame(90000.0, $structure->total());
    }

    /* ------------------------------------------------------------------ */
    /* Handing it out */
    /* ------------------------------------------------------------------ */

    public function test_billing_a_structure_raises_one_invoice_per_student(): void
    {
        $structure = $this->pricedStructure();
        $this->student(['student_number' => 'SAC/2026/9051']);
        $this->student(['student_number' => 'SAC/2026/9052', 'first_name' => 'Ngozi']);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.structures.bill', $structure))
            ->assertRedirect(route('admin.invoices.index'));

        $this->assertSame(2, Invoice::query()->count());

        $invoice = Invoice::query()->first();

        $this->assertSame(100000.0, (float) $invoice->total);
        $this->assertSame(100000.0, (float) $invoice->balance);
        $this->assertTrue($invoice->is_auto_generated);
    }

    /** Nobody is billed twice for the same term, however many times the button is pressed. */
    public function test_billing_the_same_term_twice_does_not_double_bill(): void
    {
        $structure = $this->pricedStructure();
        $this->student();

        $this->actingAs($this->admin)->post(route('admin.fees.structures.bill', $structure));
        $this->actingAs($this->admin)->post(route('admin.fees.structures.bill', $structure));

        $this->assertSame(1, Invoice::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Taking the money */
    /* ------------------------------------------------------------------ */

    public function test_a_payment_is_receipted_and_reduces_the_balance(): void
    {
        $structure = $this->pricedStructure();
        $invoice = $this->invoiceFor($this->student(), $structure);

        $this->actingAs($this->admin)
            ->post(route('admin.invoices.payments.store', $invoice), [
                'amount' => 40000,
                'method' => 'bank_transfer',
                'reference' => 'TRF-99120',
            ])
            ->assertRedirect(route('admin.invoices.show', $invoice));

        $invoice->refresh();
        $payment = Payment::query()->sole();

        $this->assertSame(40000.0, (float) $invoice->amount_paid);
        $this->assertSame(60000.0, (float) $invoice->balance);
        $this->assertSame(PaymentStatus::Successful, $payment->status);
        $this->assertNotNull($payment->receipt_number);
        $this->assertSame($this->admin->id, $payment->recorded_by);
    }

    public function test_more_money_than_is_owed_is_refused(): void
    {
        $structure = $this->pricedStructure();
        $invoice = $this->invoiceFor($this->student(), $structure);

        $this->actingAs($this->admin)
            ->post(route('admin.invoices.payments.store', $invoice), [
                'amount' => 150000,
                'method' => 'cash',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(100000.0, (float) $invoice->fresh()->balance);
    }

    /**
     * A reversal is not a deletion: the receipt keeps its number and the money goes
     * back onto the balance, so the books still explain the gap.
     */
    public function test_reversing_a_payment_puts_the_money_back(): void
    {
        $structure = $this->pricedStructure();
        $invoice = $this->invoiceFor($this->student(), $structure);

        $this->actingAs($this->admin)
            ->post(route('admin.invoices.payments.store', $invoice), ['amount' => 100000, 'method' => 'cash']);

        $payment = Payment::query()->sole();

        $this->assertSame(0.0, (float) $invoice->fresh()->balance);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.reverse', $payment), ['reason' => 'Cheque returned unpaid'])
            ->assertSessionHasNoErrors();

        $this->assertSame(PaymentStatus::Reversed, $payment->fresh()->status);
        $this->assertSame(100000.0, (float) $invoice->fresh()->balance);
        $this->assertSame(1, Payment::query()->count(), 'The receipt should be kept and marked, not deleted.');
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    private function structure(array $extra = []): FeeStructure
    {
        return FeeStructure::create($extra + [
            'name' => 'JSS1 — First Term 2026/2027',
            'academic_session_id' => $this->session->id,
            'term_id' => $this->term->id,
            'level_id' => $this->level->id,
            'is_active' => true,
            'is_default_for_new_students' => true,
            'due_days' => 30,
        ]);
    }

    /** Tuition and a levy: JSS1 owes ₦100,000. */
    private function pricedStructure(): FeeStructure
    {
        $structure = $this->structure();

        $structure->items()->create([
            'fee_category_id' => $this->category('TUITION')->id,
            'description' => 'Tuition',
            'amount' => 85000,
            'is_compulsory' => true,
        ]);

        $structure->items()->create([
            'fee_category_id' => $this->category('DEVLEVY')->id,
            'description' => 'Development levy',
            'amount' => 15000,
            'is_compulsory' => true,
        ]);

        return $structure->fresh();
    }

    private function category(string $code): FeeCategory
    {
        return FeeCategory::firstOrCreate(
            ['code' => $code],
            ['name' => $code === 'TUITION' ? 'Tuition Fee' : 'Development Levy', 'type' => 'levy', 'is_active' => true],
        );
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
            'student_number' => 'SAC/2026/9001',
            'admission_number' => 'SAC-09001',
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

    /**
     * A bill raised the way the structure's own page raises one — against the
     * structure the office has open, not against whatever happens to be default.
     */
    private function invoiceFor(Student $student, FeeStructure $structure): Invoice
    {
        $this->actingAs($this->admin)
            ->post(route('admin.invoices.store'), [
                'student_id' => $student->id,
                'fee_structure_id' => $structure->id,
            ])
            ->assertSessionHasNoErrors();

        return Invoice::query()->where('student_id', $student->id)->firstOrFail();
    }
}
