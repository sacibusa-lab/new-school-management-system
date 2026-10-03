<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The two screens the Fees & Payments banner owns itself.
 *
 * Every other page in that section belongs to the fees desk or the collection desk —
 * it is about fee structures, or bills, or money coming in. These two are about the
 * section as a whole: a dashboard that answers "how is the money doing", and the
 * students hub, where the school is read by what it owes.
 */
class FeesPaymentsController extends Controller
{
    public function dashboard(): View
    {
        return $this->placeholder('dashboard');
    }

    /**
     * The school read by what it owes, and by whom.
     *
     * Two questions, answered in that order. First: how far has each class got with
     * its bills? That is a question about thirty rows, so it is answered without
     * anything being chosen — the office should not have to guess which class to look
     * at before they can see which class is behind.
     *
     * Then: who, inside one class? That is a question about names, and it is only
     * worth asking about one class at a time.
     *
     * Deliberately not the register under Students & Results, which reads the roll:
     * guardian, photograph, class teacher, and the same children in a different order.
     * The two share a table and nothing else — one is about the children, this is
     * about the money.
     */
    public function studentsHub(Request $request): View
    {
        $this->authorize('fees.view');

        $session = AcademicSession::current();

        $level = $request->filled('level') ? SchoolLevel::find($request->integer('level')) : null;
        $class = $request->filled('class') ? SchoolClass::find($request->integer('class')) : null;

        $perClass = $this->perClass($session, $level);

        return view('admin.fees-payments.students-hub', [
            'levels' => SchoolLevel::query()->active()->orderBy('order')->get(),
            'classes' => SchoolClass::query()
                ->where('is_active', true)
                ->with('level')
                ->when($level, fn ($q) => $q->where('level_id', $level->id))
                ->orderBy('name')
                ->get(),
            'level' => $level,
            'class' => $class,
            // Only read once a class or a year group has been named: a list of every
            // child's bills is a list nobody reads, and the question the office asks is
            // "who in JSS2A", not "who".
            'students' => ($class !== null || $level !== null)
                ? $this->students($class, $level, $session)
                : collect(),
            'perClass' => $perClass,
            'totals' => [
                'students' => (int) $perClass->sum('students_count'),
                'unbilled' => (int) $perClass->sum('unbilled_count'),
                'billed' => (float) $perClass->sum('billed'),
                'collected' => (float) $perClass->sum('collected'),
                'outstanding' => (float) $perClass->sum('outstanding'),
            ],
            'currency' => Setting::get('currency_symbol', '₦'),
            'session' => $session,
        ]);
    }

    /**
     * What each class has been billed and has paid, for one session.
     *
     * A left join, not an inner one: a class whose children have not been billed
     * belongs on this page — that is exactly the class the office is looking for.
     * `unbilled_count` is therefore the children with no invoice at all, which is a
     * different thing from a child who owes the lot.
     *
     * @return Collection<int,object>
     */
    protected function perClass(?AcademicSession $session, ?SchoolLevel $level): Collection
    {
        return DB::table('students')
            ->leftJoin('invoices', function ($join) use ($session): void {
                $join->on('invoices.student_id', '=', 'students.id')
                    ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value);

                if ($session !== null) {
                    $join->where('invoices.academic_session_id', '=', $session->id);
                }
            })
            ->where('students.status', StudentStatus::Active->value)
            ->when($level, fn ($q) => $q->where('students.level_id', $level->id))
            ->groupBy('students.school_class_id')
            ->selectRaw('students.school_class_id as class_id')
            ->selectRaw('COUNT(DISTINCT students.id) as students_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN invoices.id IS NULL THEN students.id END) as unbilled_count')
            ->selectRaw('COALESCE(SUM(invoices.total), 0) as billed')
            ->selectRaw('COALESCE(SUM(invoices.amount_paid), 0) as collected')
            ->selectRaw('COALESCE(SUM(invoices.balance), 0) as outstanding')
            ->get()
            ->keyBy('class_id');
    }

    /**
     * One class's children, or one year group's, with their bills summed.
     *
     * Summed in the query rather than counted a row at a time: a class of forty is
     * forty invoices and three sums, and reading them child by child is what makes a
     * page like this slow enough that nobody opens it.
     *
     * @return Collection<int,Student>
     */
    protected function students(?SchoolClass $class, ?SchoolLevel $level, ?AcademicSession $session): Collection
    {
        return Student::query()
            ->where('status', StudentStatus::Active->value)
            ->when($class, fn (Builder $q) => $q->where('school_class_id', $class->id))
            ->when($class === null && $level, fn (Builder $q) => $q->where('level_id', $level->id))
            ->with(['schoolClass', 'virtualAccount'])
            ->withSum(['invoices as billed_total' => fn ($q) => $this->billsFor($q, $session)], 'total')
            ->withSum(['invoices as paid_total' => fn ($q) => $this->billsFor($q, $session)], 'amount_paid')
            ->withSum(['invoices as balance_total' => fn ($q) => $this->billsFor($q, $session)], 'balance')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * The bills a figure is about: this session's, and not the cancelled ones.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    protected function billsFor(Builder $query, ?AcademicSession $session): Builder
    {
        return $query
            ->when($session, fn (Builder $q) => $q->where('academic_session_id', $session->id))
            ->where('status', '!=', InvoiceStatus::Cancelled->value);
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
     * The pages this section owns, for the test that keeps the menu and them in step.
     *
     * The dashboard is still one of the screens that says what will be on it and is
     * drawn by placeholder() below. The students hub was too, and is not any more.
     *
     * @var array<int,array{key:string,label:string,icon:string,permission:string}>
     */
    public const PAGES = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'permission' => 'fees.view'],
        ['key' => 'students-hub', 'label' => 'Students Hub', 'icon' => 'users', 'permission' => 'fees.view'],
    ];
}
