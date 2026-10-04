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
                ['route' => 'admin.admissions.*', 'label' => 'Cutoff & decisions', 'icon' => 'scale', 'can' => 'admissions.decide',
                    // Spelled out rather than left as `admin.admissions.*`, because
                    // Printing lives under the same URL and is not the cutoff desk.
                    'matches' => [
                        'admin.admissions.index',
                        'admin.admissions.settings.update',
                        'admin.admissions.compute',
                        'admin.admissions.apply',
                        'admin.admissions.decision.override',
                        'admin.admissions.enrol',
                        'admin.admissions.merit',
                        'admin.admissions.merit.csv',
                        'admin.admissions.letters',
                        'admin.admissions.waiting',
                        'admin.admissions.waiting.promote',
                        'admin.admissions.resit',
                    ]],

                // The paper that leaves the building, gathered in one place. The
                // office asking "where do I print the admission list" should not have
                // to know which desk produces it.
                ['route' => 'admin.admissions.printing', 'label' => 'Printing', 'icon' => 'printer', 'can' => 'admissions.view'],

                // The settings the admission itself runs on — whether the form is
                // open, what it costs, the cutoff and the letter — sit with the work
                // they belong to rather than under Settings, which is where the
                // office went looking for them and did not find them.
                ['route' => 'admin.settings.admissions', 'label' => 'Admissions settings', 'icon' => 'sliders', 'can' => 'settings.manage',
                    'matches' => ['admin.settings.admissions']],
            ],
            'Students & Results' => [
                // The module the office listed for us, in their order. Every one of
                // these is built one at a time; until its turn comes it is a blank
                // page that says so, never a link that errors.
                ['route' => 'admin.students-results.dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'can' => 'results.view'],
                ['route' => 'admin.students-results.check-result', 'label' => 'Check Result', 'icon' => 'search', 'can' => 'results.check'],
                ['route' => 'admin.students-results.performance', 'label' => 'Performance Analytics', 'icon' => 'chart', 'can' => 'results.analytics'],
                ['route' => 'admin.students-results.pins', 'label' => 'Generate Pin', 'icon' => 'key', 'can' => 'results.pins'],
                ['route' => 'admin.students-results.students', 'label' => 'Students Details', 'icon' => 'academic', 'can' => 'students.view',
                    // `matches` reaches past the register itself, because the report
                    // hanging off it has to leave this entry looking open: the sidebar
                    // only draws a second level for the link it calls active.
                    'matches' => ['admin.students-results.students', 'admin.students-results.students.*'],
                    // Spelled out here rather than drawn from a list in the controller,
                    // the way Academic and Teachers do it: those two have a page of their
                    // own that lists their children, and this entry does not — the
                    // register is a register, and a submenu is all it needed. The order
                    // is the office's own, and the module test holds it.
                    'children' => [
                        [
                            'route' => 'admin.students-results.students.add',
                            'label' => 'Add Students',
                            'icon' => 'user-plus',
                            // Taking one child on is a write to a child's record, which is
                            // `students.manage` — the same authority the register asks for
                            // before it takes one off.
                            'can' => 'students.manage',
                        ],
                        [
                            'route' => 'admin.students-results.students.class-section-report',
                            'label' => 'Class & Section Report',
                            'icon' => 'columns',
                        ],
                        [
                            'route' => 'admin.students-results.students.multiple-import',
                            'label' => 'Multiple import',
                            'icon' => 'upload',
                            // Importing is a write, and this one answers to the permission
                            // named for it rather than to the register's: a teacher may
                            // read a class list without being able to create a hundred
                            // children at once.
                            'can' => 'students.import',
                        ],
                    ]],

                // Teachers, and the two pages that hang off it: the register of
                // the teaching staff, and the form for taking another one on.
                // Distinct from Staff & roles, which manages logins rather than
                // people, and which is why this is not called Employees.
                ['route' => 'admin.students-results.teachers', 'label' => 'Teachers', 'icon' => 'briefcase', 'can' => 'teachers.manage',
                    'matches' => ['admin.students-results.teachers', 'admin.students-results.teachers.*'],
                    'children' => StudentsResultsController::TEACHER_PAGES],
                ['route' => 'admin.students-results.academics', 'label' => 'Academic', 'icon' => 'book', 'can' => 'academics.manage',
                    // Academic is a section in its own right: the pages it holds
                    // are drawn underneath it, from the list the controller owns.
                    'matches' => ['admin.students-results.academics', 'admin.students-results.academics.*'],
                    'children' => StudentsResultsController::ACADEMIC_PAGES],
                ['route' => 'admin.students-results.exam-master', 'label' => 'Exam Master', 'icon' => 'clipboard-check', 'can' => 'exams.manage'],
                ['route' => 'admin.students-results.attendance', 'label' => 'Attendance', 'icon' => 'calendar', 'can' => 'attendance.manage'],
                ['route' => 'admin.students-results.reports', 'label' => 'Reports', 'icon' => 'report', 'can' => 'reports.view'],
                ['route' => 'admin.students-results.alumni', 'label' => 'Alumni', 'icon' => 'rosette', 'can' => 'alumni.manage'],
                ['route' => 'admin.students-results.settings', 'label' => 'Settings', 'icon' => 'sliders', 'can' => 'settings.manage'],
            ],
            // Fees and their collection are one banner, because they are one job: what is
            // billed, then the money coming in against it. Split across two headings the
            // office had to know which side a screen was on before looking for it — and
            // nobody should have to work out whether the receipt for what a parent just
            // paid is a Fees page or a Payments page.
            //
            // Under it, the five things the office asked for: the section's own dashboard,
            // the students read by what they owe, the fees themselves, the collection, and
            // what students are let off. Fees and Payments are headings with their pages
            // under them, so the sidebar stays five lines rather than twelve.
            'Fees & Payments' => [
                ['route' => 'admin.fees-payments.dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'can' => 'fees.view',
                    'matches' => ['admin.fees-payments.dashboard']],

                // The fees desk's view of the school: who owes what, by class. Deliberately
                // not the register under Students & Results, which is the academic one —
                // reading a class's fees and reading its names are two different jobs.
                ['route' => 'admin.fees-payments.students-hub', 'label' => 'Students Hub', 'icon' => 'users', 'can' => 'fees.view',
                    'matches' => ['admin.fees-payments.students-hub']],

                // What is billed, and what it is made of. The entry's own page is the
                // bare /fees path, which is blank for now: the fee screens are being
                // rebuilt, and until they are the office asked for this entry to open
                // on nothing.
                //
                // Fee categories and Invoices were the two pages beneath it and have
                // come off the menu, so there is no `children` here any more. Neither
                // page is gone — both still answer at their own address, and the
                // invoice register is still what the dashboard, the receipts and a
                // child's own page link to. They are off the sidebar because the fees
                // screens are being rebuilt, not because they have stopped working.
                //
                // `matches` is spelled out rather than left as `admin.fees.*`, because
                // that would also light this entry up on the Scholarships page, which
                // is its own entry and has nothing to do with the fee desk's list.
                //
                // `owns` keeps the invoices pages ending their trail here. They are
                // this entry's pages but not under its url, and `route` alone cannot
                // say so. A child has to be a plain route name in any case: the sidebar
                // links to it with route(), which a `*` would send looking for a route
                // that does not exist. Only `matches` and `owns` take wildcards.
                ['route' => 'admin.fees.*', 'label' => 'Fees', 'icon' => 'tag', 'can' => 'fees.manage',
                    'matches' => [
                        'admin.fees.index',
                        'admin.fees.categories.*',
                        'admin.fees.structures.*',
                        'admin.invoices.*',
                    ],
                    'owns' => ['admin.invoices.*']],

                // The money coming in: the entry's own page is the register — the day book
                // the bursar works in — and the pages beneath it are the rest of the
                // collection. `matches` is spelled out because the sidebar draws a submenu
                // only for the entry it calls active: every one of these pages has to leave
                // Payments looking open.
                //
                // Gateway, Installments and Bulk Ops were all here and have gone. The
                // first two never had a page — they were screens saying what would be on
                // them, and the office did not ask for either. Bulk Ops was a working
                // page, but the school already has its bulk operations under Students &
                // Results, and fees follow the child: when a student is promoted their
                // history and their money go with them, so there is nothing for a
                // fee-side office to do in bulk.
                //
                // Settlement was the fourth page here and is now an entry of its own,
                // below. It answers a different question from the rest of the collection:
                // these screens are about money the school is owed, and that one is about
                // money that has actually arrived.
                ['route' => 'admin.payments.index', 'label' => 'Payments', 'icon' => 'cash', 'can' => 'fees.view',
                    'matches' => [
                        'admin.payments.index',
                        'admin.payments.reverse',
                        'admin.payments.overview',
                        'admin.payments.schedule',
                        'admin.payments.reports',
                    ],
                    'children' => [
                        ['route' => 'admin.payments.overview', 'label' => 'Overview', 'icon' => 'chart'],
                        ['route' => 'admin.payments.schedule', 'label' => 'Payment Schedule', 'icon' => 'calendar'],
                        ['route' => 'admin.payments.reports', 'label' => 'Reports', 'icon' => 'report'],
                    ]],

                // What Paystack has actually paid out to the school's bank, and when.
                //
                // Its own entry rather than a page under Payments, and `matches` names it
                // alone: nothing here may leave Payments looking open, or the sidebar would
                // show it twice — once under the entry it left and once as itself. The page
                // is still served by PaymentController and still listed in its PAGES; the
                // menu is the only thing that moved.
                ['route' => 'admin.payments.settlements', 'label' => 'Settlement', 'icon' => 'briefcase', 'can' => 'fees.view',
                    'matches' => ['admin.payments.settlements']],

                ['route' => 'admin.fees.scholarships.*', 'label' => 'Scholarships', 'icon' => 'rosette', 'can' => 'fees.manage'],
            ],
            // The school's own money: the accounts fees are paid into. Taken from the
            // fee site's own section, which is where the office expects to find it.
            'Business' => [
                ['route' => 'admin.bank-accounts.*', 'label' => 'Bank Accounts', 'icon' => 'briefcase', 'can' => 'fees.manage'],
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

                // `matches` is spelled out rather than left as `admin.settings.*`,
                // because that would also light this up on the admissions settings,
                // which are no longer settings in the sense this entry means.
                ['route' => 'admin.settings.*', 'label' => 'Settings', 'icon' => 'cog', 'can' => 'settings.manage',
                    'matches' => [
                        'admin.settings.index',
                        'admin.settings.update',
                        'admin.settings.sequences.update',
                        'admin.settings.academic.*',
                        'admin.settings.api',
                    ],
                    'children' => [
                        // The keys the school signs in elsewhere with. Under Settings
                        // rather than in a section of its own: the account belongs to
                        // the school, not to the fees desk or the results desk.
                        ['route' => 'admin.settings.api', 'label' => 'API', 'icon' => 'key', 'can' => 'settings.manage',
                            'matches' => ['admin.settings.api']],
                    ]],

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
        $ownerDepth = 0;

        foreach (self::entries() as $candidate) {
            $depth = self::ownershipDepth($candidate['entry'], $routeName);

            if ($depth === null) {
                continue;
            }

            // Deepest namespace wins: child pages under Academic belong to
            // Academic as well, and it is the page itself we want to end the trail.
            if ($owner === null || $depth > $ownerDepth) {
                $owner = $candidate;
                $ownerDepth = $depth;
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
     * How deeply an entry owns a route, or null if it does not own it at all.
     *
     * An entry owns everything under its own namespace, and anything else it names in
     * `owns`. That second list is for a page that belongs to an entry without sitting
     * under its url — the invoice register is the Fees entry's page, but it answers at
     * admin.invoices rather than admin.fees, and `route` alone cannot say so.
     *
     * The depth returned is the length of the longest namespace claimed, which is what
     * lets the deepest claim win when two entries both cover a page.
     */
    protected static function ownershipDepth(array $entry, string $routeName): ?int
    {
        $depth = null;

        foreach (array_merge([self::namespace($entry)], (array) ($entry['owns'] ?? [])) as $prefix) {
            $prefix = str_replace('.*', '', (string) $prefix);

            if ($routeName === $prefix || str_starts_with($routeName, $prefix.'.')) {
                $depth = max((int) $depth, strlen($prefix));
            }
        }

        return $depth;
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
