<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\BankAccount;
use App\Models\Fee;
use App\Models\FeeCategory;
use App\Models\FeeSplit;
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
use App\Services\Fees\InvoiceGenerationService;
use App\Services\Fees\SettlementService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * How money that has been collected divides into the school's own accounts.
 *
 * The figures are worked back out of the payments rather than read from a ledger, so what
 * is worth pinning down is the arithmetic: the platform's charge comes off the top once per
 * payment, the splits are paid in order, and whatever is left over stays in the main
 * account instead of being shared out or quietly dropped.
 */
class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private Term $firstTerm;

    private SchoolLevel $level;

    private string $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->currency = Setting::get('currency_symbol', '₦');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');

        $this->session = AcademicSession::create([
            'name' => '2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-07-31',
            'is_current' => true,
        ]);

        $this->firstTerm = Term::create(['name' => 'First Term', 'position' => 1, 'is_current' => true]);

        $this->level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);

        $section = Section::create(['name' => 'A', 'order' => 1]);

        SchoolClass::create([
            'level_id' => $this->level->id,
            'section_id' => $section->id,
            'name' => 'JSS1A',
            'is_active' => true,
        ]);
    }

    /**
     * The whole rule in one place: ₦62,000 falls to two accounts, the platform keeps ₦100
     * of each payment back, and the account paid last carries the shortfall.
     */
    public function test_a_fee_is_divided_between_its_accounts_minus_the_it_fee(): void
    {
        $fee = Fee::factory()->create(['title' => '1st Term', 'amount' => 62000]);

        $zenith = BankAccount::factory()->create(['bank_name' => 'Zenith Bank', 'account_number' => '1013183975']);
        $keystone = BankAccount::factory()->create(['bank_name' => 'Keystone Bank', 'account_number' => '1009020394']);

        FeeSplit::factory()->create(['fee_id' => $fee->id, 'bank_account_id' => $zenith->id, 'amount' => 32000, 'position' => 0]);
        FeeSplit::factory()->create(['fee_id' => $fee->id, 'bank_account_id' => $keystone->id, 'amount' => 30000, 'position' => 1]);

        $invoice = $this->invoiceFor($fee, 62000);

        // Three payments on the same day, which is the day the desk has to move the money.
        foreach (['06:02', '15:05', '15:07'] as $time) {
            $this->paymentFor($invoice, 62000, '2026-10-04 '.$time.':00');
        }

        $this->actingAs($this->admin)
            ->get(route('admin.payments.settlements', ['session' => $this->session->id]))
            ->assertOk()
            // 3 x 32,000 — the first account is paid in full.
            ->assertSee($this->currency.'96,000.00')
            // 3 x 30,000 less 3 x 100 — the last account carries the platform's charge.
            ->assertSee($this->currency.'89,700.00')
            ->assertSee($this->currency.'300.00')
            ->assertSee($this->currency.'186,000.00')
            ->assertSee('Manual transfer instructions')
            ->assertSee('Transaction breakdown (3)');
    }

    /**
     * The charge is a number the fee carries, not a constant in the code: the platform's
     * price can change, and a fee that was priced at a different one has to keep it.
     */
    public function test_the_it_maintenance_fee_is_set_per_fee(): void
    {
        $fee = Fee::factory()->create(['title' => '1st Term', 'amount' => 62000, 'it_maintenance_fee' => 250]);

        $zenith = BankAccount::factory()->create();
        $keystone = BankAccount::factory()->create();

        FeeSplit::factory()->create(['fee_id' => $fee->id, 'bank_account_id' => $zenith->id, 'amount' => 32000, 'position' => 0]);
        FeeSplit::factory()->create(['fee_id' => $fee->id, 'bank_account_id' => $keystone->id, 'amount' => 30000, 'position' => 1]);

        $invoice = $this->invoiceFor($fee, 62000);

        foreach (range(1, 3) as $index) {
            $this->paymentFor($invoice, 62000, '2026-10-04 09:0'.$index.':00');
        }

        $this->actingAs($this->admin)
            ->get(route('admin.payments.settlements', ['session' => $this->session->id]))
            ->assertOk()
            // 3 x 30,000 less 3 x 250.
            ->assertSee($this->currency.'89,250.00')
            ->assertSee($this->currency.'750.00');
    }

    /**
     * A fee nobody has divided is not a fee that vanished. The money stays in the main
     * account, and the page says so rather than leaving a gap in the sums.
     */
    public function test_money_no_split_claims_stays_in_the_main_account(): void
    {
        $fee = Fee::factory()->create(['title' => '1st Term', 'amount' => 62000]);

        $invoice = $this->invoiceFor($fee, 62000);
        $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        $this->actingAs($this->admin)
            ->get(route('admin.payments.settlements', ['session' => $this->session->id]))
            ->assertOk()
            ->assertSee('Not divided, stays in the main account')
            ->assertSee($this->currency.'62,000.00');
    }

    /**
     * The two account cards are the school's own grouping rather than the order the accounts
     * happen to sit in: the main account is the one the school marked primary — the account
     * printed on a letter when nobody says otherwise — and every other account is grouped
     * under one heading, however many of them there are.
     */
    public function test_the_main_account_is_the_one_the_school_marked_primary(): void
    {
        $fee = Fee::factory()->create(['title' => '1st Term', 'amount' => 62000]);

        // Opened first, but not the main one: the row is grouped by what the school said
        // rather than by which account it set up first.
        $keystone = BankAccount::factory()->create(['bank_name' => 'Keystone Bank']);
        $zenith = BankAccount::factory()->primary()->create(['bank_name' => 'Zenith Bank']);

        FeeSplit::factory()->create(['fee_id' => $fee->id, 'bank_account_id' => $keystone->id, 'amount' => 32000, 'position' => 0]);
        FeeSplit::factory()->create(['fee_id' => $fee->id, 'bank_account_id' => $zenith->id, 'amount' => 30000, 'position' => 1]);

        $invoice = $this->invoiceFor($fee, 62000);
        $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        $this->actingAs($this->admin)
            ->get(route('admin.payments.settlements', ['session' => $this->session->id]))
            ->assertOk()
            ->assertSee('How the collections divide')
            // The first split takes 32,000 and the pool runs short, so the main account ends
            // up with 29,900 of the 30,000 it asked for — and reads under that heading.
            ->assertSeeInOrder([
                'Main Bank', $this->currency.'29,900.00',
                'Other Bank', $this->currency.'32,000.00',
            ])
            // Named underneath, so the two groups can still be told apart.
            ->assertSee('Zenith Bank')
            ->assertSee('Keystone Bank');
    }

    /**
     * A bill line names the fee it is for, and the invoice copies that across when it is
     * raised. Without it nothing here would know which fee's splits to apply.
     */
    public function test_a_structure_line_carries_its_fee_onto_the_invoice(): void
    {
        $fee = Fee::factory()->create(['title' => '1st Term', 'amount' => 62000]);
        $category = $this->category();

        $structure = FeeStructure::create([
            'name' => 'JSS1 — First Term 2026/2027',
            'academic_session_id' => $this->session->id,
            'term_id' => $this->firstTerm->id,
            'level_id' => $this->level->id,
            'is_active' => true,
            'is_default_for_new_students' => true,
            'due_days' => 30,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.structures.items.store', $structure), [
                'fee_category_id' => $category->id,
                'fee_id' => $fee->id,
                'amount' => 62000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($fee->id, $structure->items()->first()->fee_id);

        $student = $this->student();

        $invoice = app(InvoiceGenerationService::class)
            ->generateForStudent($student, $structure, $this->firstTerm->id, $this->admin);

        $this->assertNotNull($invoice);
        $this->assertSame($fee->id, $invoice->items()->first()->fee_id);
    }

    /* ------------------------------------------------------------------ */
    /* Saying the money has been moved */
    /* ------------------------------------------------------------------ */

    /**
     * What the page works out is where the money should go; whether it has gone is something
     * only the office can say, so settling is its own action and its own record.
     */
    public function test_one_payment_can_be_marked_settled(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);
        $payment = $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        $this->assertFalse($payment->isSettled());

        $this->actingAs($this->admin)
            ->post(route('admin.payments.settlements.settle', $payment))
            ->assertRedirect()
            ->assertSessionHas('status');

        $payment->refresh();

        $this->assertTrue($payment->isSettled());
        $this->assertNotNull($payment->settled_at);
        $this->assertSame($this->admin->id, $payment->settled_by);
    }

    /** A payment ticked off that had not actually been transferred has to be undoable. */
    public function test_a_settled_payment_can_be_left_unsettled_again(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);
        $payment = $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        $this->actingAs($this->admin)->post(route('admin.payments.settlements.settle', $payment));
        $this->actingAs($this->admin)
            ->post(route('admin.payments.settlements.unsettle', $payment))
            ->assertRedirect();

        $payment->refresh();

        $this->assertFalse($payment->isSettled());
        $this->assertNull($payment->settled_by);
    }

    /**
     * A day is usually transferred in one sitting, and the day's action must act on what is
     * still outstanding rather than re-stamping the whole day.
     */
    public function test_a_whole_day_can_be_settled_at_once(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);

        $first = $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');
        $this->paymentFor($invoice, 62000, '2026-10-04 15:05:00');

        // A payment from another day, which the day's action must not touch.
        $elsewhere = $this->paymentFor($invoice, 62000, '2026-10-05 09:00:00');

        // One already settled, so pressing the day's button again cannot re-stamp it.
        $first->forceFill(['settled_at' => now()->subDay(), 'settled_by' => $this->admin->id])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.payments.settlements.day.settle'), [
                'session' => $this->session->id,
                'date' => '2026-10-04',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(0, Payment::query()
            ->whereDate('paid_at', '2026-10-04')->whereNull('settled_at')->count());
        $this->assertFalse($elsewhere->fresh()->isSettled());

        // The one settled yesterday keeps the time it was actually settled at.
        $this->assertEqualsWithDelta(
            now()->subDay()->timestamp,
            $first->fresh()->settled_at->timestamp,
            5,
        );
    }

    public function test_a_whole_day_can_be_put_back_to_unsettled(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);
        $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');
        $this->paymentFor($invoice, 62000, '2026-10-04 15:05:00');

        $this->actingAs($this->admin)->post(route('admin.payments.settlements.day.settle'), [
            'session' => $this->session->id, 'date' => '2026-10-04',
        ]);

        $this->assertSame(2, Payment::query()->whereNotNull('settled_at')->count());

        $this->actingAs($this->admin)->post(route('admin.payments.settlements.day.unsettle'), [
            'session' => $this->session->id, 'date' => '2026-10-04',
        ]);

        $this->assertSame(0, Payment::query()->whereNotNull('settled_at')->count());
    }

    /**
     * Only money that arrived can be moved, and only the fee desk can say it moved; a
     * request does not have to come from the page.
     */
    public function test_only_a_successful_payment_can_be_settled(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);

        $failed = $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');
        $failed->forceFill(['status' => PaymentStatus::Failed])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.payments.settlements.settle', $failed))
            ->assertNotFound();

        $this->assertFalse($failed->fresh()->isSettled());
    }

    public function test_somebody_outside_the_fee_desk_cannot_settle(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);
        $payment = $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        $outsider = User::factory()->create();
        $outsider->assignRole('Teacher');

        $this->actingAs($outsider)
            ->post(route('admin.payments.settlements.settle', $payment))
            ->assertForbidden();

        $this->assertFalse($payment->fresh()->isSettled());
    }

    /**
     * The page settles a row without a reload, so the same endpoint has to answer in JSON
     * as well as with a redirect for a browser that posts the form.
     */
    public function test_settling_answers_json_when_asked(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);
        $payment = $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        $this->actingAs($this->admin)
            ->postJson(route('admin.payments.settlements.settle', $payment))
            ->assertOk()
            ->assertJson(['settled' => true, 'by' => $this->admin->name]);

        $this->assertTrue($payment->fresh()->isSettled());
    }

    /** The settled state is what the page reads to show a day's progress. */
    public function test_the_page_shows_how_much_of_a_day_is_settled(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);

        $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');
        $settled = $this->paymentFor($invoice, 62000, '2026-10-04 15:05:00');

        $settled->forceFill(['settled_at' => now(), 'settled_by' => $this->admin->id])->save();

        $this->actingAs($this->admin)
            ->get(route('admin.payments.settlements', ['session' => $this->session->id]))
            ->assertOk()
            ->assertSee('1 of 2 settled')
            ->assertSee('Settle the day');
    }

    /* ------------------------------------------------------------------ */
    /* From collected to paid out */
    /* ------------------------------------------------------------------ */

    /**
     * The three states a day moves through, in the order the money does: collected but not
     * finished with, settled and ready to go out, and transferred.
     */
    public function test_a_day_says_where_it_has_got_to(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);
        $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        // Collected, nothing settled: the gateway has not finished with it.
        $this->assertSame(SettlementService::STATUS_AWAITING, $this->dayStatus('2026-10-04'));

        $this->actingAs($this->admin)->post(route('admin.payments.settlements.day.settle'), [
            'session' => $this->session->id, 'date' => '2026-10-04',
        ]);

        // Every payment settled: the day can be transferred.
        $this->assertSame(SettlementService::STATUS_READY, $this->dayStatus('2026-10-04'));

        $this->actingAs($this->admin)->post(route('admin.payments.settlements.day.disburse'), [
            'session' => $this->session->id, 'date' => '2026-10-04',
        ]);

        $this->assertSame(SettlementService::STATUS_DISBURSED, $this->dayStatus('2026-10-04'));
        $this->assertDatabaseHas('disbursements', [
            'academic_session_id' => $this->session->id,
            'collected_on' => '2026-10-04',
            'disbursed_by' => $this->admin->id,
        ]);
    }

    /**
     * A day whose money is not all settled cannot be paid out: the transfer would have to be
     * worked out again as the rest arrived.
     */
    public function test_a_day_cannot_be_paid_out_until_every_payment_is_settled(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);

        $settled = $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');
        $this->paymentFor($invoice, 62000, '2026-10-04 15:05:00');

        $settled->forceFill(['settled_at' => now(), 'settled_by' => $this->admin->id])->save();

        $this->actingAs($this->admin)
            ->post(route('admin.payments.settlements.day.disburse'), [
                'session' => $this->session->id, 'date' => '2026-10-04',
            ])
            ->assertSessionHasErrors('disbursement');

        $this->assertDatabaseCount('disbursements', 0);
        $this->assertSame(SettlementService::STATUS_AWAITING, $this->dayStatus('2026-10-04'));
    }

    /** A page with scripting off posts the button and has to be told in the session. */
    public function test_paying_a_day_out_can_be_undone(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);
        $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        $this->actingAs($this->admin)->post(route('admin.payments.settlements.day.settle'), [
            'session' => $this->session->id, 'date' => '2026-10-04',
        ]);
        $this->actingAs($this->admin)->post(route('admin.payments.settlements.day.disburse'), [
            'session' => $this->session->id, 'date' => '2026-10-04',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.settlements.day.undisburse'), [
                'session' => $this->session->id, 'date' => '2026-10-04',
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('disbursements', 0);
        $this->assertSame(SettlementService::STATUS_READY, $this->dayStatus('2026-10-04'));
    }

    public function test_paying_a_day_out_answers_json_when_asked(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);
        $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        $this->actingAs($this->admin)->post(route('admin.payments.settlements.day.settle'), [
            'session' => $this->session->id, 'date' => '2026-10-04',
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.payments.settlements.day.disburse'), [
                'session' => $this->session->id, 'date' => '2026-10-04',
            ])
            ->assertOk()
            ->assertJson(['disbursed' => true, 'by' => $this->admin->name]);
    }

    /**
     * The month count is what the office works down, so it counts days still to be paid out
     * — including days whose money is settled and waiting, which are collected but not yet
     * transferred.
     */
    public function test_a_month_counts_the_days_still_to_be_paid_out(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);

        $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');
        $this->paymentFor($invoice, 62000, '2026-10-05 06:02:00');
        $this->paymentFor($invoice, 62000, '2026-10-06 06:02:00');

        $this->assertSame(3, $this->monthPending('October 2026'));

        // Pay one day out; the month is two days short of done.
        $this->actingAs($this->admin)->post(route('admin.payments.settlements.day.settle'), [
            'session' => $this->session->id, 'date' => '2026-10-04',
        ]);
        $this->actingAs($this->admin)->post(route('admin.payments.settlements.day.disburse'), [
            'session' => $this->session->id, 'date' => '2026-10-04',
        ]);

        $this->assertSame(2, $this->monthPending('October 2026'));
    }

    /**
     * The calendar reads newest first, at both levels the office scans: the month it is
     * working in is at the top, and inside it the last collection day. Oldest first would
     * put a year of history above the thing being worked on.
     */
    public function test_months_and_days_read_newest_first(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);

        $this->paymentFor($invoice, 62000, '2026-09-20 10:00:00');
        $this->paymentFor($invoice, 62000, '2026-10-01 10:00:00');
        $this->paymentFor($invoice, 62000, '2026-10-03 10:00:00');

        $terms = $this->settled()['terms'];

        $this->assertCount(1, $terms);
        $this->assertSame(['October 2026', 'September 2026'], collect($terms[0]['months'])->pluck('label')->all());

        $october = $terms[0]['months'][0];

        $this->assertSame(
            ['2026-10-03', '2026-10-01'],
            collect($october['days'])->pluck('date')->all(),
        );
    }

    /** A session somebody else's money was collected in is not the session that is settled. */
    public function test_another_sessions_money_is_not_counted(): void
    {
        $fee = Fee::factory()->create(['amount' => 62000]);
        $invoice = $this->invoiceFor($fee, 62000);
        $this->paymentFor($invoice, 62000, '2026-10-04 06:02:00');

        $other = AcademicSession::create([
            'name' => '2027/2028', 'starts_on' => '2027-09-01', 'ends_on' => '2028-07-31', 'is_current' => false,
        ]);

        $settled = app(SettlementService::class)->forSession($other);

        $this->assertSame(0.0, $settled['totals']['collections']);
        $this->assertSame([], $settled['terms']);
    }

    /* ------------------------------------------------------------------ */
    /* Reading the service */
    /* ------------------------------------------------------------------ */

    /** The status of one collection day, as the page would read it. */
    private function dayStatus(string $date): string
    {
        foreach ($this->settled()['terms'] as $term) {
            foreach ($term['months'] as $month) {
                foreach ($month['days'] as $day) {
                    if ($day['date'] === $date) {
                        return $day['status'];
                    }
                }
            }
        }

        $this->fail('That day is not on the settlement page at all: '.$date);
    }

    /** How many of a month's days are still to be paid out. */
    private function monthPending(string $label): int
    {
        foreach ($this->settled()['terms'] as $term) {
            foreach ($term['months'] as $month) {
                if ($month['label'] === $label) {
                    return $month['pending'];
                }
            }
        }

        $this->fail('That month is not on the settlement page at all: '.$label);
    }

    /**
     * @return array{totals:array<string,mixed>,accounts:Collection,terms:array<int,mixed>}
     */
    private function settled(): array
    {
        return app(SettlementService::class)->forSession($this->session);
    }

    /* ------------------------------------------------------------------ */
    /* Setting the figures up */
    /* ------------------------------------------------------------------ */

    private function category(): FeeCategory
    {
        return FeeCategory::firstOrCreate(
            ['code' => 'TUITION'],
            ['name' => 'Tuition Fee', 'type' => 'levy', 'is_active' => true],
        );
    }

    private function student(): Student
    {
        $number = 'SAC/2026/'.str_pad((string) (Student::query()->count() + 1), 4, '0', STR_PAD_LEFT);

        return Student::create([
            'student_number' => $number,
            'admission_number' => str_replace(['/', '.'], '', $number),
            'first_name' => 'Ada',
            'last_name' => 'Okonkwo',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
            'level_id' => $this->level->id,
            'school_class_id' => SchoolClass::query()->where('level_id', $this->level->id)->value('id'),
            'academic_session_id' => $this->session->id,
            'status' => StudentStatus::Active->value,
            'admitted_at' => now(),
        ]);
    }

    /**
     * A bill whose one line names the fee, which is what says where the money paid against
     * it goes.
     */
    private function invoiceFor(Fee $fee, float $amount): Invoice
    {
        $student = $this->student();

        $invoice = Invoice::create([
            'invoice_number' => 'INV/2026/'.str_pad((string) (Invoice::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'student_id' => $student->id,
            'academic_session_id' => $this->session->id,
            'term_id' => $this->firstTerm->id,
            'subtotal' => $amount,
            'discount' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'status' => InvoiceStatus::Unpaid,
            'issued_at' => now(),
        ]);

        $invoice->items()->create([
            'fee_category_id' => $this->category()->id,
            'fee_id' => $fee->id,
            'description' => $fee->title,
            'amount' => $amount,
            'is_compulsory' => true,
        ]);

        return $invoice;
    }

    private function paymentFor(Invoice $invoice, float $amount, string $paidAt): Payment
    {
        return Payment::create([
            'receipt_number' => 'RCP/2026/'.str_pad((string) (Payment::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'invoice_id' => $invoice->id,
            'student_id' => $invoice->student_id,
            'amount' => $amount,
            'method' => 'gateway',
            'status' => PaymentStatus::Successful,
            'paid_at' => $paidAt,
        ]);
    }
}
