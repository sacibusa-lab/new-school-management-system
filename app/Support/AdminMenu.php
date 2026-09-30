<?php

namespace App\Support;

use App\Http\Controllers\Admin\StudentsResultsController;

/**
 * The control panel's menu — and the trail that comes out of it.
 *
 * One definition, two readers: the sidebar draws this, and the breadcrumb above a
 * page is derived from it. That is why it lives here rather than in the layout. A
 * page's place in the platform is decided once, by the entry that owns it, so there
 * is no second list of parents to keep in step, no page can be given a trail that
 * contradicts where its sidebar item sits, and a page added to the menu arrives with
 * its trail already right.
 *
 * A page the menu does not hold gets no trail rather than a wrong one: the old flat
 * /admin/students and /admin/results screens are being retired, and pretending to
 * know where they sit would be worse than saying nothing.
 */
class AdminMenu
{
    /**
     * Where a section heading points, where the section has a front page.
     *
     * Students & Results has one: the module's own dashboard is that section's
     * landing page, so its heading is a way in as well as a label. The rest are
     * groups of pages with nothing between them, and their heading stays a label
     * rather than linking somewhere nobody meant it to.
     *
     * @var array<string,string>
     */
    protected const HEADING_ROUTES = [
        'Students & Results' => 'admin.students-results.dashboard',
    ];

    /**
     * The menu, by section heading, in the order the office asked for.
     *
     * `matches` names the routes an entry lights up for, where that is narrower than
     * its own namespace — Register applicant must not also light up Applicants. The
     * trail ignores `matches` and reads the route names, so highlighting and place
     * cannot drag each other around.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function sections(): array
    {
        return [
            'Overview' => [
                ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'can' => null],
            ],
            'Admissions' => [
                ['route' => 'admin.applicants.*', 'label' => 'Applicants', 'icon' => 'users', 'can' => 'admissions.view',
                    'matches' => ['admin.applicants.index', 'admin.applicants.show', 'admin.applicants.edit']],

                ['route' => 'admin.applicants.create', 'label' => 'Register applicant', 'icon' => 'user-plus', 'can' => 'admissions.create',
                    'matches' => ['admin.applicants.create', 'admin.applicants.import', 'admin.applicants.import.*']],

                ['route' => 'admin.exams.*', 'label' => 'Examinations', 'icon' => 'clipboard', 'can' => 'exams.view'],
                ['route' => 'admin.scores.*', 'label' => 'Score entry', 'icon' => 'pencil', 'can' => 'scores.enter'],
                ['route' => 'admin.imports.*', 'label' => 'Scoresheet imports', 'icon' => 'upload', 'can' => 'scores.import'],
                ['route' => 'admin.admissions.*', 'label' => 'Cutoff & decisions', 'icon' => 'scale', 'can' => 'admissions.decide'],
            ],
            'Students & Results' => [
                // The module the office listed for us, in their order. Every one of
                // these is built one at a time; until its turn comes it is a blank
                // page that says so, never a link that errors.
                ['route' => 'admin.students-results.dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'can' => 'results.view'],
                ['route' => 'admin.students-results.check-result', 'label' => 'Check Result', 'icon' => 'search', 'can' => 'results.check'],
                ['route' => 'admin.students-results.performance', 'label' => 'Performance Analytics', 'icon' => 'chart', 'can' => 'results.analytics'],
                ['route' => 'admin.students-results.pins', 'label' => 'Generate Pin', 'icon' => 'key', 'can' => 'results.pins'],
                ['route' => 'admin.students-results.students', 'label' => 'Students Details', 'icon' => 'academic', 'can' => 'students.view'],
                ['route' => 'admin.students-results.employees', 'label' => 'Employee', 'icon' => 'briefcase', 'can' => 'employees.manage'],
                ['route' => 'admin.students-results.academics', 'label' => 'Academic', 'icon' => 'book', 'can' => 'academics.manage',
                    // Academic is a section in its own right: the four pages it holds
                    // are drawn underneath it, from the list the controller owns.
                    'matches' => ['admin.students-results.academics', 'admin.students-results.academics.*'],
                    'children' => StudentsResultsController::ACADEMIC_PAGES],
                ['route' => 'admin.students-results.exam-master', 'label' => 'Exam Master', 'icon' => 'clipboard-check', 'can' => 'exams.manage'],
                ['route' => 'admin.students-results.attendance', 'label' => 'Attendance', 'icon' => 'calendar', 'can' => 'attendance.manage'],
                ['route' => 'admin.students-results.reports', 'label' => 'Reports', 'icon' => 'report', 'can' => 'reports.view'],
                ['route' => 'admin.students-results.alumni', 'label' => 'Alumni', 'icon' => 'rosette', 'can' => 'alumni.manage'],
                ['route' => 'admin.students-results.settings', 'label' => 'Settings', 'icon' => 'sliders', 'can' => 'settings.manage'],
            ],
            'Fees' => [
                ['route' => 'admin.fees.categories.*', 'label' => 'Fee categories', 'icon' => 'tag', 'can' => 'fees.manage'],
                ['route' => 'admin.fees.structures.*', 'label' => 'Fee structures', 'icon' => 'list', 'can' => 'fees.manage'],
                ['route' => 'admin.invoices.*', 'label' => 'Invoices', 'icon' => 'receipt', 'can' => 'fees.view'],
                ['route' => 'admin.payments.*', 'label' => 'Payments', 'icon' => 'cash', 'can' => 'fees.view'],
            ],
            'Communication' => [
                ['route' => 'admin.sms.center', 'label' => 'SMS center', 'icon' => 'chat', 'can' => 'sms.view',
                    'matches' => ['admin.sms.center']],

                // `matches` is spelled out because admin.sms.* would also light this
                // up on the SMS centre screen.
                ['route' => 'admin.sms.*', 'label' => 'Text messages', 'icon' => 'envelope', 'can' => 'sms.view',
                    'matches' => ['admin.sms.index', 'admin.sms.batch', 'admin.sms.batch.store', 'admin.sms.templates', 'admin.sms.templates.*']],
            ],
            'Administration' => [
                ['route' => 'admin.users.*', 'label' => 'Staff & roles', 'icon' => 'shield', 'can' => 'users.manage'],
                ['route' => 'admin.settings.*', 'label' => 'Settings', 'icon' => 'cog', 'can' => 'settings.manage'],
                ['route' => 'admin.activity.*', 'label' => 'Activity log', 'icon' => 'clock', 'can' => 'audit.view'],
            ],
        ];
    }

    /**
     * Where an entry points.
     *
     * A `*` route becomes its .index URL; a plain name is used as-is, so an entry
     * can point at a create screen.
     */
    public static function href(array $link): string
    {
        return route(self::target($link));
    }

