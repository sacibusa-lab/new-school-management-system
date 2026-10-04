<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\FeeController;
use App\Models\AcademicSession;
use App\Models\BankAccount;
use App\Models\Fee;
use App\Models\FeeBeneficiary;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The catalogue of fees the school charges.
 *
 * Three things worth pinning down, because they are the decisions rather than the
 * plumbing. A fee is created active and there is nowhere in the modal to say otherwise.
 * A fee with no session belongs to every session, which is the usual answer for a levy. And
 * an untouched term checkbox means "no", not "yes" — a fee entered for the first term only
 * must not quietly be charged in the other two.
 */
class FeeCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicSession $session;

    private string $currency;

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
    }

    public function test_the_catalogue_lists_what_the_school_charges(): void
    {
        Fee::factory()->create([
            'title' => 'Tuition Fee',
            'description' => 'Per term, per child',
            'cycle' => 'termly',
            'academic_session_id' => $this->session->id,
            'amount' => 85000,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.fees.index'))
            ->assertOk()
            ->assertSee('Fee list')
            ->assertSee('Tuition Fee')
            // The description sits under the title rather than in a column of its own.
            ->assertSee('Per term, per child')
            ->assertSee('Termly')
            ->assertSee($this->session->name)
            ->assertSee($this->currency.'85,000.00')
            ->assertSee('Active');
    }

    public function test_a_fee_is_added_from_the_modal(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.fees.store'), [
                'title' => 'Development Levy',
                'description' => 'Once a session, towards the new block',
                'cycle' => 'one-time',
                'academic_session_id' => $this->session->id,
                'amount' => 15000,
                'first_term_active' => '1',
            ])
            ->assertRedirect(route('admin.fees.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $fee = Fee::query()->sole();

        $this->assertSame('Development Levy', $fee->title);
        $this->assertSame('one-time', $fee->cycle);
        $this->assertSame($this->session->id, $fee->academic_session_id);
        $this->assertSame('15000.00', $fee->amount);

        // Created active. Switching a fee off is a decision about a fee that exists, made
        // on its own page, not something to get wrong as a title is first typed in.
        $this->assertTrue($fee->is_active);

        // Only the term that was ticked, and an unticked box is a no.
        $this->assertTrue($fee->first_term_active);
        $this->assertFalse($fee->second_term_active);
        $this->assertFalse($fee->third_term_active);
    }

    public function test_a_fee_needs_a_title_a_real_cycle_and_an_amount(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.fees.store'), [])
            ->assertSessionHasErrors(['title', 'cycle', 'amount']);

        // "Weekly" is not one of the three cycles this school bills in.
        $this->actingAs($this->admin)
            ->post(route('admin.fees.store'), [
                'title' => 'Tuition',
                'cycle' => 'weekly',
                'amount' => 1000,
            ])
            ->assertSessionHasErrors('cycle');

        $this->assertSame(0, Fee::query()->count());
    }

    public function test_a_fee_with_no_session_belongs_to_every_one_of_them(): void
    {
        Fee::factory()->forEverySession()->create(['title' => 'PTA Levy']);

        $this->actingAs($this->admin)
            ->get(route('admin.fees.index'))
            ->assertOk()
            ->assertSee('PTA Levy')
            // Named rather than left blank, so an empty cell is never mistaken for a fee
            // somebody forgot to finish.
            ->assertSee('Every session');
    }

    public function test_the_list_reads_a_to_z(): void
    {
        Fee::factory()->create(['title' => 'Uniform']);
        Fee::factory()->create(['title' => 'Books & Stationery']);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees.index'))
            ->assertOk()
            ->getContent();

        $books = strpos($html, 'Books &amp; Stationery');
        $uniform = strpos($html, 'Uniform');

        $this->assertNotFalse($books);
        $this->assertNotFalse($uniform);
        $this->assertLessThan($uniform, $books, 'The list is not in alphabetical order.');
    }

    public function test_a_fee_the_school_has_stopped_charging_is_marked_inactive(): void
    {
        Fee::factory()->inactive()->create(['title' => 'Old ICT Levy']);

        $this->actingAs($this->admin)
            ->get(route('admin.fees.index'))
            ->assertOk()
            ->assertSee('Old ICT Levy')
            ->assertSee('Inactive');
    }

    /**
     * A fee entered for the first term only must not read as a fee for the year.
     */
    public function test_a_termly_fee_says_which_terms_it_comes_round_in(): void
    {
        $partly = Fee::factory()->create([
            'title' => 'Mock Examination Fee',
            'second_term_active' => false,
            'third_term_active' => false,
        ]);

        $everyTerm = Fee::factory()->create(['title' => 'Tuition Fee']);

        $this->assertSame('First', $partly->termsSummary());
        $this->assertSame('All three terms', $everyTerm->termsSummary());

        // An annual fee has no terms to be active in, so the page says nothing about them.
        $this->assertFalse(Fee::factory()->annual()->make()->isTermly());

        $this->actingAs($this->admin)
            ->get(route('admin.fees.index'))
            ->assertOk()
            ->assertSee('All three terms');
    }

    public function test_a_fee_has_five_tabs_and_opens_on_details(): void
    {
        $fee = Fee::factory()->create(['title' => 'Tuition Fee', 'amount' => 85000]);

        $html = $this->actingAs($this->admin)
            ->get(route('admin.fees.edit', $fee))
            ->assertOk()
            ->assertSee('Tuition Fee')
            ->assertSee($this->currency.'85,000.00')
            ->getContent();

        foreach (FeeController::TABS as $tab) {
            $this->assertStringContainsString($tab['label'], $html, "The {$tab['label']} tab is missing.");
            $this->assertStringContainsString(
                route('admin.fees.edit', ['fee' => $fee, 'tab' => $tab['key']]),
                $html,
                "The {$tab['label']} tab does not link to itself.",
            );
        }

        // Details is where you land, and the only tab with a form on it.
        $this->assertStringContainsString('Save changes', $html);
    }

    /**
     * An unknown tab is somebody's stale bookmark, not an error worth a stack trace.
     */
    public function test_an_unknown_tab_falls_back_to_details(): void
    {
        $fee = Fee::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.fees.edit', ['fee' => $fee, 'tab' => 'nonsense']))
            ->assertOk()
            ->assertSee('Save changes');
    }

    public function test_the_details_tab_saves_the_fee(): void
    {
        $fee = Fee::factory()->create(['title' => 'Tuition Fee', 'cycle' => 'termly', 'amount' => 85000]);

        $this->actingAs($this->admin)
            ->put(route('admin.fees.update', $fee), [
                'title' => 'Tuition (Senior)',
                'description' => 'Per term, per child',
                'cycle' => 'annually',
                'academic_session_id' => $this->session->id,
                'amount' => 120000,
                'first_term_active' => '1',
                'second_term_active' => '1',
            ])
            ->assertRedirect(route('admin.fees.edit', ['fee' => $fee, 'tab' => 'details']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $fee->refresh();

        $this->assertSame('Tuition (Senior)', $fee->title);
        $this->assertSame('annually', $fee->cycle);
        $this->assertSame($this->session->id, $fee->academic_session_id);
        $this->assertSame('120000.00', $fee->amount);
        $this->assertTrue($fee->second_term_active);
        $this->assertFalse($fee->third_term_active);
    }

    /**
     * A form that saved the title must not be able to switch the fee on as a side effect
     * of something nobody was looking at. The standing is changed in Settings.
     */
    public function test_saving_the_details_does_not_change_the_standing(): void
    {
        $fee = Fee::factory()->inactive()->create(['title' => 'Old Levy']);

        $this->actingAs($this->admin)
            ->put(route('admin.fees.update', $fee), [
                'title' => 'Old Levy',
                'cycle' => 'termly',
                'amount' => 5000,
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($fee->fresh()->is_active);
    }

    public function test_the_settings_tab_switches_a_fee_on_and_off(): void
    {
        $fee = Fee::factory()->create(['title' => 'Tuition Fee']);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.toggle', $fee))
            ->assertRedirect(route('admin.fees.edit', ['fee' => $fee, 'tab' => 'settings']))
            ->assertSessionHas('status');

        $this->assertFalse($fee->fresh()->is_active);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.toggle', $fee))
            ->assertSessionHas('status');

        // Switched back on, and still there: off is not deleted.
        $this->assertTrue($fee->fresh()->is_active);
    }

    /**
     * The one tab whose table does not exist yet has to say so, rather than looking like a
     * page that loaded wrong.
     */
    public function test_the_tab_that_is_not_built_says_so(): void
    {
        $fee = Fee::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.fees.edit', ['fee' => $fee, 'tab' => 'transactions']))
            ->assertOk()
            ->assertSee('Nothing to show yet')
            ->assertSee('recorded against a bill and not against the catalogue');
    }

    /* ------------------------------------------------------------------ */
    /* Who a fee is divided between */
    /* ------------------------------------------------------------------ */

    public function test_a_fee_can_be_split_between_the_schools_own_accounts(): void
    {
        $fee = Fee::factory()->create(['title' => 'Tuition Fee', 'amount' => 100000]);

        $feesAccount = BankAccount::factory()->create(['label' => 'Fees account', 'account_number' => '1111111111']);
        $ptaAccount = BankAccount::factory()->create(['label' => 'PTA account', 'account_number' => '2222222222']);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.beneficiaries', $fee), [
                'beneficiaries' => [
                    ['bank_account_id' => $feesAccount->id, 'amount' => 60000],
                    ['bank_account_id' => $ptaAccount->id, 'amount' => 25000],
                ],
            ])
            ->assertRedirect(route('admin.fees.edit', ['fee' => $fee, 'tab' => 'splits']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(2, $fee->beneficiaries()->count());

        // 85,000 of 100,000 promised away, so 15,000 still pays into the main account.
        $split = $fee->load('beneficiaries');
        $this->assertSame(85000.0, $split->splitTotal());
        $this->assertSame(15000.0, $split->unsplitAmount());

        $this->actingAs($this->admin)
            ->get(route('admin.fees.edit', ['fee' => $fee, 'tab' => 'splits']))
            ->assertOk()
            ->assertSee('Fees account')
            ->assertSee('1111111111')
            ->assertSee($this->currency.'60,000.00')
            // What is left over is shown rather than left to be worked out.
            ->assertSee($this->currency.'15,000.00');
    }

    /**
     * The whole point of the rule, and the reason it is checked on the server: the form
     * only warns, and a request does not have to come from the form.
     */
    public function test_the_splits_cannot_come_to_more_than_the_fee(): void
    {
        $fee = Fee::factory()->create(['amount' => 100000]);

        $first = BankAccount::factory()->create();
        $second = BankAccount::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.fees.beneficiaries', $fee), [
                'beneficiaries' => [
                    ['bank_account_id' => $first->id, 'amount' => 80000],
                    ['bank_account_id' => $second->id, 'amount' => 40000],
                ],
            ])
            ->assertSessionHasErrors('beneficiaries');

        // Nothing saved: a split that does not add up is not half-saved.
        $this->assertSame(0, $fee->beneficiaries()->count());
    }

    /**
     * Two rows for one account would be two transfers to it, which is never what was meant.
     * The database refuses it; this is so the office is told, instead of shown a 500.
     */
    public function test_a_fee_cannot_be_split_into_the_same_account_twice(): void
    {
        $fee = Fee::factory()->create(['amount' => 100000]);
        $account = BankAccount::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.fees.beneficiaries', $fee), [
                'beneficiaries' => [
                    ['bank_account_id' => $account->id, 'amount' => 30000],
                    ['bank_account_id' => $account->id, 'amount' => 30000],
                ],
            ])
            ->assertSessionHasErrors('beneficiaries.0.bank_account_id');

        $this->assertSame(0, $fee->beneficiaries()->count());
    }

    /**
     * Removing every split has to be possible — it means the fee pays wholly into the main
     * account, which is a real thing to want.
     */
    public function test_every_split_can_be_removed(): void
    {
        $fee = Fee::factory()->create(['title' => 'Tuition Fee', 'amount' => 100000]);
        FeeBeneficiary::factory()->create(['fee_id' => $fee->id, 'amount' => 40000]);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.beneficiaries', $fee), [])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(0, $fee->beneficiaries()->count());
    }

    /* ------------------------------------------------------------------ */
    /* What a year group is charged */
    /* ------------------------------------------------------------------ */

    public function test_a_year_group_can_be_charged_other_than_the_default(): void
    {
        $fee = Fee::factory()->create(['title' => 'Tuition Fee', 'amount' => 85000]);
        $senior = SchoolLevel::factory()->create(['name' => 'SS1', 'order' => 4]);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.overrides', $fee), [
                'overrides' => [
                    ['level_id' => $senior->id, 'amount' => 120000, 'status' => 'active'],
                ],
            ])
            ->assertRedirect(route('admin.fees.edit', ['fee' => $fee, 'tab' => 'class-amounts']))
            ->assertSessionHasNoErrors();

        $override = $fee->overrides()->sole();
        $this->assertSame('120000.00', $override->amount);
        $this->assertTrue($override->isActive());

        $this->actingAs($this->admin)
            ->get(route('admin.fees.edit', ['fee' => $fee, 'tab' => 'class-amounts']))
            ->assertOk()
            ->assertSee('SS1')
            ->assertSee($this->currency.'120,000.00');
    }

    public function test_the_same_year_group_cannot_be_listed_twice(): void
    {
        $fee = Fee::factory()->create(['amount' => 85000]);
        $level = SchoolLevel::factory()->create(['name' => 'JSS1']);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.overrides', $fee), [
                'overrides' => [
                    ['level_id' => $level->id, 'amount' => 1000, 'status' => 'active'],
                    ['level_id' => $level->id, 'amount' => 2000, 'status' => 'active'],
                ],
            ])
            ->assertSessionHasErrors('overrides.0.level_id');

        $this->assertSame(0, $fee->overrides()->count());
    }

    /**
     * A price set up in advance is not a deleted one: the amount is kept and switched off,
     * so a fee the school is not charging yet does not have to be typed in twice.
     */
    public function test_a_year_group_amount_can_be_switched_off_without_being_removed(): void
    {
        $fee = Fee::factory()->create(['amount' => 85000]);
        $level = SchoolLevel::factory()->create(['name' => 'JSS3']);

        $this->actingAs($this->admin)
            ->post(route('admin.fees.overrides', $fee), [
                'overrides' => [
                    ['level_id' => $level->id, 'amount' => 95000, 'status' => 'inactive'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse($fee->overrides()->sole()->isActive());
    }

    /* ------------------------------------------------------------------ */
    /* What a term costs */
    /* ------------------------------------------------------------------ */

    public function test_a_term_can_be_priced_apart_from_the_default(): void
    {
        $fee = Fee::factory()->create(['title' => 'Tuition Fee', 'amount' => 85000]);

        $this->actingAs($this->admin)
            ->put(route('admin.fees.update', $fee), [
                'title' => 'Tuition Fee',
                'revenue_code' => 'TUI-01',
                'cycle' => 'termly',
                'amount' => 85000,
                'first_term_amount' => 95000,
            ])
            ->assertSessionHasNoErrors();

        $fee->refresh();

        $this->assertSame('TUI-01', $fee->revenue_code);

        // The first term costs more because of resumption; the others fall back.
        $this->assertSame('95000.00', $fee->amountForTerm(1));
        $this->assertSame('85000.00', $fee->amountForTerm(2));
        $this->assertSame('85000.00', $fee->amountForTerm(3));
    }

    /**
     * An emptied amount box means "charge the default here", not nought. Stored as null so
     * a term nobody has priced and a term that costs nothing stay different answers.
     */
    public function test_an_empty_term_amount_means_the_default_and_not_nought(): void
    {
        $fee = Fee::factory()->create(['amount' => 85000]);

        $this->actingAs($this->admin)
            ->put(route('admin.fees.update', $fee), [
                'title' => $fee->title,
                'cycle' => 'termly',
                'amount' => 85000,
                'second_term_amount' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($fee->refresh()->second_term_amount);
    }

    public function test_somebody_outside_the_fee_desk_cannot_change_a_fee(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $fee = Fee::factory()->create(['title' => 'Tuition Fee', 'amount' => 85000]);

        $this->actingAs($teacher)->get(route('admin.fees.edit', $fee))->assertForbidden();

        $this->actingAs($teacher)
            ->put(route('admin.fees.update', $fee), ['title' => 'Changed', 'cycle' => 'termly', 'amount' => 1])
            ->assertForbidden();

        $this->actingAs($teacher)->post(route('admin.fees.toggle', $fee))->assertForbidden();

        $fee->refresh();
        $this->assertSame('Tuition Fee', $fee->title);
        $this->assertTrue($fee->is_active);
    }

    public function test_somebody_outside_the_fee_desk_cannot_open_the_catalogue(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->actingAs($teacher)->get(route('admin.fees.index'))->assertForbidden();

        $this->actingAs($teacher)
            ->post(route('admin.fees.store'), [
                'title' => 'Something',
                'cycle' => 'termly',
                'amount' => 1000,
            ])
            ->assertForbidden();

        $this->assertSame(0, Fee::query()->count());
    }
}
