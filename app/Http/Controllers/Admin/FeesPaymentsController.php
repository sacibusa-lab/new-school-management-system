<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * The two screens the Fees & Payments banner owns itself.
 *
 * Every other page in that section belongs to the fees desk or the collection desk —
 * it is about fee structures, or bills, or money coming in. These two are about the
 * section as a whole, so they have no controller to live in: a dashboard that
 * answers "how is the money doing", and the student hub, where a class's fees are
 * read at a glance rather than a child's.
 *
 * Both are drawn the same way the Students & Results module's own pages are: a page
 * that says what will be on it, never a link that errors.
 */
class FeesPaymentsController extends Controller
{
    public function dashboard(): View
    {
        return $this->placeholder('dashboard');
    }

    public function studentsHub(): View
    {
        return $this->placeholder('students-hub');
    }

    /**
     * Looked up rather than trusted from the URL, so the label and the permission
     * come from this file and never from the browser.
     */
    protected function placeholder(string $key): View
    {
        $page = collect(self::PAGES)->firstWhere('key', $key);

        abort_if($page === null, 404);

        $this->authorize($page['permission']);

        return view('admin.fees-payments.'.$key, ['page' => $page]);
    }

    /**
     * The section's own pages. Read by the test that keeps the menu and these in step.
     *
     * @var array<int,array{key:string,label:string,icon:string,permission:string}>
     */
    public const PAGES = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'permission' => 'fees.view'],
        ['key' => 'students-hub', 'label' => 'Students Hub', 'icon' => 'users', 'permission' => 'fees.view'],
    ];
}
