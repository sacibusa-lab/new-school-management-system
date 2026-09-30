<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Where a page sits, read off the page rather than off the menu class.
 *
 * The trail is derived from the menu, so the thing worth testing is the result the
 * office sees: that the crumb on a real screen names the section, the page and — on
 * a detail screen — the child it is about. Reading AdminMenu's array back to itself
 * would test the declaration and pass while the layout drew nothing.
 */
class BreadcrumbTest extends TestCase
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
     * One page from each kind: a section with a single page, two pages side by side
     * under one heading, a page with no place of its own in the menu, and a page
     * that hangs off another.
     */
    public function test_every_page_the_menu_holds_says_where_it_sits(): void
    {
        $expected = [
            'admin.dashboard' => ['Overview', 'Dashboard'],
            'admin.applicants.index' => ['Admissions', 'Applicants'],
            'admin.applicants.create' => ['Admissions', 'Register applicant'],
            'admin.exams.index' => ['Admissions', 'Examinations'],
            'admin.exams.create' => ['Admissions', 'Examinations', 'New examination'],
            'admin.scores.index' => ['Admissions', 'Score entry'],
            // Its own place in the menu, under the section it belongs to.
            'admin.admissions.printing' => ['Admissions', 'Printing'],
            'admin.settings.index' => ['Administration', 'Settings'],
            // Its own place in the menu, in the section it belongs to.
            'admin.settings.admissions' => ['Admissions', 'Admissions settings'],
            'admin.students-results.academics.classes' => ['Students & Results', 'Academic', 'Classes & Sections'],
            // A section of the module with a page of its own, and two pages under it.
            'admin.students-results.teachers' => ['Students & Results', 'Teachers'],
            'admin.students-results.teachers.list' => ['Students & Results', 'Teachers', 'Teachers List'],
            'admin.students-results.teachers.create' => ['Students & Results', 'Teachers', 'Add Teachers'],
        ];

        foreach ($expected as $route => $crumbs) {
            $this->assertSame($crumbs, $this->trailOn(route($route)), "Wrong trail on {$route}.");
        }
    }

    /**
     * A detail screen names the record it is about as the last crumb, rather than
     * repeating the page it came from.
     */
    public function test_a_page_about_one_record_ends_its_trail_with_that_record(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('Teacher');

        $this->assertSame(
            ['Students & Results', 'Teachers', 'Edit Teacher'],
            $this->trailOn(route('admin.students-results.teachers.edit', $teacher)),
        );
    }

    /**
     * Where you are is drawn as text. A link to the page already on screen is a way
     * out that leads nowhere, and the trail above a page is the one place it always
     * looks like one.
     */
    public function test_the_page_you_are_on_is_never_a_link_in_its_own_trail(): void
    {
        foreach ([
            'admin.dashboard',
            'admin.applicants.index',
            'admin.applicants.create',
            'admin.exams.create',
            'admin.settings.index',
            'admin.students-results.academics.classes',
        ] as $route) {
            $this->assertStringNotContainsString(
                'href="'.route($route).'"',
                $this->crumbMarkupOn(route($route)),
                "The trail on {$route} links to the page it is on.",
            );
        }
    }

    /**
     * Everything above the last crumb is a way back, not decoration. Here that is
     * the section's own front page: Students & Results opens the module dashboard,
     * so the heading of that section is a link and the headings of the others — which
     * are groups of pages with nothing between them — are not.
     */
    public function test_the_section_is_a_way_back_where_the_section_has_a_front_page(): void
    {
        $this->assertStringContainsString(
            'href="'.route('admin.students-results.dashboard').'"',
            $this->crumbMarkupOn(route('admin.students-results.academics.classes')),
        );

        $this->assertStringNotContainsString(
            'href="'.route('admin.applicants.index').'"',
            $this->crumbMarkupOn(route('admin.dashboard')),
        );
    }

    /**
     * A page the menu does not hold gets no trail rather than a wrong one. The flat
     * /admin/students screen is the example: the module's Students Details page is
     * replacing it, and pointing this page at its replacement would be a lie about
     * where the two sit.
     */
    public function test_a_page_the_menu_does_not_hold_gets_no_trail(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.students.index'))
            ->assertOk()
            ->assertDontSee('aria-label="Breadcrumb"', false);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The labels of the trail on a page, in the order they are drawn.
     *
     * @return array<int,string>
     */
    private function trailOn(string $url): array
    {
        preg_match_all('/<li\b[^>]*>(.*?)<\/li>/s', $this->crumbMarkupOn($url), $matches);

        return array_values(array_filter(array_map(
            fn (string $item) => trim(html_entity_decode(strip_tags($item))),
            $matches[1] ?? [],
        )));
    }

    /** Just the breadcrumb, so the rest of the page cannot answer for it. */
    private function crumbMarkupOn(string $url): string
    {
        $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

        return (string) str($html)->after('aria-label="Breadcrumb"')->before('</nav>')->value();
    }
}
