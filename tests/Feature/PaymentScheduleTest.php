<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\Fee;
use App\Models\FeeClassOverride;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentAdjustment;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The payment schedule: the slip that goes home with a child.
 *
 * Four things it has to get right, and each of them is a way the office has been asked a
 * question it could not answer before. What is this child charged, and for what. What has
 * been taken off. What is still owed from a year that has ended. And where the money goes.
 *
 * The sheet is built from the fees rather than from the bills, which is the same choice the
 * overview makes — so the first test here is the one that matters most: a child nobody has
 * raised a bill for still gets a slip, because the school knowing what it is owed does not
 * depend on the office having invoiced anybody.
 */
class PaymentScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private AcademicSession $lastYear;

    private Term $term;

    private SchoolLevel $level;

    private Section $section;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        // The year that has not started is created first, so that "an earlier session"
        // means an earlier row rather than a smaller id.
        $this->lastYear = AcademicSession::create([
            'name' => '2025/2026',
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-07-31',
            'is_current' => false,
        ]);

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
        ]);

        $this->term = Term::create(['name' => 'First Term', 'position' => 1, 'is_current' => true]);
        Term::create(['name' => 'Second Term', 'position' => 2, 'is_current' => false]);

        $this->level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);
        $this->section = Section::create(['name' => 'A', 'order' => 1]);

        $this->class = SchoolClass::create([
            'level_id' => $this->level->id,
            'section_id' => $this->section->id,
            'name' => 'JSS1A',
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* What the slip says */
    /* ------------------------------------------------------------------ */

    public function test_a_child_nobody_has_billed_still_gets_a_slip(): void
    {
        Fee::factory()->create(['title' => 'School fees', 'amount' => 62000]);

        $child = $this->student();

        $html = $this->sheet();

        $this->assertStringContainsString($child->full_name, $html);
        $this->assertStringContainsString('School fees', $html);
        $this->assertStringContainsString('62,000.00', $html);
        $this->assertStringContainsString('Not paid', $html);
    }

    public function test_money_paid_comes_off_the_slip(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        $this->payment($this->invoice($child, 62000), 62000);

        $html = $this->sheet();

        $this->assertStringContainsString('Paid in full', $html);
        $this->assertStringContainsString('0.00', $html);
    }

    public function test_a_year_group_amount_replaces_the_fee(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);

        FeeClassOverride::factory()->create([
            'fee_id' => $fee->id,
            'level_id' => $this->level->id,
            'amount' => 80000,
        ]);

        $this->student();

        $html = $this->sheet();

        $this->assertStringContainsString('80,000.00', $html);
        $this->assertStringNotContainsString('62,000.00', $html);
    }

    public function test_a_discount_comes_off_the_slip_as_its_own_line(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        $this->invoice($child, 62000, 0, 10000);

        $html = $this->sheet();

        $this->assertStringContainsString('Discount', $html);
        // Charged 62,000 less 10,000 off is what is asked for.
        $this->assertStringContainsString('52,000.00', $html);
    }

    /* ------------------------------------------------------------------ */
    /* What is brought forward */
    /* ------------------------------------------------------------------ */

    public function test_an_earlier_session_still_owed_is_brought_forward(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        $this->invoice($child, 40000, 15000, 0, $this->lastYear);

        $html = $this->sheet();

        $this->assertStringContainsString('Brought forward', $html);
        $this->assertStringContainsString('2025/2026', $html);
        // 62,000 for this term plus the 25,000 left over from last year.
        $this->assertStringContainsString('87,000.00', $html);
    }

    public function test_a_bill_for_a_session_that_has_not_started_is_not_brought_forward(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        $nextYear = AcademicSession::create([
            'name' => '2027/2028',
            'starts_on' => '2027-09-01',
            'ends_on' => '2028-07-31',
            'is_current' => false,
        ]);

        $this->invoice($child, 70000, 0, 0, $nextYear);

        $html = $this->sheet();

        $this->assertStringNotContainsString('Brought forward', $html);
        $this->assertStringNotContainsString('70,000.00', $html);
    }

    public function test_a_settled_earlier_session_is_not_brought_forward(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        $this->invoice($child, 40000, 40000, 0, $this->lastYear);

        $this->assertStringNotContainsString('Brought forward', $this->sheet());
    }

    public function test_a_cancelled_bill_from_before_is_owed_by_nobody(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        $cancelled = $this->invoice($child, 40000, 0, 0, $this->lastYear);
        $cancelled->update(['status' => InvoiceStatus::Cancelled]);

        $this->assertStringNotContainsString('Brought forward', $this->sheet());
    }

    /* ------------------------------------------------------------------ */
    /* Narrowing it down */
    /* ------------------------------------------------------------------ */

    public function test_the_sheet_is_narrowed_to_one_class(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $here = $this->student();

        $elsewhere = SchoolClass::create([
            'level_id' => SchoolLevel::create(['name' => 'JSS2', 'order' => 2, 'is_active' => true])->id,
            'section_id' => $this->section->id,
            'name' => 'JSS2A',
            'is_active' => true,
        ]);

        $other = $this->student($elsewhere, first: 'Ngozi');

        $html = $this->sheet(['class' => $this->level->id.':'.$this->section->id]);

        $this->assertStringContainsString($here->full_name, $html);
        $this->assertStringNotContainsString($other->full_name, $html);

        // Nothing selected is the whole roll, which is the state the page opens in.
        $everything = $this->sheet();

        $this->assertStringContainsString($here->full_name, $everything);
        $this->assertStringContainsString($other->full_name, $everything);
    }

    public function test_the_sheet_can_be_narrowed_to_one_fee(): void
    {
        $termly = Fee::factory()->create(['title' => 'Termly fee', 'amount' => 62000]);
        Fee::factory()->create(['title' => 'Bus levy', 'amount' => 5000]);

        $this->student();

        // Read through the export rather than the page: the page's own Payment type control
        // lists every fee, so the name of the one that was filtered out is on it either way.
        // What the sheet holds is the question, and the file has no control on it. Charged is
        // the fourth column.
        $narrowed = $this->exportRows('all', ['fee' => $termly->id]);

        $this->assertSame(
            '62000.00',
            $narrowed[1][3],
            'Narrowed to the termly fee, the levy should not be charged.',
        );

        $everything = $this->exportRows('all');

        $this->assertSame(
            '67000.00',
            $everything[1][3],
            'Both fees belong to this session and this term, so both should be charged.',
        );
    }

    /* ------------------------------------------------------------------ */
    /* Taking it away */
    /* ------------------------------------------------------------------ */

    public function test_the_export_has_a_row_for_every_child(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $paid = $this->student();
        $owing = $this->student(class: $this->class, first: 'Ngozi');

        $this->payment($this->invoice($paid, 62000), 62000);

        $rows = $this->exportRows('all');

        $this->assertSame(
            ['Student number', 'Name', 'Class', 'Charged', 'Discount', 'Expected', 'Received', 'Outstanding', 'Status'],
            $rows[0],
        );

        $this->assertCount(3, $rows);

        // Sorted by surname, so the two are in a known order at the foot of the file.
        $this->assertSame($paid->student_number, $rows[1][0]);
        $this->assertSame('Paid in full', $rows[1][8]);
        $this->assertSame($owing->student_number, $rows[2][0]);
        $this->assertSame('Not paid', $rows[2][8]);
    }

    public function test_the_export_can_be_narrowed_to_one_standing(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $paid = $this->student();
        $owing = $this->student(class: $this->class, first: 'Ngozi');

        $this->payment($this->invoice($paid, 62000), 62000);

        $settled = $this->exportRows('completed');

        $this->assertCount(2, $settled);
        $this->assertSame($paid->student_number, $settled[1][0]);

        $unpaid = $this->exportRows('pending');

        $this->assertCount(2, $unpaid);
        $this->assertSame($owing->student_number, $unpaid[1][0]);
    }

    public function test_the_slips_download_as_a_pdf(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $this->student();

        // DomPDF throws on a broken view, so a clean 200 is the assertion: the file either
        // built or it did not, and there is no half-built PDF worth reading.
        $this->actingAs($this->admin)
            ->get(route('admin.payments.schedule.pdf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_download_carries_the_filters_the_page_was_shown_with(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $here = $this->student();
        $other = $this->student(class: $this->class, first: 'Ngozi');

        $csv = $this->export('all', ['class' => $this->level->id.':'.$this->section->id]);

        $this->assertStringContainsString($here->full_name, $csv);
        $this->assertStringContainsString($other->full_name, $csv);

        // A class that is not this one has nobody on it, so the file is its headings.
        $nowhere = $this->exportRows('all', ['class' => '999:0']);

        $this->assertCount(1, $nowhere);
    }

    /* ------------------------------------------------------------------ */
    /* Who may look */
    /* ------------------------------------------------------------------ */

    public function test_somebody_outside_the_fee_desk_cannot_read_the_sheet(): void
    {
        $outsider = User::factory()->create();
        $outsider->assignRole('Student');

        foreach (['admin.payments.schedule', 'admin.payments.schedule.export', 'admin.payments.schedule.pdf'] as $route) {
            $this->actingAs($outsider)->get(route($route))->assertForbidden();
        }
    }

    /* ------------------------------------------------------------------ */
    /* Changing what is owed */
    /* ------------------------------------------------------------------ */

    public function test_taking_an_amount_off_writes_one_adjustment_for_each_child(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $one = $this->student();
        $two = $this->student(class: $this->class, first: 'Ngozi');
        $untouched = $this->student(class: $this->class, first: 'Chidi');

        $this->adjust(['students' => [$one->id, $two->id]])->assertRedirect();

        $this->assertSame(2, StudentAdjustment::query()->count());

        $adjustment = StudentAdjustment::query()->first();

        $this->assertSame('-5000.00', (string) $adjustment->amount);
        $this->assertSame('Sibling discount', $adjustment->description);
        $this->assertSame($this->session->id, $adjustment->academic_session_id);
        $this->assertSame($this->term->id, $adjustment->term_id);
        $this->assertSame($this->admin->id, $adjustment->created_by);

        // A child who was not ticked is not on the list, which is the whole point of ticking.
        $this->assertSame(
            0,
            StudentAdjustment::query()->where('student_id', $untouched->id)->count(),
            'A child who was not chosen was changed anyway.',
        );

        // And it reaches the slip: 62,000 less the 5,000 taken off.
        $html = $this->sheet();

        $this->assertStringContainsString('Sibling discount', $html);
        $this->assertStringContainsString('57,000.00', $html);
    }

    public function test_adding_an_amount_puts_it_on_the_bill(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        $this->adjust([
            'students' => [$child->id],
            'type' => 'add',
            'amount' => 2500,
            'description' => 'Bus paid by the school',
        ])->assertRedirect();

        $this->assertSame('2500.00', (string) StudentAdjustment::query()->first()->amount);

        $html = $this->sheet();

        $this->assertStringContainsString('Bus paid by the school', $html);
        $this->assertStringContainsString('64,500.00', $html);
    }

    public function test_a_reason_left_blank_still_says_which_way_the_money_went(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        $this->adjust(['students' => [$child->id], 'description' => ''])->assertRedirect();

        $this->assertSame('Discount', StudentAdjustment::query()->first()->description);
    }

    public function test_a_change_made_for_another_term_is_not_on_this_slip(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        StudentAdjustment::factory()->create([
            'student_id' => $child->id,
            'academic_session_id' => $this->session->id,
            'term_id' => Term::query()->where('position', 2)->sole()->id,
            'amount' => -5000,
            'description' => 'Second term discount',
        ]);

        $html = $this->sheet();

        $this->assertStringNotContainsString('Second term discount', $html);
        $this->assertStringContainsString('62,000.00', $html);
    }

    public function test_an_amount_of_nothing_is_not_a_change(): void
    {
        $child = $this->student();

        $this->adjust(['students' => [$child->id], 'amount' => 0])->assertSessionHasErrors('amount');

        $this->assertSame(0, StudentAdjustment::query()->count());
    }

    public function test_changing_nobody_at_all_is_refused(): void
    {
        $this->adjust(['students' => []])->assertSessionHasErrors('students');

        $this->assertSame(0, StudentAdjustment::query()->count());
    }

    public function test_the_fee_desk_changing_an_amount_needs_the_permission_for_it(): void
    {
        $outsider = User::factory()->create();
        $outsider->assignRole('Student');

        $child = $this->student();

        $this->actingAs($outsider)
            ->post(route('admin.payments.schedule.adjust'), [
                'students' => [$child->id],
                'amount' => 1000,
                'type' => 'subtract',
            ])
            ->assertForbidden();

        $this->assertSame(0, StudentAdjustment::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Recording money */
    /* ------------------------------------------------------------------ */

    public function test_marking_children_paid_records_a_payment_for_each(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $one = $this->student();
        $two = $this->student(class: $this->class, first: 'Ngozi');

        $first = $this->invoice($one, 62000);
        $second = $this->invoice($two, 62000);

        $this->record(['students' => [$one->id, $two->id]])->assertRedirect();

        $payments = Payment::query()->where('status', PaymentStatus::Successful->value)->get();

        $this->assertCount(2, $payments, 'One payment each was not recorded.');
        $this->assertSame('62000.00', (string) $payments->firstWhere('invoice_id', $first->id)->amount);
        $this->assertSame('62000.00', (string) $payments->firstWhere('invoice_id', $second->id)->amount);
        $this->assertSame('cash', $payments->first()->method);
        $this->assertNotNull($payments->first()->receipt_number, 'No receipt number was issued.');
        $this->assertSame($this->admin->id, $payments->first()->recorded_by);

        // The bill agrees with the payment, and the sheet now says so.
        $this->assertSame('62000.00', (string) $first->fresh()->amount_paid);
        $this->assertStringContainsString('Paid in full', $this->sheet());
    }

    public function test_a_part_payment_is_capped_at_what_the_bill_says(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        // 4,500 still owed, and 20,000 offered: the bill is the ceiling.
        $this->invoice($child, 62000, 57500);

        $this->record([
            'students' => [$child->id],
            'mode' => 'part',
            'amount' => 20000,
        ])->assertRedirect();

        $this->assertSame('4500.00', (string) Payment::query()->first()->amount);
    }

    public function test_a_child_with_nothing_to_pay_against_is_named_rather_than_passed_over(): void
    {
        // No bill and no published structure, so there is nothing to record money against.
        $child = $this->student();

        $this->record(['students' => [$child->id]])->assertRedirect();

        $this->assertSame(0, Payment::query()->count());
        $this->assertStringContainsString(
            $child->full_name,
            (string) session('status'),
            'The child who was left alone was not named, so the run looked like it worked.',
        );
    }

    public function test_a_child_who_has_already_settled_is_not_paid_a_second_time(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $child = $this->student();

        $this->invoice($child, 62000, 62000);

        $this->record(['students' => [$child->id]])->assertRedirect();

        $this->assertSame(0, Payment::query()->count());
        $this->assertStringContainsString('already settled', (string) session('status'));
    }

    public function test_recording_payments_is_not_something_a_student_may_do(): void
    {
        $outsider = User::factory()->create();
        $outsider->assignRole('Student');

        $child = $this->student();

        $this->actingAs($outsider)
            ->post(route('admin.payments.schedule.record'), [
                'students' => [$child->id],
                'method' => 'cash',
                'mode' => 'full',
            ])
            ->assertForbidden();

        $this->assertSame(0, Payment::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    /**
     * Change what a set of children owe, the way the panel does.
     *
     * @param  array<string,mixed>  $payload
     */
    private function adjust(array $payload = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.payments.schedule.adjust'), array_merge([
            'students' => [],
            'amount' => 5000,
            'type' => 'subtract',
            'description' => 'Sibling discount',
            'session' => $this->session->id,
            'term' => $this->term->id,
        ], $payload));
    }

    /**
     * Record money for a set of children, the way the panel does.
     *
     * @param  array<string,mixed>  $payload
     */
    private function record(array $payload = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.payments.schedule.record'), array_merge([
            'students' => [],
            'method' => 'cash',
            'mode' => 'full',
            'session' => $this->session->id,
            'term' => $this->term->id,
        ], $payload));
    }

    /**
     * The page, drawn.
     *
     * @param  array<string,mixed>  $query
     */
    private function sheet(array $query = []): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.payments.schedule', $query))
            ->assertOk()
            ->getContent();
    }

    /**
     * The spreadsheet, read back the way the office will open it.
     *
     * `str_getcsv` rather than a search of the text, because a comma inside a name is
     * exactly the bug this catches.
     *
     * @param  array<string,mixed>  $query
     * @return array<int,array<int,string>>
     */
    private function exportRows(string $subset, array $query = []): array
    {
        $lines = array_filter(explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $this->export($subset, $query)))));

        return array_values(array_map(
            fn (string $line): array => str_getcsv(trim($line)),
            $lines,
        ));
    }

    /**
     * @param  array<string,mixed>  $query
     */
    private function export(string $subset, array $query = []): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.payments.schedule.export', $query + ['subset' => $subset]))
            ->assertOk()
            ->streamedContent();
    }

    private function student(?SchoolClass $class = null, StudentStatus $status = StudentStatus::Active, string $first = 'Ada'): Student
    {
        $class ??= $this->class;

        $number = 'SAC/2026/'.str_pad((string) (Student::query()->count() + 1), 4, '0', STR_PAD_LEFT);

        return Student::create([
            'student_number' => $number,
            'admission_number' => str_replace(['/', '.'], '', $number),
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

    private function invoice(
        Student $student,
        float $total,
        float $paid = 0,
        float $discount = 0,
        ?AcademicSession $session = null,
    ): Invoice {
        $owed = $total - $discount;

        return Invoice::create([
            'invoice_number' => 'INV/2026/'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'student_id' => $student->id,
            'academic_session_id' => ($session ?? $this->session)->id,
            'term_id' => $this->term->id,
            'subtotal' => $total,
            'discount' => $discount,
            'total' => $owed,
            'amount_paid' => $paid,
            'balance' => $owed - $paid,
            'status' => $paid <= 0
                ? InvoiceStatus::Unpaid
                : ($paid >= $owed ? InvoiceStatus::Paid : InvoiceStatus::Partial),
            'issued_at' => now(),
        ]);
    }

    private function payment(Invoice $invoice, float $amount): Payment
    {
        return Payment::create([
            'receipt_number' => 'RCP/2026/'.str_pad((string) (Payment::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'invoice_id' => $invoice->id,
            'student_id' => $invoice->student_id,
            'amount' => $amount,
            'method' => 'cash',
            'status' => PaymentStatus::Successful,
            'paid_at' => now(),
        ]);
    }
}
