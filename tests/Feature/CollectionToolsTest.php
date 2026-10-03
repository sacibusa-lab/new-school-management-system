<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\BankAccount;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\Term;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The school's own bank accounts.
 *
 * These hold the numbers the school is paid into, and a wrong digit is money that
 * never arrives. So the tests are about the checks: the number put to the bank before
 * it is saved, one main account rather than two, and a name that has to be typed in
 * when there is no key to ask the bank with.
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
        ]);
    }
}
