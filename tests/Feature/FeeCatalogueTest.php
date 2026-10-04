<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\FeeController;
use App\Models\AcademicSession;
use App\Models\Fee;
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
     * The three tabs whose tables do not exist yet have to say so, rather than looking
     * like a page that loaded wrong.
     */
    public function test_the_tabs_that_are_not_built_say_so(): void
    {
        $fee = Fee::factory()->create();

        foreach ([
            'splits' => 'Nothing records a split yet',
            'class-amounts' => 'Nothing records an amount per class yet',
            'transactions' => 'recorded against a bill and not against the catalogue',
        ] as $tab => $phrase) {
            $this->actingAs($this->admin)
                ->get(route('admin.fees.edit', ['fee' => $fee, 'tab' => $tab]))
                ->assertOk()
                ->assertSee($phrase);
        }
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
