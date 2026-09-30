<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * The Students & Results module.
 *
 * Scaffolding, deliberately: every page below exists, is reachable from the
 * sidebar and enforces a permission, but draws only a placeholder. The pages are
 * built one at a time, and each one graduates out of this file into a controller
 * of its own as it is designed — so this class is a menu that happens to render,
 * not a home for twelve unrelated features.
 *
 * Check Result is the first to graduate: it is {@see CheckResultController} now.
 * Its entry below stays, because the entry is the menu — the sidebar, the label and
 * the permission all come from here — and only the page behind it moved.
 *
 * The permissions are named for what each page will DO rather than what it is
 * called, because the menu label is the least durable thing about it: "Check
 * Result" has already been renamed once on the legacy system.
 *
 * Route names follow `admin.students-results.*`; the URLs sit under
 * /admin/students-results so that this module cannot be confused with the
 * existing flat routes (/admin/students, /admin/results) while both exist.
 */
class StudentsResultsController extends Controller
{
    /** Where each page lives, in the order the office asked for them. */
    public const PAGES = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'permission' => 'results.view'],
        ['key' => 'check-result', 'label' => 'Check Result', 'icon' => 'search', 'permission' => 'results.check'],
        ['key' => 'performance', 'label' => 'Performance Analytics', 'icon' => 'chart', 'permission' => 'results.analytics'],
        ['key' => 'pins', 'label' => 'Generate Pin', 'icon' => 'key', 'permission' => 'results.pins'],
        ['key' => 'students', 'label' => 'Students Details', 'icon' => 'academic', 'permission' => 'students.view'],
        ['key' => 'teachers', 'label' => 'Teachers', 'icon' => 'briefcase', 'permission' => 'teachers.manage'],
        ['key' => 'academics', 'label' => 'Academic', 'icon' => 'book', 'permission' => 'academics.manage'],
        ['key' => 'exam-master', 'label' => 'Exam Master', 'icon' => 'clipboard-check', 'permission' => 'exams.manage'],
        ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'calendar', 'permission' => 'attendance.manage'],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'report', 'permission' => 'reports.view'],
        ['key' => 'alumni', 'label' => 'Alumni', 'icon' => 'rosette', 'permission' => 'alumni.manage'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'sliders', 'permission' => 'settings.manage'],
    ];

    /**
     * The pages that hang off Academic, in the order the office listed them.
     *
     * Kept beside PAGES rather than inside it because they are a second level: the
     * sidebar draws them underneath Academic, and the Academic page draws them as
     * the four things it is made of. One list, so the two cannot drift apart — an
     * item added here appears in both places.
     *
     * All four answer to the permission of the page they hang off. They are one
     * subject — how the school is organised — and splitting that into four
     * permissions would make a menu nobody could be given half of.
     *
     * @var array<int,array{key:string,route:string,label:string,icon:string,note:string}>
     */
    public const ACADEMIC_PAGES = [
        [
            'key' => 'classes',
            'route' => 'admin.students-results.academics.classes',
            'label' => 'Classes & Sections',
            'icon' => 'grid',
            'note' => 'The classes the school runs — JSS1A, JSS1B, SS2 Science — with the class teacher in each and the space a class holds.',
        ],
        [
            'key' => 'subjects',
            'route' => 'admin.students-results.academics.subjects',
            'label' => 'Subjects',
            'icon' => 'list',
            'note' => 'Every subject the school teaches and the code each one is known by, so a mark entered once can be found again on the report card.',
        ],
        [
            'key' => 'schedule',
            'route' => 'admin.students-results.academics.schedule',
            'label' => 'Class schedule',
            'icon' => 'calendar',
            'note' => 'Which subject a class sits, on which day and at which period, and the teacher taking it — the timetable the term runs on.',
        ],
        [
            'key' => 'promotion',
            'route' => 'admin.students-results.academics.promotion',
            'label' => 'Promotion',
            'icon' => 'refresh',
            'note' => 'Moving a class on at the end of a session: who goes up, who repeats the year, and who has left the school.',
        ],
    ];

    /**
     * The two pages that hang off Teachers, in the order the office listed them.
     *
     * Kept beside PAGES for the same reason as ACADEMIC_PAGES: the sidebar draws
     * them underneath Teachers, and the Teachers page draws them as the two things
     * it is made of.
     *
     * Both answer to the permission of the page they hang off. A teacher is one
     * subject — who teaches here — and splitting the register from the form that
     * fills it would make a permission nobody could be given half of.
     *
     * @var array<int,array{key:string,route:string,label:string,icon:string,note:string}>
     */
    public const TEACHER_PAGES = [
        [
            'key' => 'teachers-list',
            'route' => 'admin.students-results.teachers.list',
            'label' => 'Teachers List',
            'icon' => 'list',
            'note' => 'Every teacher on the staff, with the classes each one is class teacher of and the account they sign in with.',
        ],
        [
            'key' => 'teachers-create',
            'route' => 'admin.students-results.teachers.create',
            'label' => 'Add Teachers',
            'icon' => 'user-plus',
            'note' => 'Take a teacher on: their name, how to reach them, and the login they will use. They change the password the first time they sign in.',
        ],
    ];

    public function dashboard(): View
    {
        return $this->placeholder('dashboard');
    }

    public function performance(): View
    {
        return $this->placeholder('performance');
    }

    public function pins(): View
    {
        return $this->placeholder('pins');
    }

    public function students(): View
    {
        return $this->placeholder('students');
    }

    /**
     * Teachers, and the two pages that hang off it.
     *
     * A section rather than a screen, for the same reason as Academic: the office
     * asked for a Teachers entry with a list and a form under it, and a section
     * whose own page is one of its two children is a menu that repeats itself.
     * The two are drawn from the list below, so the sidebar and this page cannot
     * drift apart.
     */
    public function teachers(): View
    {
        return $this->placeholder('teachers', ['children' => self::TEACHER_PAGES]);
    }

    /**
     * Academic, and the four pages that hang off it.
     *
     * The page itself is a list of its own four children rather than a placeholder;
     * a page with nothing on it but the word "Academic" tells the office nothing
     * about what Academic holds.
     */
    public function academics(): View
    {
        return $this->placeholder('academics', ['children' => self::ACADEMIC_PAGES]);
    }

    public function academicClasses(): View
    {
        return $this->academicPage('classes');
    }

    public function academicSubjects(): View
    {
        return $this->academicPage('subjects');
    }

    public function academicSchedule(): View
    {
        return $this->academicPage('schedule');
    }

    public function academicPromotion(): View
    {
        return $this->academicPage('promotion');
    }

    public function examMaster(): View
    {
        return $this->placeholder('exam-master');
    }

    public function attendance(): View
    {
        return $this->placeholder('attendance');
    }

    public function reports(): View
    {
        return $this->placeholder('reports');
    }

    public function alumni(): View
    {
        return $this->placeholder('alumni');
    }

    public function settings(): View
    {
        return $this->placeholder('settings');
    }

    /**
     * Draw one page of the menu.
     *
     * The page is looked up in PAGES rather than trusted from the URL, so the
     * label and the permission always come from this file and never from the
     * browser.
     *
     * @param  array<string,mixed>  $extra  Anything the page's own view needs.
     */
    protected function placeholder(string $key, array $extra = []): View
    {
        $page = collect(self::PAGES)->firstWhere('key', $key);

        abort_if($page === null, 404);

        $this->authorize($page['permission']);

        return view('admin.students-results.'.$key, $extra + ['page' => $page]);
    }

    /**
     * Draw a page that hangs off Academic.
     *
     * Looked up in ACADEMIC_PAGES for the same reason: the label, the note and the
     * permission are decided here, never by what the URL says. The permission is
     * the parent's — see ACADEMIC_PAGES.
     */
    protected function academicPage(string $key): View
    {
        $page = collect(self::ACADEMIC_PAGES)->firstWhere('key', $key);

        abort_if($page === null, 404);

        $this->authorize('academics.manage');

        return view('admin.students-results.academics.'.$key, ['page' => $page]);
    }
}
