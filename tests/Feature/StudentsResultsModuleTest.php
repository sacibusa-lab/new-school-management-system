<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\StudentsResultsController;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Students & Results module, while it is still a menu and nothing else.
 *
 * What matters at this stage is that no page is a dead end: every item in the
 * sidebar opens something that says plainly it has not been built, rather than
 * five hundred or a blank sheet that looks like a broken filter. Several of these
 * pages are also the only place a permission is checked, so the gate is worth
 * pinning down before the page behind it exists.
 */
class StudentsResultsModuleTest extends TestCase
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

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /* ------------------------------------------------------------------ */
    /* Every page of the menu opens                                        */
    /* ------------------------------------------------------------------ */

    public function test_every_page_in_the_menu_opens_for_the_super_admin(): void
    {
        // Named here rather than read from the controller, so that removing a page
        // from the menu is a failing test rather than a silent disappearance.
        foreach ([
            'dashboard', 'check-result', 'performance', 'pins', 'students', 'employees',
            'academics', 'exam-master', 'attendance', 'reports', 'alumni', 'settings',
        ] as $page) {
            $this->actingAs($this->admin)
                ->get(route('admin.students-results.' . $page))
                ->assertOk();
        }
    }

    public function test_a_blank_page_says_it_is_blank_rather_than_looking_empty(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.pins'))
            ->assertOk()
            ->assertSee('Generate Pin')
            ->assertSee('This page has not been built yet');
    }

    /**
     * Every page shares one placeholder component, so the thing that proves the
     * right view was rendered is its own wording — not its label, which the
     * sidebar shows on every page.
     */
    public function test_each_page_draws_its_own_page_and_not_another(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.exam-master'))
            ->assertOk()
            ->assertSee('Term examinations and the subjects each class sits')
            ->assertDontSee('what a parent buys to check a result');

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.pins'))
            ->assertOk()
            ->assertSee('what a parent buys to check a result')
            ->assertDontSee('Term examinations and the subjects each class sits');
    }

    /* ------------------------------------------------------------------ */
    /* The sidebar                                                         */
    /* ------------------------------------------------------------------ */

    public function test_the_sidebar_lists_the_whole_menu_in_order(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $positions = [];

        foreach (StudentsResultsController::PAGES as $page) {
            $href = route('admin.students-results.' . $page['key']);

            $this->assertStringContainsString($href, $html, "The sidebar is missing {$page['label']}.");

            $positions[] = strpos($html, '>' . $page['label'] . '<');
        }

        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'The menu items are not in the order the office asked for.');
    }

    /** The two links this menu replaced must be gone, or the office sees them twice. */
    public function test_the_old_students_and_results_links_are_no_longer_in_the_sidebar(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('>' . 'Students' . '<', $html);
        $this->assertStringNotContainsString('>' . 'Results' . '<', $html);
    }

    /**
     * A menu is a list of what you can do. An item that always answers 403 is
     * worse than no item, and the module is not the place to find that out.
     */
    public function test_the_sidebar_shows_only_the_pages_this_user_may_open(): void
    {
        // A teacher holds students.view and results.view, and nothing else here.
        $teacher = $this->userWithRole('Teacher');

        $html = $this->actingAs($teacher)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString(route('admin.students-results.students'), $html);
        $this->assertStringNotContainsString(route('admin.students-results.check-result'), $html);
        $this->assertStringNotContainsString(route('admin.students-results.alumni'), $html);
        $this->assertStringNotContainsString(route('admin.students-results.settings'), $html);
    }

    /* ------------------------------------------------------------------ */
    /* The permission behind each page                                     */
    /* ------------------------------------------------------------------ */

    public function test_check_result_is_for_the_super_admin_alone(): void
    {
        // The exam officer holds every results permission except this one.
        $officer = $this->userWithRole('Exam Officer');

        $this->assertTrue($officer->can('results.view'));
        $this->assertTrue($officer->can('results.compute'));
        $this->assertFalse($officer->can('results.check'));

        $this->actingAs($officer)
            ->get(route('admin.students-results.check-result'))
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.check-result'))
            ->assertOk();
    }

    public function test_a_page_that_has_not_been_built_still_refuses_the_wrong_role(): void
    {
        $bursar = $this->userWithRole('Bursar / Accounts');

        // The bursar can see students, but generating result PINs is not theirs.
        $this->assertTrue($bursar->can('students.view'));
        $this->assertFalse($bursar->can('results.pins'));

        $this->actingAs($bursar)->get(route('admin.students-results.students'))->assertOk();
        $this->actingAs($bursar)->get(route('admin.students-results.pins'))->assertForbidden();
        $this->actingAs($bursar)->get(route('admin.students-results.attendance'))->assertForbidden();
    }

    /**
     * The module's own Settings page is not the school-wide one, and must not
     * become a second way in for anybody who can manage settings.
     */
    public function test_the_module_settings_page_is_separate_from_the_school_settings_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students-results.settings'))
            ->assertOk()
            ->assertDontSee('School branding');
    }
}
