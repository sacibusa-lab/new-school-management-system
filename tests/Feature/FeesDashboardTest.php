<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The collection desk's front page.
 *
 * It exists to answer "where are the fees not working", which is a different question
 * from the one the main school dashboard asks. So the tests here are about the things
 * that make it that: the money is scoped to the session's own bills, the classes are
 * ordered by what is outstanding rather than by name, and a cancelled bill is not a
 * bill. The last one matters more than it looks — a cancelled invoice counted in the
 * totals is the school believing it is owed money it has already written off.
 */
class FeesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private Term $term;

    private string $currency;

    private int $invoices = 0;

    private int $receipts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->currency = Setting::get('currency_symbol', '₦');

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
        ]);

        $this->term = Term::create(['name' => 'First Term', 'position' => 1, 'is_current' => true]);
    }

    public function test_it_opens_and_shows_the_session_position(): void
    {
        $student = $this->student();
        $invoice = $this->bill($student, 100000, 40000);

        $this->receipt($invoice, 40000);

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.dashboard'))
            ->assertOk()
            ->assertSee('The session so far')
            ->assertSee($this->currency.'100,000.00')
            ->assertSee($this->currency.'60,000.00')
            // The rate is the figure that says whether this is a good term or a bad one.
            ->assertSee('40% collected');
    }

    /**
     * Today means today. A receipt taken yesterday is this week's money and last week's
     * news, and a page that calls it "collected today" sends somebody looking for a
     * payment that was never made.
     */
    public function test_todays_figures_are_todays_and_not_yesterdays(): void
    {
        // A Wednesday, so the week has days either side of today in it.
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));

        $student = $this->student();
        $invoice = $this->bill($student, 100000, 50000);

        $this->receipt($invoice, 40000, 'cash', Carbon::parse('2026-10-07 09:30:00'));
        $this->receipt($invoice, 10000, 'cash', Carbon::parse('2026-10-06 09:30:00'));

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.dashboard'))
            ->assertOk()
            ->assertSee('1 payment recorded')
            // Today's own figure, not the week's and not the session's.
            ->assertSee($this->currency.'40,000.00')
            // And the week holds both, because both are this week.
            ->assertSee($this->currency.'50,000.00');
    }

    public function test_a_cancelled_bill_is_not_money_the_school_is_owed(): void
    {
        $student = $this->student();
        $this->bill($student, 100000, 0);
        $this->bill($student, 90000, 0, null, InvoiceStatus::Cancelled);

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.dashboard'))
            ->assertOk()
            ->assertSee($this->currency.'100,000.00')
            // Not 190,000: the cancelled bill is a bill the school has stopped asking for.
            ->assertDontSee($this->currency.'190,000.00');
    }

    /**
     * A list of twenty-three classes in alphabetical order is a list nobody acts on.
     */
    public function test_classes_are_listed_furthest_behind_first(): void
    {
        $a = $this->student('JSS1A', 'SAC/2026/1001', 'Ada');
        $b = $this->student('JSS2B', 'SAC/2026/1002', 'Ngozi', 'JSS2');

        // Alphabetically first, and comfortably the least behind.
        $this->bill($a, 500000, 495000);

        $this->bill($b, 200000, 10000);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.dashboard'))
            ->assertOk()
            ->assertSee('Class by class')
            ->assertSee($this->currency.'190,000.00')
            ->assertSee($this->currency.'5,000.00')
            ->getContent();

        // JSS2B owes most, so it is read first even though JSS1A comes first in the roll.
        $biggest = strpos($html, 'JSS2B');
        $smallest = strpos($html, 'JSS1A');

        $this->assertNotFalse($biggest);
        $this->assertNotFalse($smallest);
        $this->assertLessThan($smallest, $biggest, 'The class owing the most is not the first one listed.');
    }

    public function test_the_breakdown_shows_how_the_money_arrived(): void
    {
        $student = $this->student();
        $invoice = $this->bill($student, 100000, 75000);

        $this->receipt($invoice, 50000, 'gateway');
        $this->receipt($invoice, 25000, 'cash');

        $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.dashboard'))
            ->assertOk()
            ->assertSee('How the money arrives')
            // Spelled the same way they are spelled on a receipt.
            ->assertSee('Online payment')
            ->assertSee('Cash')
            ->assertSee($this->currency.'50,000.00')
            ->assertSee($this->currency.'25,000.00')
            // Two thirds of the money in came through the gateway.
            ->assertSee('66.7% of the money in');
    }

    /**
     * The twelve months are filled in whether or not anything came in during them. A
     * month with no money in it is a column of zero height, and dropping it would close
     * the gap and draw a trend that says the opposite of what happened.
     */
    public function test_twelve_months_are_drawn_even_when_a_month_is_empty(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));

        $student = $this->student();
        $invoice = $this->bill($student, 100000, 20000);

        // One receipt, three months back, so the months after it are all zero.
        $this->receipt($invoice, 20000, 'cash', Carbon::parse('2026-07-15 10:00:00'));

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.dashboard'))
            ->assertOk()
            ->assertSee('Money received, month by month')
            ->getContent();

        $start = Carbon::parse('2026-10-01')->subMonths(11);

        for ($back = 0; $back < 12; $back++) {
            $this->assertStringContainsString(
                $start->copy()->addMonths($back)->format('M'),
                $html,
                'A month is missing from the twelve.',
            );
        }

        // The month the money came in is the best one, which is what the note under the
        // chart is for.
        $this->assertStringContainsString('The best month was Jul', $html);
    }

    public function test_the_day_book_shows_the_newest_receipt_first(): void
    {
        $this->travelTo(Carbon::parse('2026-10-07 10:00:00'));

        $student = $this->student();
        $invoice = $this->bill($student, 100000, 30000);

        $this->receipt($invoice, 10000, 'cash', Carbon::parse('2026-10-01 09:00:00'));
        $this->receipt($invoice, 20000, 'cash', Carbon::parse('2026-10-06 09:00:00'));

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees-payments.dashboard'))
            ->assertOk()
            ->assertSee('The day book')
            ->assertSee('RCP/2026/00001')
            ->assertSee('RCP/2026/00002')
            // The child's name, so the office can read back who paid without opening the
            // receipt.
            ->assertSee($student->full_name)
            ->getContent();

        $newest = strpos($html, 'RCP/2026/00002');
        $older = strpos($html, 'RCP/2026/00001');

        $this->assertNotFalse($newest);
        $this->assertNotFalse($older);
        $this->assertLessThan($older, $newest, 'The day book is not newest first.');
    }

    public function test_somebody_outside_the_fee_desk_cannot_read_it(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->get(route('admin.fees-payments.dashboard'))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    private function student(
        string $class = 'JSS1A',
        string $number = 'SAC/2026/1001',
        string $first = 'Ada',
        string $level = 'JSS1',
    ): Student {
        $schoolLevel = SchoolLevel::query()->firstOrCreate(
            ['name' => $level],
            ['order' => SchoolLevel::query()->count() + 1, 'is_active' => true],
        );

        $section = Section::query()->firstOrCreate(
            ['name' => 'A'],
            ['order' => 1],
        );

        $schoolClass = SchoolClass::query()->firstOrCreate(
            ['name' => $class],
            ['level_id' => $schoolLevel->id, 'section_id' => $section->id, 'is_active' => true],
        );

        return Student::create([
            'student_number' => $number,
            'admission_number' => 'SAC-'.str_replace(['/', '.'], '', $number),
            'first_name' => $first,
            'last_name' => 'Okonkwo',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
            'level_id' => $schoolLevel->id,
            'school_class_id' => $schoolClass->id,
            'academic_session_id' => $this->session->id,
            'status' => StudentStatus::Active->value,
            'admitted_at' => now(),
        ]);
    }

    /**
     * A bill. Its money columns are set by hand rather than derived from its lines,
     * because the columns are what every figure on the dashboard reads.
     */
    private function bill(
        Student $student,
        float $amount,
        float $paid = 0,
        ?Carbon $issuedAt = null,
        ?InvoiceStatus $status = null,
    ): Invoice {
        $this->invoices++;

        return Invoice::create([
            'invoice_number' => sprintf('INV/2026/%05d', $this->invoices),
            'student_id' => $student->id,
            'academic_session_id' => $this->session->id,
            'term_id' => $this->term->id,
            'subtotal' => $amount,
            'discount' => 0,
            'total' => $amount,
            'amount_paid' => $paid,
            'balance' => $amount - $paid,
            'status' => $status ?? ($paid <= 0
                ? InvoiceStatus::Unpaid
                : ($paid >= $amount ? InvoiceStatus::Paid : InvoiceStatus::Partial)),
            'issued_at' => $issuedAt ?? now(),
        ]);
    }

    private function receipt(
        Invoice $invoice,
        float $amount,
        string $method = 'cash',
        ?Carbon $paidAt = null,
    ): Payment {
        $this->receipts++;

        return Payment::create([
            'receipt_number' => sprintf('RCP/2026/%05d', $this->receipts),
            'invoice_id' => $invoice->id,
            'student_id' => $invoice->student_id,
            'amount' => $amount,
            'method' => $method,
            'status' => PaymentStatus::Successful->value,
            'paid_at' => $paidAt ?? now(),
        ]);
    }
}
