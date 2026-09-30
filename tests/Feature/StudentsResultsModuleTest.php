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
    /* Every page of the menu opens */
    /* ------------------------------------------------------------------ */

    public function test_every_page_in_the_menu_opens_for_the_super_admin(): void
    {
        // Named here rather than read from the controller, so that removing a page
        // from the menu is a failing test rather than a silent disappearance.
        foreach ([
            'dashboard', 'check-result', 'performance', 'pins', 'students', 'teachers',
            'academics', 'exam-master', 'attendance', 'reports', 'alumni', 'settings',
        ] as $page) {
            $this->actingAs($this->admin)
                ->get(route('admin.students-results.'.$page))
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
    /* The sidebar */
    /* ------------------------------------------------------------------ */

    public function test_the_sidebar_lists_the_whole_menu_in_order(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $positions = [];

        foreach (StudentsResultsController::PAGES as $page) {
            $href = route('admin.students-results.'.$page['key']);

            $this->assertStringContainsString($href, $html, "The sidebar is missing {$page['label']}.");

            // The link itself, not the label: a label is bare text in several
            // places on a page, and matching on it made this assertion pass with
            // every position false — that is, without checking the order at all.
            $positions[] = strpos($html, 'href="'.$href.'"');
        }

        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'The menu items are not in the order the office asked for.');
    }

    /** The two links this menu replaced must be gone, or the office sees them twice. */
    public function test_the_old_students_and_results_links_are_no_longer_in_the_sidebar(): void
    {
        $html = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString('>'.'Students'.'<', $html);
        $this->assertStringNotContainsString('>'.'Results'.'<', $html);
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
    /* The permission behind each page */
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

    /* ------------------------------------------------------------------ */
    /* Academic, and the four pages under it */
    /* ------------------------------------------------------------------ */

    public function test_every_page_under_academic_opens_and_is_the_page_it_claims(): void
    {
        // Classes & Sections is built, so it is no longer one of the pages that says
        // it has not been built; the three that are left still are, each with the
        // note written for it — which is what proves the right one drew.
        foreach (['subjects', 'schedule', 'promotion'] as $key) {
            $child = collect(StudentsResultsController::ACADEMIC_PAGES)->firstWhere('key', $key);

            $this->actingAs($this->admin)
                ->get(route($child['route']))
                ->assertOk()
                ->assertSee($child['note'])
                ->assertSee('This page has not been built yet');
        }

        $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.classes'))
            ->assertOk()
            ->assertSee('Create Class')
            ->assertDontSee('This page has not been built yet');
    }

    public function test_the_academic_page_lists_the_four_things_it_holds(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics'))
            ->assertOk()
            ->getContent();

        $positions = [];

        foreach (StudentsResultsController::ACADEMIC_PAGES as $child) {
            $this->assertStringContainsString(
                route($child['route']),
                $html,
                "Academic does not link to {$child['label']}.",
            );

            $at = strpos($html, $child['note']);

            $this->assertNotFalse($at, "Academic does not describe {$child['label']}.");
            $positions[] = $at;
        }

        $sorted = $positions;
        sort($sorted);

        $this->assertSame($sorted, $positions, 'Academic lists its pages out of the order the office asked for.');
    }

    /**
     * The four are a second level, and a second level that is always open is just
     * a longer first level. They appear once Academic is the page you are on.
     */
    public function test_the_four_pages_are_drawn_under_academic_only_while_it_is_open(): void
    {
        $dashboard = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

        $academic = $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics'))
            ->assertOk()
            ->getContent();

        foreach (StudentsResultsController::ACADEMIC_PAGES as $child) {
            $this->assertStringNotContainsString(
                route($child['route']),
                $dashboard,
                "{$child['label']} is in the sidebar before Academic has been opened.",
            );

            $this->assertStringContainsString(
                route($child['route']),
                $academic,
                "The sidebar does not offer {$child['label']} from Academic.",
            );
        }
    }

    /**
     * Standing on a page under Academic has to leave Academic looking open —
     * otherwise the four vanish the moment you use one, and the only way back is
     * up a level. That they render at all on a child route is the proof: the
     * sidebar only draws them for the link it considers active.
     */
    public function test_the_four_stay_in_the_sidebar_while_you_are_on_one_of_them(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics.promotion'))
            ->assertOk()
            ->getContent();

        foreach (StudentsResultsController::ACADEMIC_PAGES as $child) {
            $this->assertStringContainsString(route($child['route']), $html);
        }
    }

    /**
     * They are one subject — how the school is organised — so they answer to the
     * permission of the page they hang off, and a role without it is refused the
     * whole section rather than half of it.
     */
    public function test_the_pages_under_academic_are_refused_without_the_permission(): void
    {
        $teacher = $this->userWithRole('Teacher');

        $this->assertFalse($teacher->can('academics.manage'));

        $this->actingAs($teacher)
            ->get(route('admin.students-results.academics'))
            ->assertForbidden();

        foreach (StudentsResultsController::ACADEMIC_PAGES as $child) {
            $this->actingAs($teacher)
                ->get(route($child['route']))
                ->assertForbidden();
        }

        // And Academic is not in their sidebar to be clicked in the first place.
        $sidebar = $this->actingAs($teacher)->get(route('admin.dashboard'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('admin.students-results.academics'), $sidebar);
    }

    /* ------------------------------------------------------------------ */
    /* Breadcrumbs */
    /* ------------------------------------------------------------------ */

    /**
     * Three levels down there has to be a way back that is not the browser button:
     * the sidebar sub-items say where you may go, and this says where you are.
     */
    public function test_a_page_under_academic_shows_the_trail_back_to_it(): void
    {
        foreach (StudentsResultsController::ACADEMIC_PAGES as $child) {
            $html = $this->actingAs($this->admin)
                ->get(route($child['route']))
                ->assertOk()
                ->getContent();

            // Read the trail itself, not the page: both parent URLs are also in the
            // sidebar, where finding them would prove nothing.
            $trail = str($html)->after('aria-label="Breadcrumb"')->before('</nav>')->value();

            $this->assertStringContainsString('Students &amp; Results', $trail, 'The trail does not start at the module.');
            $this->assertStringContainsString('Academic', $trail, 'The trail skips Academic.');

            // e() because this reads the HTML: "Classes & Sections" is written to
            // the page as "Classes &amp; Sections".
            $this->assertStringContainsString(e($child['label']), $trail, "The trail does not end at {$child['label']}.");

            // Both parents are ways out of here...
            $this->assertStringContainsString('href="'.route('admin.students-results.dashboard').'"', $trail);
            $this->assertStringContainsString('href="'.route('admin.students-results.academics').'"', $trail);

            // ...and the page you are on is not one of them.
            $this->assertStringNotContainsString('href="'.route($child['route']).'"', $trail);
        }
    }

    public function test_academic_itself_says_it_sits_under_the_module(): void
    {
        $html = $this->actingAs($this->admin)
            ->get(route('admin.students-results.academics'))
            ->assertOk()
            ->getContent();

        $trail = str($html)->after('aria-label="Breadcrumb"')->before('</nav>')->value();

        $this->assertStringContainsString('Students &amp; Results', $trail);
        $this->assertStringContainsString(route('admin.students-results.dashboard'), $trail);

        // Academic is where you are, so it is the one crumb that is not a link.
        $this->assertStringNotContainsString('href="'.route('admin.students-results.academics').'"', $trail);
    }

    /**
     * The trail is read off the menu, so every page of the module has one — the
     * pages that are not built yet included, because where a page sits is decided
     * by the menu holding it rather than by the page having anything on it.
     */
    public function test_every_page_of_the_module_says_where_it_sits(): void
    {
        foreach (StudentsResultsController::PAGES as $page) {
            $html = $this->actingAs($this->admin)
                ->get(route('admin.students-results.'.$page['key']))
                ->assertOk()
                ->getContent();

            // Asserted first so the slice below cannot quietly fall back to the
            // sidebar, whose own </nav> and section heading would answer for it.
            $this->assertStringContainsString('aria-label="Breadcrumb"', $html, "{$page['label']} has no trail.");

            $trail = str($html)->after('aria-label="Breadcrumb"')->before('</nav>')->value();

            $this->assertStringContainsString('Students &amp; Results', $trail, "{$page['label']} has no trail.");
            $this->assertStringContainsString($page['label'], $trail, "The trail does not name {$page['label']}.");
        }
    }
}
