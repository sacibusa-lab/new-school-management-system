<?php

namespace Tests\Feature;

use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\BankAccount;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentVirtualAccount;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The school's own bank accounts, and giving a class their account numbers.
 *
 * Both are about account numbers, and both are places where a wrong digit is money
 * that never arrives: one holds the numbers the school is paid into, the other opens
 * a number per child. So the tests are about the checks — the number put to the bank
 * before it is saved, one main account rather than two, and a bulk run that skips the
 * children who already have one.
 */
class CollectionToolsTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'sk_test_secret_key';

    private User $admin;

    private AcademicSession $session;

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

        Term::create(['name' => 'First Term', 'position' => 1, 'is_current' => true]);
        $this->level = SchoolLevel::create(['name' => 'JSS1', 'order' => 1, 'is_active' => true]);
    }

    /* ------------------------------------------------------------------ */
    /* The school's own accounts */
    /* ------------------------------------------------------------------ */

    public function test_the_bank_is_asked_who_the_account_belongs_to(): void
    {
        $this->fakePaystack();

        $this->actingAs($this->admin)
            ->post(route('admin.bank-accounts.store'), [
                'label' => 'Fees account',
                'bank_code' => '035',
                'account_number' => '0123456789',
                // Deliberately wrong: what the bank says is what is kept.
                'account_name' => 'Typed in by hand',
                'is_primary' => '1',
            ])
            ->assertSessionHasNoErrors();

        $account = BankAccount::query()->sole();

        $this->assertSame('SACI SCHOOLS LTD', $account->account_name);
        $this->assertSame('Wema Bank', $account->bank_name);
        $this->assertTrue($account->is_primary);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bank/resolve')
            && $request['account_number'] === '0123456789'
            && $request['bank_code'] === '035');
    }

    public function test_an_account_number_the_bank_does_not_recognise_is_refused(): void
    {
        Http::fake([
            'api.paystack.co/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name'], 422),
            'api.paystack.co/bank*' => Http::response(['status' => true, 'data' => [['name' => 'Wema Bank', 'code' => '035']]]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.bank-accounts.store'), [
                'label' => 'Fees account',
                'bank_code' => '035',
                'account_number' => '0123456789',
            ])
            ->assertSessionHasErrors('account_number');

        $this->assertSame(0, BankAccount::query()->count());
    }

    public function test_there_is_only_one_main_account(): void
    {
        $this->fakePaystack();

        $this->actingAs($this->admin)->post(route('admin.bank-accounts.store'), [
            'label' => 'Fees account', 'bank_code' => '035', 'account_number' => '0123456789', 'is_primary' => '1',
        ]);

        $this->actingAs($this->admin)->post(route('admin.bank-accounts.store'), [
            'label' => 'PTA account', 'bank_code' => '035', 'account_number' => '9876543210', 'is_primary' => '1',
        ]);

        $this->assertSame(1, BankAccount::query()->where('is_primary', true)->count());

        // The one that just went in is the main one; the first is demoted, not removed.
        $this->assertTrue(BankAccount::query()->where('label', 'PTA account')->sole()->is_primary);
        $this->assertSame(2, BankAccount::query()->count());
    }

    public function test_without_a_key_the_name_has_to_be_typed_in(): void
    {
        Setting::put('paystack_secret_key', '');
        Setting::flush();

        $this->actingAs($this->admin)
            ->post(route('admin.bank-accounts.store'), [
                'label' => 'Fees account',
                'bank_name' => 'Wema Bank',
                'account_number' => '0123456789',
            ])
            ->assertSessionHasErrors('account_name');

        $this->actingAs($this->admin)
            ->post(route('admin.bank-accounts.store'), [
                'label' => 'Fees account',
                'bank_name' => 'Wema Bank',
                'account_number' => '0123456789',
                'account_name' => 'SACI SCHOOLS LTD',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('SACI SCHOOLS LTD', BankAccount::query()->sole()->account_name);
    }

    /* ------------------------------------------------------------------ */
    /* Giving a class their account numbers */
    /* ------------------------------------------------------------------ */

    public function test_a_class_is_given_account_numbers_in_one_run(): void
    {
        $this->fakePaystack();

        $this->student('SAC/2026/9001');
        $this->student('SAC/2026/9002');
        $withAccount = $this->student('SAC/2026/9003');

        // Somebody who already has one must keep the number their parent saved.
        StudentVirtualAccount::create([
            'student_id' => $withAccount->id,
            'customer_code' => 'CUS_existing',
            'bank_name' => 'Wema Bank',
            'account_number' => '1111111111',
            'account_name' => 'SACI SCHOOLS - EXISTING',
            'provider' => 'paystack',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.bulk-ops.generate'), ['level' => $this->level->id])
            ->assertSessionHas('status', '2 account number(s) opened.');

        $this->assertSame(3, StudentVirtualAccount::query()->count());
        $this->assertSame('1111111111', $withAccount->fresh()->virtualAccount->account_number);
    }

    public function test_a_run_larger_than_one_go_is_refused_before_anything_is_opened(): void
    {
        $this->fakePaystack();

        foreach (range(1, 51) as $n) {
            $this->student(sprintf('SAC/2026/%04d', $n));
        }

        // Each account is two calls to Paystack: a run this size would time out part
        // way through, with no way to tell which half had been done.
        $this->actingAs($this->admin)
            ->post(route('admin.payments.bulk-ops.generate'), ['level' => $this->level->id])
            ->assertSessionHas('error');

        $this->assertSame(0, StudentVirtualAccount::query()->count());
        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /* Fixtures */
    /* ------------------------------------------------------------------ */

    private function fakePaystack(): void
    {
        Http::fake([
            // Listed before the bank list, because /bank/resolve also starts with /bank.
            'api.paystack.co/bank/resolve*' => Http::response([
                'status' => true,
                'data' => ['account_name' => 'SACI SCHOOLS LTD', 'account_number' => '0123456789'],
            ]),
            'api.paystack.co/bank*' => Http::response([
                'status' => true,
                'data' => [['name' => 'Wema Bank', 'code' => '035']],
            ]),
            'api.paystack.co/customer' => Http::response([
                'status' => true,
                'data' => ['customer_code' => 'CUS_opened', 'email' => 'parent@example.com'],
            ]),
            'api.paystack.co/dedicated_account' => Http::response([
                'status' => true,
                'data' => [
                    'account_number' => '2222222222',
                    'account_name' => 'SACI SCHOOLS - CHILD',
                    'bank' => ['name' => 'Wema Bank', 'slug' => 'wema-bank'],
                ],
            ]),
        ]);
    }

    /** @param  array<string,mixed>  $extra */
    private function student(string $number, array $extra = []): Student
    {
        return Student::create($extra + [
            'student_number' => $number,
            'admission_number' => 'SAC-'.str_replace(['/', '.'], '', $number),
            'first_name' => 'Ada',
            'last_name' => 'Okonkwo',
            'guardian_name' => 'Mrs Okonkwo',
            'guardian_phone' => '08031234567',
            'guardian_email' => 'parent@example.com',
            'level_id' => $this->level->id,
            'academic_session_id' => $this->session->id,
            'status' => StudentStatus::Active->value,
            'admitted_at' => now(),
        ]);
    }
}