    /** The route an entry actually lands on: `admin.applicants.*` means its index. */
    public static function target(array $link): string
    {
        return str_replace('.*', '.index', $link['route']);
    }

    /**
     * The routes an entry owns — everything under its own namespace.
     *
     * `admin.sms.*` owns admin.sms.index, admin.sms.batch and the rest, so a screen
     * nobody listed individually still belongs somewhere.
     */
    public static function namespace(array $link): string
    {
        return str_replace('.*', '', $link['route']);
    }

    /** Is this entry the page being looked at? */
    public static function isActive(array $link): bool
    {
        return request()->routeIs(...(array) ($link['matches'] ?? [$link['route']]));
    }

    /**
     * Where the page being looked at sits, outermost first.
     *
     * The last crumb is the page itself and carries no route, because a link to the
     * page you are on is a way out that leads nowhere. A page under an entry — a
     * detail screen, or one of the pages under Academic — keeps the entry as a link
     * and adds its own title as the last crumb, which is why a report card reads
     * "Admissions › Applicants › Chidera Okafor" without anybody saying so.
     *
     * @return array<int,array{label:string,route:?string}>
     */
    public static function trailFor(?string $routeName, ?string $pageTitle = null): array
    {
        $routeName = (string) $routeName;

        if ($routeName === '') {
            return [];
        }

        $owner = null;

        foreach (self::entries() as $candidate) {
            $namespace = self::namespace($candidate['entry']);

            if ($routeName !== $namespace && ! str_starts_with($routeName, $namespace.'.')) {
                continue;
            }

            // Deepest namespace wins: the four pages under Academic belong to
            // Academic as well, and it is the page itself we want to end the trail.
            if ($owner === null || strlen($namespace) > strlen(self::namespace($owner['entry']))) {
                $owner = $candidate;
            }
        }

        if ($owner === null) {
            return [];
        }

        $entry = $owner['entry'];
        $trail = [['label' => $owner['heading'], 'route' => self::HEADING_ROUTES[$owner['heading']] ?? null]];

        if ($owner['parent'] !== null) {
            $trail[] = ['label' => $owner['parent']['label'], 'route' => self::target($owner['parent'])];
        }

        if ($routeName === self::target($entry)) {
            // The entry is the page, so there is nothing deeper to name.
            $trail[] = ['label' => $entry['label'], 'route' => null];

            return $trail;
        }

        $trail[] = ['label' => $entry['label'], 'route' => self::target($entry)];

        // A page that sets no title of its own ends at its entry rather than at a
        // second crumb saying the same thing.
        $title = trim((string) $pageTitle);

        if ($title !== '' && $title !== $entry['label']) {
            $trail[] = ['label' => $title, 'route' => null];
        }

        return $trail;
    }

    /**
     * Every entry, flattened, with the heading it sits under and its parent.
     *
     * @return array<int,array{heading:string,parent:?array<string,mixed>,entry:array<string,mixed>}>
     */
    protected static function entries(): array
    {
        $flat = [];

        foreach (self::sections() as $heading => $links) {
            foreach ($links as $link) {
                $flat[] = ['heading' => $heading, 'parent' => null, 'entry' => $link];

                foreach ($link['children'] ?? [] as $child) {
                    $flat[] = ['heading' => $heading, 'parent' => $link, 'entry' => $child];
                }
            }
        }

        return $flat;
    }
}
