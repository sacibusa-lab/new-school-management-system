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
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payments overview: what should have been collected, against what has.
 *
 * The whole screen rests on one decision — the expectation is worked out from the fee,
 * not from the bills raised — and most of these tests exist because of it. A year group
 * nobody has billed for shows a debt; a year group whose fee is switched off for the term
 * shows nothing; a year group with an amount of its own is charged that instead.
 *
 * The money side is the other way about, and the tests hold that too: only money that came
 * in against this term's bills counts, so a parent clearing last term does not flatter
 * this term's rate.
 */
class PaymentsOverviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private Term $firstTerm;

    private Term $secondTerm;

    private SchoolLevel $junior;

    private SchoolLevel $senior;

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

        $this->firstTerm = Term::create(['name' => 'First Term', 'position' => 1, 'is_current' => true]);
        $this->secondTerm = Term::create(['name' => 'Second Term', 'position' => 2, 'is_current' => false]);

        $this->junior = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);
        $this->senior = SchoolLevel::create(['name' => 'SS1', 'order' => 2, 'is_active' => true]);

        $section = Section::create(['name' => 'A', 'order' => 1]);

        SchoolClass::create(['level_id' => $this->junior->id, 'section_id' => $section->id, 'name' => 'JSS1A', 'is_active' => true]);
        SchoolClass::create(['level_id' => $this->senior->id, 'section_id' => $section->id, 'name' => 'SS1A', 'is_active' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* The expectation comes from the fee */
    /* ------------------------------------------------------------------ */

    public function test_it_reads_the_school_a_year_group_at_a_time(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $this->student($this->junior);
        $this->student($this->junior);
        $this->student($this->senior);

        $html = $this->html();

        $this->assertStringContainsString('JSS1', $html);
        $this->assertStringContainsString('SS1', $html);

        // One child is charged 62,000, so JSS1 — two of them — is expected to pay 124,000,
        // and the school as a whole 186,000.
        $this->assertStringContainsString('62,000', $html);
        $this->assertStringContainsString('124,000', $html);
        $this->assertStringContainsString('186,000', $html);
    }

    /**
     * The decision the whole screen rests on. Nobody has raised a bill, and the page still
     * knows what the school should have collected — which is the difference between this
     * screen and the register underneath it.
     */
    public function test_the_expectation_comes_from_the_fee_and_not_from_the_bills(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $this->student($this->junior);

        $this->assertSame(0, Invoice::query()->count());

        $html = $this->html();

        $this->assertStringContainsString('62,000', $html, 'A year group nobody has billed still shows what it owes.');
        $this->assertStringContainsString('1 student', $html);
    }

    public function test_a_year_group_given_its_own_amount_is_charged_that(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);

        FeeClassOverride::factory()->create([
            'fee_id' => $fee->id,
            'level_id' => $this->junior->id,
            'amount' => 80000,
        ]);

        $this->student($this->junior);
        $this->student($this->senior);

        $html = $this->html();

        // The override replaces the fee's amount rather than adding to it, which is what
        // makes "JSS1 is charged more than SS1" one decision instead of two figures to add.
        $this->assertStringContainsString('80,000', $html);
        $this->assertStringContainsString('62,000', $html);
    }

    /** An override that has been switched off contributes nothing, not the fee's amount. */
    public function test_a_year_group_whose_own_amount_is_switched_off_is_charged_nothing(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);

        FeeClassOverride::factory()->inactive()->create([
            'fee_id' => $fee->id,
            'level_id' => $this->junior->id,
            'amount' => 80000,
        ]);

        $this->student($this->junior);

        $html = $this->html();

        $this->assertStringNotContainsString('80,000', $html);
        // JSS1 contributes nothing, so the school is expected to pay nothing at all.
        $this->assertStringContainsString('Nothing is expected of anybody', $html);
    }

    public function test_a_fee_takes_no_money_in_a_term_it_is_not_charged_in(): void
    {
        Fee::factory()->create([
            'amount' => 62000,
            'first_term_active' => true,
            'second_term_active' => false,
        ]);

        $this->student($this->junior);

        // The term the school is in: charged.
        $this->assertStringContainsString('62,000', $this->html());

        // The second term: the fee has been unticked for it, so nothing is expected —
        // rather than the page quietly charging the default for a term the school does not.
        $second = $this->html(['term' => $this->secondTerm->id]);

        $this->assertStringContainsString('Nothing is expected of anybody', $second);
        $this->assertStringNotContainsString('62,000', $second);
    }

    public function test_a_fee_the_school_has_stopped_charging_is_not_expected(): void
    {
        Fee::factory()->inactive()->create(['amount' => 62000]);

        $this->student($this->junior);

        $this->assertStringContainsString('Nothing is expected of anybody', $this->html());
    }

    public function test_a_child_who_has_left_is_not_owed_for(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $this->student($this->junior);
        $this->student($this->junior, StudentStatus::Withdrawn);

        $html = $this->html();

        // One child on the roll is 62,000. Two would be 124,000, and a debt nobody can
        // collect is worse than no figure at all.
        $this->assertStringContainsString('62,000', $html);
        $this->assertStringNotContainsString('124,000', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The money that actually came in */
    /* ------------------------------------------------------------------ */

    public function test_money_received_is_taken_off_the_debt(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $one = $this->student($this->junior);
        $this->student($this->junior);

        // Two children expected to pay 62,000; one of them has.
        $this->payment($this->invoice($one, 62000), 62000);

        $html = $this->html();

        $this->assertStringContainsString('62,000', $html);
        // JSS1 is half way: 62,000 of 124,000.
        $this->assertStringContainsString('50%', $html);
        $this->assertStringContainsString('1 student paid', $html);
    }

    /**
     * A family part-way through is counted as having paid and as still owing, because
     * both are true of them. The counts on the row therefore do not add up to the roll,
     * and the page says so rather than leaving it to be worked out.
     */
    public function test_somebody_part_way_through_is_counted_as_paying_and_as_owing(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $one = $this->student($this->junior);
        $this->student($this->junior);

        $this->payment($this->invoice($one, 62000, 31000), 31000);

        $html = $this->html();

        $this->assertStringContainsString('1 student paid', $html);
        $this->assertStringContainsString('2 students owing', $html);
        $this->assertStringContainsString('both are true of them', $html);
    }

    /** A receipt with no bill behind it belongs to no term, so it is not this term's money. */
    public function test_money_against_another_terms_bill_is_not_counted_here(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $one = $this->student($this->junior);

        $lastTerm = Invoice::create([
            'invoice_number' => 'INV/OLD/00001',
            'student_id' => $one->id,
            'academic_session_id' => $this->session->id,
            'term_id' => $this->secondTerm->id,
            'subtotal' => 62000,
            'discount' => 0,
            'total' => 62000,
            'amount_paid' => 62000,
            'balance' => 0,
            'status' => InvoiceStatus::Paid,
            'issued_at' => now(),
        ]);

        $this->payment($lastTerm, 62000);

        $html = $this->html();

        // Nothing has come in against the first term, whatever the second term looks like.
        $this->assertStringNotContainsString('1 student paid', $html);
        $this->assertStringContainsString('0%', $html);
    }

    /* ------------------------------------------------------------------ */
    /* What has been taken off */
    /* ------------------------------------------------------------------ */

    public function test_an_approved_discount_shows_as_taken_off(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $one = $this->student($this->junior);

        $this->invoice($one, 62000, 0, 10000);

        $html = $this->html();

        // The discount column on the bill, which is what approving a scholarship writes.
        $this->assertStringContainsString('10,000', $html);
        $this->assertStringContainsString('1 student let off', $html);
    }

    /* ------------------------------------------------------------------ */

    public function test_somebody_outside_the_fee_desk_cannot_read_it(): void
    {
        $student = User::factory()->create();
        $student->assignRole('Student');

        $this->actingAs($student)->get(route('admin.payments.overview'))->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* The year group behind a row */
    /* ------------------------------------------------------------------ */

    public function test_a_year_group_opens_as_the_children_on_it(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $one = $this->student($this->junior, first: 'Ada');
        $two = $this->student($this->junior, first: 'Ngozi');

        $this->payment($this->invoice($one, 62000), 62000);

        $children = collect($this->detail($this->junior)['children']);

        $this->assertCount(2, $children);

        $first = $children->firstWhere('id', $one->id);

        $this->assertSame(62000.0, (float) $first['received']);
        $this->assertSame('completed', $first['status']);
        $this->assertSame('Paid in full', $first['status_label']);
        $this->assertSame($one->student_number, $first['number']);

        $second = $children->firstWhere('id', $two->id);

        $this->assertSame(0.0, (float) $second['received']);
        $this->assertSame('pending', $second['status']);
        $this->assertSame('Not paid', $second['status_label']);
    }

    /**
     * Green, yellow and red account for everybody between them.
     *
     * Deliberately not the same pair of counts the row above shows: there, a family
     * part-way through is counted as having paid and as still owing, because both are true
     * of them. Here the colours have to partition the roll or the grid looks wrong.
     */
    public function test_the_three_colours_account_for_everybody(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $settled = $this->student($this->junior, first: 'Ada');
        $partWay = $this->student($this->junior, first: 'Ngozi');
        $this->student($this->junior, first: 'Chidi');

        $this->payment($this->invoice($settled, 62000), 62000);
        $this->payment($this->invoice($partWay, 62000), 20000);

        $children = collect($this->detail($this->junior)['children']);

        $this->assertSame(3, $children->count());
        $this->assertSame(1, $children->where('status', 'completed')->count());
        $this->assertSame(1, $children->where('status', 'partial')->count());
        $this->assertSame(1, $children->where('status', 'pending')->count());
    }

    /**
     * A child owing nothing has settled, however little they have paid.
     *
     * A family let off the whole fee, or a year group whose fee is switched off for the
     * term, is not money outstanding — and painting them red would send somebody chasing
     * a debt that does not exist.
     */
    public function test_a_child_owing_nothing_has_settled_however_little_they_paid(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $one = $this->student($this->junior);

        $this->invoice($one, 62000, 0, 62000);

        $child = collect($this->detail($this->junior)['children'])->firstWhere('id', $one->id);

        $this->assertSame(0.0, (float) $child['expected']);
        $this->assertSame(0.0, (float) $child['received']);
        $this->assertSame('completed', $child['status']);
    }

    public function test_the_detail_lists_the_active_roll(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $this->student($this->junior);
        $left = $this->student($this->junior, StudentStatus::Withdrawn, 'Ngozi');

        $children = collect($this->detail($this->junior)['children']);

        $this->assertCount(1, $children);
        $this->assertNotContains($left->id, $children->pluck('id')->all());
    }

    public function test_the_detail_needs_a_year_group_to_be_about(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('admin.payments.level'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('level');
    }

    /* ------------------------------------------------------------------ */
    /* The spreadsheet */
    /* ------------------------------------------------------------------ */

    public function test_the_spreadsheet_carries_the_subset_it_was_asked_for(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $settled = $this->student($this->junior, first: 'Ada');
        $nothing = $this->student($this->junior, first: 'Ngozi');

        $this->payment($this->invoice($settled, 62000), 62000);

        $all = $this->spreadsheet('all');

        $this->assertStringContainsString($settled->full_name, $all);
        $this->assertStringContainsString($nothing->full_name, $all);

        // The four things the office asked to be able to take away.
        $paid = $this->spreadsheet('completed');

        $this->assertStringContainsString($settled->full_name, $paid);
        $this->assertStringNotContainsString($nothing->full_name, $paid);

        $pending = $this->spreadsheet('pending');

        $this->assertStringContainsString($nothing->full_name, $pending);
        $this->assertStringNotContainsString($settled->full_name, $pending);

        // Nobody is part-way through, so that file is its headings and nothing else.
        $this->assertCount(1, $this->spreadsheetRows('partial'));
    }

    public function test_the_spreadsheet_says_what_each_column_is(): void
    {
        Fee::factory()->create(['amount' => 62000]);

        $one = $this->student($this->junior);

        $this->payment($this->invoice($one, 62000), 20000);

        $rows = $this->spreadsheetRows('all');

        $this->assertSame(
            ['Student number', 'Name', 'Class', 'Discount', 'Expected', 'Received', 'Outstanding', 'Status'],
            $rows[0],
        );

        $this->assertSame($one->student_number, $rows[1][0]);
        $this->assertSame($one->full_name, $rows[1][1]);
        $this->assertSame('20000.00', $rows[1][5]);
        $this->assertSame('Part payment', $rows[1][7]);
    }

    public function test_a_subset_nobody_has_heard_of_is_not_a_subset(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.payments.level.export', ['level' => $this->junior->id, 'subset' => 'nonsense']))
            ->assertNotFound();
    }

    public function test_somebody_outside_the_fee_desk_cannot_read_a_year_group(): void
    {
        $outsider = User::factory()->create();
        $outsider->assignRole('Student');

        $this->actingAs($outsider)
            ->getJson(route('admin.payments.level', ['level' => $this->junior->id]))
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get(route('admin.payments.level.export', ['level' => $this->junior->id]))
            ->assertForbidden();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string,mixed>  $query
     */
    private function html(array $query = []): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.payments.overview', $query))
            ->assertOk()
            ->getContent();
    }

    /**
     * @return array<string,mixed>
     */
    private function detail(SchoolLevel $level): array
    {
        return $this->actingAs($this->admin)
            ->getJson(route('admin.payments.level', ['level' => $level->id]))
            ->assertOk()
            ->json();
    }

    /**
     * The spreadsheet, as rows.
     *
     * Read back with `str_getcsv` rather than searched as text, because that is what the
     * office will open it with — a comma inside a name is the bug this catches.
     *
     * @return array<int,array<int,string>>
     */
    private function spreadsheetRows(string $subset): array
    {
        $csv = $this->actingAs($this->admin)
            ->get(route('admin.payments.level.export', ['level' => $this->junior->id, 'subset' => $subset]))
            ->assertOk()
            ->streamedContent();

        $lines = array_filter(explode("\n", trim(str_replace("\xEF\xBB\xBF", '', $csv))));

        return array_values(array_map(
            fn (string $line): array => str_getcsv(trim($line)),
            $lines,
        ));
    }

    private function spreadsheet(string $subset): string
    {
        return $this->actingAs($this->admin)
            ->get(route('admin.payments.level.export', ['level' => $this->junior->id, 'subset' => $subset]))
            ->assertOk()
            ->streamedContent();
    }

    private function student(
        SchoolLevel $level,
        StudentStatus $status = StudentStatus::Active,
        string $first = 'Ada',
    ): Student {
        $number = 'SAC/2026/'.str_pad((string) (Student::query()->count() + 1), 4, '0', STR_PAD_LEFT);

        return Student::create([
            'student_number' => $number,
            'admission_number' => str_replace(['/', '.'], '', $number),
            'first_name' => $first,
            'last_name' => 'Okonkwo',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
            'level_id' => $level->id,
            'school_class_id' => SchoolClass::query()->where('level_id', $level->id)->value('id'),
            'academic_session_id' => $this->session->id,
            'status' => $status->value,
            'admitted_at' => now(),
        ]);
    }

    private function invoice(Student $student, float $total, float $paid = 0, float $discount = 0): Invoice
    {
        $owed = $total - $discount;

        return Invoice::create([
            'invoice_number' => 'INV/2026/'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'student_id' => $student->id,
            'academic_session_id' => $this->session->id,
            'term_id' => $this->firstTerm->id,
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
