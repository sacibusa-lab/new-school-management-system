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
        ['key' => 'employees', 'label' => 'Employee', 'icon' => 'briefcase', 'permission' => 'employees.manage'],
        ['key' => 'academics', 'label' => 'Academic', 'icon' => 'book', 'permission' => 'academics.manage'],
        ['key' => 'exam-master', 'label' => 'Exam Master', 'icon' => 'clipboard-check', 'permission' => 'exams.manage'],
        ['key' => 'attendance', 'label' => 'Attendance', 'icon' => 'calendar', 'permission' => 'attendance.manage'],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'report', 'permission' => 'reports.view'],
        ['key' => 'alumni', 'label' => 'Alumni', 'icon' => 'rosette', 'permission' => 'alumni.manage'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'sliders', 'permission' => 'settings.manage'],
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

    public function employees(): View
    {
        return $this->placeholder('employees');
    }

    public function academics(): View
    {
        return $this->placeholder('academics');
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
     */
    protected function placeholder(string $key): View
    {
        $page = collect(self::PAGES)->firstWhere('key', $key);

        abort_if($page === null, 404);

        $this->authorize($page['permission']);

        return view('admin.students-results.' . $key, ['page' => $page]);
    }
}
