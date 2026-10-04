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
 * never arrives. So the tests are about the checks: the number put to the bank while
 * the form is being filled in AND again before it is saved, one main account rather
 * than two, and a name that has to be typed in when there is no key to ask with.
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
            'bank_code' => '035', 'account_number' => '0123456789', 'is_primary' => '1',
        ]);

        $this->actingAs($this->admin)->post(route('admin.bank-accounts.store'), [
            'bank_code' => '035', 'account_number' => '9876543210', 'is_primary' => '1',
        ]);

        $this->assertSame(1, BankAccount::query()->where('is_primary', true)->count());

        // The one that just went in is the main one; the first is demoted, not removed.
        $this->assertTrue(BankAccount::query()->where('account_number', '9876543210')->sole()->is_primary);
        $this->assertSame(2, BankAccount::query()->count());
    }

    public function test_without_a_key_the_name_has_to_be_typed_in(): void
    {
        Setting::put('paystack_secret_key', '');
        Setting::flush();

        $this->actingAs($this->admin)
            ->post(route('admin.bank-accounts.store'), [
                'bank_name' => 'Wema Bank',
                'account_number' => '0123456789',
            ])
            ->assertSessionHasErrors('account_name');

        $this->actingAs($this->admin)
            ->post(route('admin.bank-accounts.store'), [
                'bank_name' => 'Wema Bank',
                'account_number' => '0123456789',
                'account_name' => 'SACI SCHOOLS LTD',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('SACI SCHOOLS LTD', BankAccount::query()->sole()->account_name);
    }

    /* ------------------------------------------------------------------ */
    /* The number is put to the bank while the form is being filled in */
    /* ------------------------------------------------------------------ */

    /**
     * The office should see what it is about to save. The name that comes back here is a
     * courtesy and not the check — the test above proves the saved name is the bank's —
     * but a page that made you find out on submit is a page you fill in twice.
     */
    public function test_the_bank_is_asked_whose_account_it_is_as_soon_as_it_can_be(): void
    {
        $this->fakePaystack();

        $this->actingAs($this->admin)
            ->postJson(route('admin.bank-accounts.resolve'), [
                'account_number' => '0123456789',
                'bank_code' => '035',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'account_name' => 'SACI SCHOOLS LTD']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/bank/resolve')
            && $request['account_number'] === '0123456789'
            && $request['bank_code'] === '035');

        // Nothing was written. Looking is not saving.
        $this->assertSame(0, BankAccount::query()->count());
    }

    /**
     * A number that is already on the list is the one mistake this can spot without
     * asking the bank anything, and being told while typing beats being told at the end.
     */
    public function test_a_number_that_is_already_saved_is_caught_before_the_bank_is_asked(): void
    {
        $this->fakePaystack();

        BankAccount::factory()->create(['account_number' => '0123456789']);

        $this->actingAs($this->admin)
            ->postJson(route('admin.bank-accounts.resolve'), [
                'account_number' => '0123456789',
                'bank_code' => '035',
            ])
            ->assertOk()
            ->assertJson(['ok' => false, 'message' => 'That account number is already saved.']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/bank/resolve'));
    }

    public function test_a_number_the_bank_does_not_recognise_comes_back_as_a_sentence(): void
    {
        Http::fake([
            'api.paystack.co/bank/resolve*' => Http::response(['status' => false, 'message' => 'Could not resolve account name'], 422),
            'api.paystack.co/bank*' => Http::response(['status' => true, 'data' => [['name' => 'Wema Bank', 'code' => '035']]]),
        ]);

        $this->actingAs($this->admin)
            ->postJson(route('admin.bank-accounts.resolve'), [
                'account_number' => '0123456789',
                'bank_code' => '035',
            ])
            ->assertOk()
            ->assertJson(['ok' => false, 'message' => 'Could not resolve account name']);
    }

    public function test_half_a_number_is_not_worth_asking_about(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->postJson(route('admin.bank-accounts.resolve'), [
                'account_number' => '0123',
                'bank_code' => '035',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('account_number');

        Http::assertNothingSent();
    }

    public function test_somebody_who_cannot_manage_fees_cannot_ask_the_bank(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)
            ->postJson(route('admin.bank-accounts.resolve'), [
                'account_number' => '0123456789',
                'bank_code' => '035',
            ])
            ->assertForbidden();
    }

    /**
     * The caption is gone from the form and from the table, and the number it was there
     * to explain is now the first thing asked for.
     */
    public function test_the_page_no_longer_asks_what_an_account_is_for(): void
    {
        $this->fakePaystack();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bank-accounts.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="label"', $html);
        $this->assertStringNotContainsString('What it is for', $html);

        // And the number is drawn before the bank, because the bank cannot be chosen
        // until it is complete.
        $this->assertLessThan(
            strpos($html, 'name="bank_code"'),
            strpos($html, 'name="account_number"'),
        );

        // The page knows where to ask. @js() escapes the slashes — that is part of why it
        // is safe in an attribute — so the raw route is not what is in the page.
        $this->assertStringContainsString(
            str_replace('/', '\/', route('admin.bank-accounts.resolve')),
            $html,
        );
    }

    /**
     * The Alpine object is an HTML attribute, so a double quote anywhere inside it —
     * including in a comment — ends the attribute early and leaves the browser evaluating
     * the first half of an object. Every method in it then vanishes, and the page reports
     * `typed is not defined` while looking perfectly normal.
     *
     * Anchored on the form's own action, because the layout's header carries an x-data of
     * its own and it comes first in the document.
     */
    public function test_the_alpine_object_survives_being_an_html_attribute(): void
    {
        $this->fakePaystack();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bank-accounts.index'))->assertOk()->getContent();

        $form = strpos($html, 'action="'.route('admin.bank-accounts.store').'"');
        $start = strpos($html, 'x-data="', $form) + strlen('x-data="');
        $block = substr($html, $start, strpos($html, '"', $start) - $start);

        $this->assertStringContainsString(
            'resolveUrl',
            $block,
            'The x-data attribute ended before the object did — something inside it contains a double quote.',
        );

        // And it reaches the far end of the object, where the methods are.
        $this->assertStringContainsString('typed(', $block);
        $this->assertStringContainsString('check()', $block);
    }

    /**
     * The two situations that leave the page without a bank list are not the same thing, and
     * saying "not set up" when it is set up sends the office to re-check a key that is
     * already correct. It happened: the key was right and the machine had no CA bundle, so
     * nothing could reach Paystack at all.
     *
     * Two tests rather than one, because the key has to be settled before the first request
     * of a test — a provider built while the app was serving that request keeps the key it
     * was built with. Changing the setting between two requests in one test measures
     * nothing.
     */
    public function test_the_page_says_so_when_paystack_has_a_key_but_did_not_answer(): void
    {
        // A key, and Paystack gives an empty answer rather than refusing outright.
        Http::fake([
            'api.paystack.co/bank*' => Http::response(['status' => true, 'data' => []]),
        ]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bank-accounts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('did not answer just now', $html);
        $this->assertStringNotContainsString('Paystack is not set up', $html);
    }

    public function test_the_page_says_so_when_paystack_is_not_set_up_at_all(): void
    {
        Setting::put('paystack_secret_key', '');
        Setting::flush();

        // Nothing should be called at all, so a stray request would be a real failure.
        Http::fake();

        $html = $this->actingAs($this->admin)
            ->get(route('admin.bank-accounts.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Paystack is not set up', $html);
        $this->assertStringNotContainsString('did not answer just now', $html);
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
