<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The menu, on the page you are actually looking at.
 *
 * The sidebar is longer than most screens, so it scrolls — and a fresh page load
 * used to put it back at the top, which meant scrolling down to the entry you had
 * just clicked before you could carry on from it. What keeps it in place is the
 * marker on the entry you are on, which the layout scrolls back into view.
 *
 * Which means the marker has to be on the right entry, and this is where that is
 * pinned down: the link you are on where there is one, and the section holding it
 * where the page is not a menu entry at all.
 */
class SidebarTest extends TestCase
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

    /**
     * A page that is a menu entry is marked as the page: the entry itself where the
     * entry is the page, and the child where the page hangs off one.
     */
    public function test_the_page_you_are_on_is_marked_in_the_menu(): void
    {
        $expected = [
            'admin.dashboard' => 'admin.dashboard',
            'admin.applicants.index' => 'admin.applicants.index',
            'admin.students-results.academics' => 'admin.students-results.academics',
            'admin.students-results.academics.classes' => 'admin.students-results.academics.classes',
            'admin.students-results.teachers' => 'admin.students-results.teachers',
            'admin.students-results.teachers.list' => 'admin.students-results.teachers.list',
            'admin.students-results.teachers.create' => 'admin.students-results.teachers.create',
            // Settings is the general page, and the admissions settings hang off it.
            'admin.settings.index' => 'admin.settings.index',
            'admin.settings.admissions' => 'admin.settings.admissions',
            'admin.admissions.printing' => 'admin.admissions.printing',
        ];

        foreach ($expected as $route => $marked) {
            $menu = $this->menuOn(route($route));

            $this->assertSame(
                1,
                substr_count($menu, 'aria-current="page"'),
                "The menu marks more than one entry, or none, on {$route}.",
            );

            $this->assertStringContainsString(
                'aria-current="page"',
                $this->anchorIn($menu, route($marked)),
                "The menu does not mark {$marked} on {$route}.",
            );
        }
    }

    /**
     * Academic is the case the office named: opening it or any page under it leaves
     * the menu showing that entry rather than its first line.
     */
    public function test_the_entry_you_clicked_stays_the_one_that_is_marked(): void
    {
        $menu = $this->menuOn(route('admin.students-results.academics.classes'));

        // The section is marked, and so is the page inside it.
        $this->assertStringContainsString('data-menu-section', $menu);
        $this->assertStringContainsString(
            'aria-current="page"',
            $this->anchorIn($menu, route('admin.students-results.academics.classes')),
        );

        // And not the parent it hangs off, which would send the menu to the wrong
        // line: Academic is not the page, Classes & Sections is.
        $this->assertStringNotContainsString(
            'aria-current="page"',
            $this->anchorIn($menu, route('admin.students-results.academics')),
        );
    }

    /**
     * A page that is not a menu entry — a detail screen, a row being edited — still
     * has a place in the menu, and that is what the menu is brought back to.
     */
    public function test_a_page_that_is_not_a_menu_entry_is_marked_by_its_section(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $menu = $this->menuOn(route('admin.students-results.teachers.edit', $teacher));

        // Nothing claims to be the page, because no link leads to it...
        $this->assertStringNotContainsString('aria-current="page"', $menu);

        // ...but the section holding it is marked, so the menu still lands there.
        $this->assertSame(1, substr_count($menu, 'data-menu-section'));
        $this->assertStringContainsString(
            'data-menu-section',
            $this->sectionIn($menu, route('admin.students-results.teachers')),
        );
    }

    /**
     * The admissions settings are in the Admissions section, so being on them must
     * not also light up Settings: two entries lit up for one page is a menu telling
     * the office they are in two places.
     */
    public function test_the_admissions_settings_do_not_light_up_settings(): void
    {
        $menu = $this->menuOn(route('admin.settings.admissions'));

        $this->assertStringContainsString(
            'aria-current="page"',
            $this->anchorIn($menu, route('admin.settings.admissions')),
        );

        // The active entry is drawn with a tinted background; Settings must not be.
        $this->assertStringNotContainsString('bg-white/10', $this->anchorIn($menu, route('admin.settings.index')));
    }

    /**
     * Printing is not the cutoff desk, though both live under /admin/admissions —
     * which is why the cutoff entry's `matches` is spelled out rather than left as
     * a wildcard.
     */
    public function test_printing_does_not_light_up_the_cutoff_desk(): void
    {
        $menu = $this->menuOn(route('admin.admissions.printing'));

        $this->assertStringContainsString(
            'aria-current="page"',
            $this->anchorIn($menu, route('admin.admissions.printing')),
        );

        $this->assertStringNotContainsString('bg-white/10', $this->anchorIn($menu, route('admin.admissions.index')));
    }

    /* ------------------------------------------------------------------ */

    /** The sidebar's own markup, without the breadcrumb's <nav> around the page. */
    private function menuOn(string $url): string
    {
        $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

        $start = strpos($html, 'id="admin-menu"');
        $this->assertNotFalse($start, 'The menu is not on the page.');

        $end = strpos($html, '</nav>', $start);

        return substr($html, $start, $end - $start);
    }

    /** The whole opening tag of the link pointing at a URL. */
    private function anchorIn(string $menu, string $href): string
    {
        $at = strpos($menu, 'href="'.$href.'"');
        $this->assertNotFalse($at, "No menu link points at {$href}.");

        $from = strrpos(substr($menu, 0, $at), '<a ');

        return substr($menu, $from, strpos($menu, '>', $at) - $from + 1);
    }

    /** Everything inside the <li> holding a link, children and all. */
    private function sectionIn(string $menu, string $href): string
    {
        $at = strpos($menu, 'href="'.$href.'"');
        $this->assertNotFalse($at, "No menu link points at {$href}.");

        $from = strrpos(substr($menu, 0, $at), '<li ');

        return substr($menu, $from, strpos($menu, '</li>', $at) - $from);
    }
}
