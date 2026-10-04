<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentVirtualAccount;
use App\Models\Term;
use App\Models\User;
use App\Models\WebhookEvent;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dedicated virtual accounts: a bank account per child, and the transfers that land
 * in them.
 *
 * This is the part of the fee collection where being wrong costs real money, so the
 * tests are mostly about the rules rather than the plumbing: money goes to the oldest
 * debt first, a transfer larger than the bill is held rather than lost, and — the one
 * that would matter most on a Monday morning — a webhook Paystack sends twice must
 * not credit a parent twice.
 */
class VirtualAccountTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_secret_key';

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

        Setting::put('paystack_secret_key', self::SECRET);

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

    /* ------------------------------------------------------------------ */
    /* Issuing the account */
    /* ------------------------------------------------------------------ */

    public function test_a_student_is_given_an_account_number(): void
    {
        $this->fakePaystack();
        $student = $this->student();

        $this->actingAs($this->admin)
            ->post(route('admin.fees.virtual-account', $student))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('student_virtual_accounts', [
            'student_id' => $student->id,
            'customer_code' => 'CUS_testcode',
            'account_number' => '9876543210',
            'bank_name' => 'Wema Bank',
        ]);

        // The key on Settings → API is what Paystack is spoken to with, and the payer
        // is the parent, because a student here has no email of their own.
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.self::SECRET));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/customer')
            && $request['email'] === 'parent@example.com');
    }

    public function test_the_account_is_not_issued_twice(): void
    {
        $this->fakePaystack();
        $student = $this->student();

        $this->actingAs($this->admin)->post(route('admin.fees.virtual-account', $student));
        $this->actingAs($this->admin)->post(route('admin.fees.virtual-account', $student));

        $this->assertSame(1, StudentVirtualAccount::query()->count());

        // One customer and one account created, not two: a second account number would
        // leave a parent paying into somewhere the school is not watching.
        Http::assertSentCount(2);
    }

    public function test_no_account_is_issued_without_a_key(): void
    {
        $this->fakePaystack();
        Setting::put('paystack_secret_key', '');
        Setting::flush();

        $student = $this->student();

        $this->actingAs($this->admin)
            ->post(route('admin.fees.virtual-account', $student))
            ->assertSessionHas('error');

        $this->assertSame(0, StudentVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /* A September intake, one press */
    /* ------------------------------------------------------------------ */

    public function test_account_numbers_can_be_opened_for_a_run_of_ticked_names(): void
    {
        $this->fakePaystack();

        $first = $this->student(['student_number' => 'SAC/2026/9001', 'admission_number' => 'SAC-09001']);
        $second = $this->student(['student_number' => 'SAC/2026/9002', 'admission_number' => 'SAC-09002']);
        $alreadyHasOne = $this->student(['student_number' => 'SAC/2026/9003', 'admission_number' => 'SAC-09003']);
        $notTicked = $this->student(['student_number' => 'SAC/2026/9004', 'admission_number' => 'SAC-09004']);

        // Somebody who already has one must keep the number their parent saved.
        $existing = StudentVirtualAccount::create([
            'student_id' => $alreadyHasOne->id,
            'customer_code' => 'CUS_existing',
            'bank_name' => 'Wema Bank',
            'account_number' => '1111111111',
            'account_name' => 'SACI SCHOOLS - EXISTING',
            'provider' => 'paystack',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.virtual-accounts'), [
                'students' => [$first->id, $second->id, $alreadyHasOne->id],
            ])
            ->assertSessionHas('status', '2 account number(s) opened.');

        $this->assertSame(3, StudentVirtualAccount::query()->count());
        $this->assertSame('1111111111', $existing->fresh()->account_number);

        // Only the names that were ticked: the run is what the office asked for, not
        // everything the page happens to know about.
        $this->assertNull($notTicked->fresh()->virtualAccount);
    }

    public function test_a_run_where_nobody_is_waiting_says_so_rather_than_opening_anything(): void
    {
        $this->fakePaystack();

        $student = $this->student();
        $this->accountFor($student);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.virtual-accounts'), ['students' => [$student->id]])
            ->assertSessionHas('status', 'Everyone you ticked already has an account number.');

        Http::assertNothingSent();
    }

    /**
     * Each account is two calls to the gateway: a run this size would time out part
     * way through, with no way to tell which half had been done. So it is refused
     * before the first one is opened, not halfway.
     */
    public function test_a_run_larger_than_one_go_is_refused_before_anything_is_opened(): void
    {
        $this->fakePaystack();

        $ids = [];

        foreach (range(1, 51) as $n) {
            $ids[] = $this->student([
                'student_number' => sprintf('SAC/2026/%04d', $n),
                'admission_number' => sprintf('SAC-9%04d', $n),
            ])->id;
        }

        $this->actingAs($this->admin)
            ->post(route('admin.fees.virtual-accounts'), ['students' => $ids])
            ->assertSessionHas('error');

        $this->assertSame(0, StudentVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_run_with_nothing_ticked_is_refused(): void
    {
        $this->fakePaystack();

        $this->actingAs($this->admin)
            ->post(route('admin.fees.virtual-accounts'), ['students' => []])
            ->assertSessionHasErrors('students');

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /* The transfer landing */
    /* ------------------------------------------------------------------ */

    public function test_a_transfer_settles_the_oldest_debt_first(): void
    {
        $student = $this->student();
        $this->accountFor($student);

        // Last term's bill is still unpaid when this term's arrives, which is the
        // ordinary case by the end of a session.
        $older = $this->invoice($student, 30000, '2026-09-05', 'INV/2026/00001');
        $newer = $this->invoice($student, 50000, '2026-10-01', 'INV/2026/00002');

        $this->charge('CUS_testcode', 40000, 'TRF_oldest_first')->assertOk();

        $this->assertSame(0.0, (float) $older->fresh()->balance, 'The older bill should be settled first.');
        $this->assertSame(40000.0, (float) $newer->fresh()->balance, 'The rest should go to the newer bill.');

        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(0, Payment::query()->whereNull('invoice_id')->count());
    }

    public function test_the_same_charge_twice_credits_once(): void
    {
        $student = $this->student();
        $this->accountFor($student);
        $invoice = $this->invoice($student, 100000, '2026-09-05', 'INV/2026/00001');

        $this->charge('CUS_testcode', 25000, 'TRF_retried')->assertOk();
        $this->charge('CUS_testcode', 25000, 'TRF_retried')->assertOk();

        // Paystack retries; the second delivery is the same event and must not be
        // money a second time.
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(75000.0, (float) $invoice->fresh()->balance);

        $this->assertSame(1, WebhookEvent::query()->where('reference', 'TRF_retried')->count());
    }

    public function test_more_than_is_owed_is_held_as_a_credit_rather_than_lost(): void
    {
        $student = $this->student();
        $this->accountFor($student);
        $invoice = $this->invoice($student, 30000, '2026-09-05', 'INV/2026/00001');

        $this->charge('CUS_testcode', 50000, 'TRF_overpaid')->assertOk();

        $this->assertSame(0.0, (float) $invoice->fresh()->balance);

        // A transfer cannot be refused — it has already landed — so the surplus is
        // receipted and shown as a credit on the account.
        $credit = Payment::query()->whereNull('invoice_id')->sole();

        $this->assertSame(20000.0, (float) $credit->amount);
        $this->assertNotNull($credit->receipt_number);
    }

    public function test_a_charge_for_an_account_we_do_not_hold_is_ignored(): void
    {
        $this->charge('CUS_somebody_else', 20000, 'TRF_unknown')->assertOk();

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('ignored', WebhookEvent::query()->sole()->status);
    }

    /* ------------------------------------------------------------------ */
    /* Somebody else's POST */
    /* ------------------------------------------------------------------ */

    public function test_a_webhook_without_a_valid_signature_is_refused(): void
    {
        $student = $this->student();
        $this->accountFor($student);
        $invoice = $this->invoice($student, 100000, '2026-09-05', 'INV/2026/00001');

        $body = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => 'TRF_forged',
                'amount' => 5000000,
                'customer' => ['customer_code' => 'CUS_testcode'],
            ],
        ]);

        $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'not-the-schools-key'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertStatus(400);

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(100000.0, (float) $invoice->fresh()->balance);
        $this->assertSame(0, WebhookEvent::query()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    /**
     * Post a charge the way Paystack does: a signed body, over the exact bytes we
     * claim to have signed.
     */
    private function charge(string $customerCode, float $amount, string $reference): TestResponse
    {
        $body = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $reference,
                'amount' => (int) round($amount * 100),
                'status' => 'success',
                'channel' => 'bank_transfer',
                'customer' => ['customer_code' => $customerCode],
            ],
        ]);

        return $this->call('POST', route('webhooks.paystack'), [], [], [], [
            'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, self::SECRET),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body);
    }

    private function fakePaystack(): void
    {
        Http::fake([
            'api.paystack.co/customer' => Http::response([
                'status' => true,
                'data' => ['customer_code' => 'CUS_testcode', 'email' => 'parent@example.com'],
            ]),
            'api.paystack.co/dedicated_account' => Http::response([
                'status' => true,
                'data' => [
                    'account_number' => '9876543210',
                    'account_name' => 'SACI SCHOOLS - ADA OKONKWO',
                    'bank' => ['name' => 'Wema Bank', 'slug' => 'wema-bank'],
                ],
            ]),
        ]);
    }

    private function accountFor(Student $student, string $customerCode = 'CUS_testcode'): StudentVirtualAccount
    {
        return StudentVirtualAccount::create([
            'student_id' => $student->id,
            'customer_code' => $customerCode,
            'bank_name' => 'Wema Bank',
            'account_number' => '9876543210',
            'account_name' => 'SACI SCHOOLS - '.$student->full_name,
            'account_slug' => 'wema-bank',
            'provider' => 'paystack',
            'is_active' => true,
        ]);
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

        $invoice->items()->create([
            'description' => 'Tuition',
            'amount' => $amount,
            'is_compulsory' => true,
        ]);

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
            'student_number' => 'SAC/2026/7001',
            'admission_number' => 'SAC-07001',
            'first_name' => 'Ada',
            'last_name' => 'Okonkwo',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
            'guardian_email' => 'parent@example.com',
            'level_id' => $this->level->id,
            'school_class_id' => $class->id,
            'academic_session_id' => $this->session->id,
            'status' => StudentStatus::Active->value,
            'admitted_at' => now(),
        ]);
    }
}
