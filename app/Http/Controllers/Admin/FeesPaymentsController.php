<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Services\Payment\VirtualAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * The two screens the Fees & Payments banner owns itself.
 *
 * Every other page in that section belongs to the fees desk or the collection desk —
 * it is about fee structures, or bills, or money coming in. These two are about the
 * section as a whole: a dashboard that answers "how is the money doing", and the
 * students hub, where the roll is read one child at a time.
 */
class FeesPaymentsController extends Controller
{
    /** Lines to a page. A form class or two at a time, as the register does it. */
    private const PER_PAGE = 25;

    public function dashboard(): View
    {
        return $this->placeholder('dashboard');
    }

    /**
     * The roll, read one child at a time.
     *
     * Every child gets a line, and each line carries the two things a parent rings up
     * about: the account number their money goes into, and how far the fees have got.
     * The register under Students & Results holds the same children from the other
     * end — guardian, photograph, class teacher. The two share a table and nothing
     * else; this one is about the money.
     *
     * The filter is the child's standing on the roll, not their payment history.
     * "Everyone who has paid" is a list whose shape the office already knows;
     * "the active roll, and how each one is doing" is the question actually asked
     * across the counter. How far the fees have got is answered per child, by the
     * pill in the row.
     */
    public function studentsHub(Request $request): View
    {
        $this->authorize('fees.view');

        $session = AcademicSession::current();

        $filters = [
            'class' => $request->integer('class'),
            'section' => $request->integer('section'),
            // The roll opens on the children who are on it. An empty value is the
            // office asking for everybody, which is a thing they are allowed to want.
            'status' => $request->has('status')
                ? (string) $request->string('status')
                : StudentStatus::Active->value,
            'q' => trim((string) $request->string('q')),
        ];

        return view('admin.fees-payments.students-hub', [
            'students' => $this->register($filters, $session),
            'levels' => SchoolLevel::query()->active()->orderBy('order')->orderBy('name')->get(),
            'sections' => Section::query()->orderBy('order')->orderBy('name')->get(),
            'statuses' => StudentStatus::options(),
            'filters' => $filters,
            'currency' => Setting::get('currency_symbol', '₦'),
            'session' => $session,
            'paystackReady' => app(VirtualAccountService::class)->isConfigured(),
        ]);
    }

    /**
     * One page of the roll, with each child's bills summed on the way out.
     *
     * Billed and paid are summed by the database rather than counted a row at a time,
     * so a page of twenty-five costs two reads rather than fifty, and the payment
     * standing beside each name is worked out from those two figures.
     *
     * The search reaches into both numbers a child can be known by: the admission
     * number they are given here, and the registration number they applied with. The
     * office has a parent on the telephone holding one or the other.
     *
     * @param  array{class:int,section:int,status:string,q:string}  $filters
     * @return LengthAwarePaginator<int,Student>
     */
    protected function register(array $filters, ?AcademicSession $session): LengthAwarePaginator
    {
        $search = $filters['q'];

        return Student::query()
            ->with(['schoolClass.section', 'level', 'virtualAccount'])
            ->withSum(['invoices as billed_total' => fn ($q) => $this->billsFor($q, $session)], 'total')
            ->withSum(['invoices as paid_total' => fn ($q) => $this->billsFor($q, $session)], 'amount_paid')
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['class'] !== 0, fn ($q) => $q->where('level_id', $filters['class']))
            // The section lives on the class rather than on the child, so this asks the
            // class they are in rather than the student row itself.
            ->when($filters['section'] !== 0, fn ($q) => $q->whereHas(
                'schoolClass',
                fn ($classes) => $classes->where('section_id', $filters['section']),
            ))
            ->when($search !== '', fn ($q) => $q->where(
                fn ($inner) => $inner
                    ->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('student_number', 'like', "%{$search}%")
                    ->orWhere('admission_number', 'like', "%{$search}%")
            ))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
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
