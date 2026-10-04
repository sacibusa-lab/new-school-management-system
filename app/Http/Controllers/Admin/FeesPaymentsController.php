<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
use App\Models\Student;
use App\Services\Payment\VirtualAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /**
     * How the money is doing.
     *
     * Two questions, and the page is built around the difference between them. The
     * headline is the session so far — billed, collected, outstanding — which is the
     * position rather than the news. Everything below it is the news: what came in
     * today, where the gaps are class by class, how the money arrives, and what the
     * parents are actually paying for.
     *
     * It is not a second copy of the fees section on the main dashboard. That one is a
     * school-wide overview where the fees get four cards; this is the collection desk's
     * own front page, and it goes a level deeper than four cards can.
     */
    public function dashboard(): View
    {
        $this->authorize('fees.view');

        $session = AcademicSession::current();

        $billed = (float) $this->billsFor(Invoice::query(), $session)->sum('total');
        $collected = (float) $this->billsFor(Invoice::query(), $session)->sum('amount_paid');

        return view('admin.fees-payments.dashboard', [
            'session' => $session,

            // The position, which is the session so far.
            'totals' => [
                'billed' => $billed,
                'collected' => $collected,
                'outstanding' => round(max($billed - $collected, 0), 2),
                'rate' => $billed > 0 ? round(($collected / $billed) * 100, 1) : 0.0,
                'bills' => $this->billsFor(Invoice::query(), $session)->count(),
                'unpaid' => $this->billsFor(Invoice::query(), $session)->whereIn('status', [
                    InvoiceStatus::Unpaid->value,
                    InvoiceStatus::Partial->value,
                    InvoiceStatus::Overdue->value,
                ])->count(),
            ],

            // The news.
            'today' => $this->todayTotals($session),
            'perClass' => $this->perClassTotals($session),
            'methods' => $this->methodTotals($session),
            'monthly' => $this->monthlyTotals(),

            'recent' => Payment::query()
                ->with(['student', 'invoice'])
                ->where('status', PaymentStatus::Successful->value)
                ->latest('paid_at')
                ->limit(10)
                ->get(),

            // Not a fee figure, but a collection rate means nothing without knowing how
            // many families are behind it.
            'roll' => Student::query()->where('status', StudentStatus::Active->value)->count(),
        ]);
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

    /* ------------------------------------------------------------------ */
    /* The figures behind the dashboard */
    /* ------------------------------------------------------------------ */

    /**
     * Money in today, and bills raised today.
     *
     * Scoped to the session's own bills, like every other figure on the page. A payment
     * taken today against last session's bill is not this session's collection, and
     * adding the two together is how a cash book stops agreeing with the accounts.
     *
     * @return array<string,float|int>
     */
    protected function todayTotals(?AcademicSession $session): array
    {
        $today = Carbon::today();

        $received = $this->sessionPayments($session)->whereDate('paid_at', $today);
        $raised = $this->billsFor(Invoice::query(), $session)->whereDate('issued_at', $today);

        return [
            'collected' => (float) (clone $received)->sum('amount'),
            'payments' => (clone $received)->count(),
            'raised' => (float) (clone $raised)->sum('total'),
            'bills' => (clone $raised)->count(),
            'week' => (float) $this->sessionPayments($session)
                ->where('paid_at', '>=', $today->copy()->startOfWeek())
                ->sum('amount'),
        ];
    }

    /**
     * The payments that count as this session's money.
     *
     * A payment is this session's if it settled one of this session's bills. A receipt
     * with no bill behind it — an overpayment held as a credit — is left out on
     * purpose: it is money the school is holding, not income against anything yet.
     *
     * @return Builder<Payment>
     */
    protected function sessionPayments(?AcademicSession $session): Builder
    {
        return Payment::query()
            ->where('status', PaymentStatus::Successful->value)
            ->whereHas('invoice', fn (Builder $invoice) => $this->billsFor($invoice, $session));
    }

    /**
     * What each class has been billed, has paid, and still owes.
     *
     * Sorted by what is outstanding rather than by name, because a list of twenty-three
     * classes in alphabetical order is a list nobody acts on. Grouped in the database
     * rather than counted class by class: twenty-three classes would otherwise be
     * forty-six reads.
     *
     * @return Collection<int,array<string,mixed>>
     */
    protected function perClassTotals(?AcademicSession $session): Collection
    {
        // Qualified throughout: `students` carries a `status` of its own, so an
        // unqualified one becomes ambiguous the moment the two tables are joined.
        $rows = Invoice::query()
            ->when($session, fn (Builder $q) => $q->where('invoices.academic_session_id', $session->id))
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->join('students', 'students.id', '=', 'invoices.student_id')
            ->leftJoin('school_classes', 'school_classes.id', '=', 'students.school_class_id')
            ->groupBy('school_classes.id', 'school_classes.name')
            ->get([
                DB::raw('school_classes.name as class_name'),
                DB::raw('count(distinct invoices.student_id) as children'),
                DB::raw('coalesce(sum(invoices.total), 0) as billed'),
                DB::raw('coalesce(sum(invoices.amount_paid), 0) as collected'),
            ]);

        return $rows
            ->map(fn ($row): array => $this->withOutstanding([
                'name' => $row->class_name ?? 'No class yet',
                'children' => (int) $row->children,
                'billed' => (float) $row->billed,
                'collected' => (float) $row->collected,
            ]))
            ->sortByDesc('outstanding')
            ->values();
    }

    /**
     * How the money arrived.
     *
     * The gateway against a hand-written receipt is the figure the office is actually
     * curious about: it is the difference between a parent paying from home at midnight
     * and a parent queueing at the bursary window.
     *
     * @return Collection<int,array<string,mixed>>
     */
    protected function methodTotals(?AcademicSession $session): Collection
    {
        $rows = $this->sessionPayments($session)
            ->groupBy('method')
            ->get([
                DB::raw('method'),
                DB::raw('sum(amount) as total'),
                DB::raw('count(*) as payments'),
            ])
            ->sortByDesc('total')
            ->values();

        $sum = (float) $rows->sum('total');

        return $rows->map(fn ($row): array => [
            'label' => Payment::labelFor($row->method),
            'total' => (float) $row->total,
            'payments' => (int) $row->payments,
            'share' => $sum > 0 ? round(((float) $row->total / $sum) * 100, 1) : 0.0,
        ]);
    }

    /**
     * The last twelve months of money received.
     *
     * Not scoped to the session, unlike everything above it, and deliberately: money
     * arrives in the shape of the school year. A September spike means nothing until it
     * is seen against the quiet months either side of it, and a chart that can only see
     * one session can never show that shape.
     *
     * @return Collection<int,array{key:string,label:string,total:float}>
     */
    protected function monthlyTotals(): Collection
    {
        $start = Carbon::today()->startOfMonth()->subMonths(11);

        $sums = Payment::query()
            ->where('status', PaymentStatus::Successful->value)
            ->where('paid_at', '>=', $start)
            ->groupBy('ym')
            ->get([
                DB::raw("date_format(paid_at, '%Y-%m') as ym"),
                DB::raw('sum(amount) as total'),
            ])
            ->pluck('total', 'ym');

        // Filled in month by month rather than taken as it comes. A month nothing came
        // in is a real column a real height of zero; dropping it would close the gap and
        // draw a trend that says the opposite of what happened.
        return collect(range(0, 11))->map(function (int $back) use ($start, $sums): array {
            $month = $start->copy()->addMonths($back);

            return [
                'key' => $month->format('Y-m'),
                'label' => $month->format('M'),
                'total' => (float) ($sums[$month->format('Y-m')] ?? 0),
            ];
        });
    }

    /**
     * Fill in what is still owed on a row, and how far along it is. Kept in one place so
     * a class, and anything added later, is read the same way.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    protected function withOutstanding(array $row): array
    {
        $billed = (float) $row['billed'];
        $collected = (float) $row['collected'];

        return $row + [
            'outstanding' => round(max($billed - $collected, 0), 2),
            'rate' => $billed > 0 ? round(($collected / $billed) * 100, 1) : 0.0,
        ];
    }

    /**
     * The pages this section owns, for the test that keeps the menu and them in step.
     *
     * Both are real screens now. Neither is still one of the pages that says what will
     * be on it.
     *
     * @var array<int,array{key:string,label:string,icon:string,permission:string}>
     */
    public const PAGES = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'grid', 'permission' => 'fees.view'],
        ['key' => 'students-hub', 'label' => 'Students Hub', 'icon' => 'users', 'permission' => 'fees.view'],
    ];
}
