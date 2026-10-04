<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\FeesPaymentsController;
use App\Http\Controllers\Admin\PaymentController;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The collection side of fees, taken from the school's own fees site.
 *
 * Most of these screens are not built yet, so what is held here is what has already
 * gone wrong once in this project: a section committed with its routes and its menu
 * and without its views, so that every entry in the menu answered 500. A page that
 * says what will be on it is a placeholder; a page that errors is a hole.
 */
class PaymentScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        Setting::flush();

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
    }

    public function test_every_page_of_the_payments_section_renders(): void
    {
        foreach (PaymentController::PAGES as $page) {
            $this->actingAs($this->admin)
                ->get(route('admin.payments.'.$page['key']))
                ->assertOk()
                ->assertSee($page['label']);
        }
    }

    /**
     * The register that used to sit under Fees is this section's Transactions page —
     * one page, reached from one place, so the menu is not two ways into one screen
     * under two names.
     */
    public function test_the_payments_register_is_reachable(): void
    {
        $this->actingAs($this->admin)->get(route('admin.payments.index'))->assertOk();
    }

    public function test_the_menu_offers_the_collection_screens_in_the_office_order(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.payments.index'))->assertOk()->getContent();

        $positions = [];

        foreach (PaymentController::PAGES as $page) {
            $at = strpos($html, route('admin.payments.'.$page['key']));

            $this->assertNotFalse($at, "The sidebar does not offer {$page['label']}.");

            $positions[] = $at;
        }

        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'The Payments menu is not in the order the office asked for.');
    }

    public function test_somebody_outside_the_fee_desk_cannot_open_them(): void
    {
        $student = User::factory()->create();
        $student->assignRole('Student');

        foreach (PaymentController::PAGES as $page) {
            $this->actingAs($student)
                ->get(route('admin.payments.'.$page['key']))
                ->assertForbidden();
        }
    }

    /**
     * The banner owns two screens of its own: a dashboard for the money, and a hub for
     * the students read by what they owe. Neither belongs to the fees desk or the
     * collection desk, which is why they hang off neither of them.
     */
    public function test_the_section_has_its_own_dashboard_and_students_hub(): void
    {
        foreach (FeesPaymentsController::PAGES as $page) {
            $this->actingAs($this->admin)
                ->get(route('admin.fees-payments.'.$page['key']))
                ->assertOk()
                ->assertSee($page['label']);
        }
    }

    /**
     * The five groups the office asked for, all reachable from the sidebar: the two the
     * section owns, the fees, the collection, and what students are let off.
     */
    public function test_the_banner_holds_the_five_groups_the_office_asked_for(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.payments.index'))->assertOk()->getContent();

        foreach ([
            route('admin.fees-payments.dashboard'),
            route('admin.fees-payments.students-hub'),
            route('admin.fees.index'),
            route('admin.payments.overview'),
            route('admin.fees.scholarships.index'),
        ] as $href) {
            $this->assertStringContainsString($href, $html, "The banner does not offer {$href}.");
        }
    }
}
