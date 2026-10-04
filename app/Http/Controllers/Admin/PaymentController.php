<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Fee;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Services\NumberSequenceService;
use App\Services\Sms\SmsNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentController extends Controller
{
    public function __construct(
        private readonly NumberSequenceService $sequences,
        private readonly SmsNotifier $sms,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('fees.view');

        return view('admin.payments.index', [
            'payments' => Payment::query()
                ->with(['student.level', 'invoice', 'recorder'])
                ->when($request->filled('q'), function ($q) use ($request) {
                    $term = '%'.trim($request->string('q')->toString()).'%';

                    $q->where(function ($inner) use ($term) {
                        $inner->where('receipt_number', 'like', $term)
                            ->orWhere('reference', 'like', $term)
                            ->orWhereHas('student', fn ($s) => $s
                                ->where('student_number', 'like', $term)
                                ->orWhere('last_name', 'like', $term));
                    });
                })
                ->when($request->filled('method'), fn ($q) => $q->where('method', $request->string('method')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->latest('paid_at')
                ->paginate(25)
                ->withQueryString(),
            'methods' => [
                'cash' => 'Cash',
                'bank_transfer' => 'Bank transfer',
                'card' => 'Card',
                'gateway' => 'Online payment',
                'cheque' => 'Cheque',
            ],
            'statuses' => PaymentStatus::options(),
            'currency' => Setting::get('currency_symbol', '₦'),
            'todayTotal' => (float) Payment::query()
                ->where('status', PaymentStatus::Successful->value)
                ->whereDate('paid_at', today())
                ->sum('amount'),
        ]);
    }

    /** Record money received against an invoice. */
    public function store(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('payments.record');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'method' => ['required', 'in:cash,bank_transfer,card,gateway,cheque'],
            'reference' => ['nullable', 'string', 'max:120'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'amount.min' => 'Enter an amount greater than zero.',
        ]);

        // Guard against typing a figure larger than what is actually owed.
        if ((float) $validated['amount'] > (float) $invoice->balance + 0.009) {
            return back()->withErrors([
                'amount' => sprintf(
                    'That is more than the outstanding balance of %s%s.',
                    Setting::get('currency_symbol', '₦'),
                    number_format((float) $invoice->balance, 2),
                ),
            ])->withInput();
        }

        $payment = DB::transaction(function () use ($invoice, $validated, $request) {
            $payment = Payment::create([
                'receipt_number' => $this->sequences->nextReceiptNumber(),
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'amount' => $validated['amount'],
                'method' => $validated['method'],
                'reference' => $validated['reference'] ?? null,
                'status' => PaymentStatus::Successful,
                'paid_at' => $validated['paid_at'] ?? now(),
                'notes' => $validated['notes'] ?? null,
                'recorded_by' => $request->user()->id,
            ]);

            $invoice->recalculate();

            return $payment;
        });

        // After commit — a parent should never wait on a gateway to get a receipt.
        $this->sms->paymentReceived($payment);

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('status', "Payment recorded against {$invoice->invoice_number}. Receipt {$payment->receipt_number} issued.");
    }

    public function receipt(Invoice $invoice, Payment $payment): View
    {
        $this->authorize('fees.view');

        abort_unless($payment->invoice_id === $invoice->id, 404);

        $invoice->load(['student.level', 'student.schoolClass', 'term']);
        $payment->load('recorder');

        return view('admin.invoices.receipt', [
            'invoice' => $invoice,
            'payment' => $payment,
            'currency' => Setting::get('currency_symbol', '₦'),
        ]);
    }

    public function reverse(Request $request, Payment $payment): RedirectResponse
    {
        $this->authorize('payments.void');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($payment->status === PaymentStatus::Reversed) {
            return back()->with('error', 'This payment has already been reversed.');
        }

        DB::transaction(function () use ($payment, $validated) {
            $payment->update([
                'status' => PaymentStatus::Reversed,
                'notes' => trim(($payment->notes ? $payment->notes.' | ' : '').'Reversed: '.$validated['reason']),
            ]);

            $payment->invoice?->recalculate();
        });

        return back()->with('status', "Payment {$payment->receipt_number} reversed and the invoice balance restored.");
    }

    /* ------------------------------------------------------------------ */
    /* The rest of the collection side */
    /* ------------------------------------------------------------------ */

    /**
     * What the school should have collected, against what it has.
     *
     * Read a year group at a time rather than a child at a time, because the question
     * asked first is which year is behind. The expectation is worked out from the fee —
     * what a child in that year group should be paying this term — and not from the
     * bills raised, which is the whole point of the screen: the register says what has
     * been billed, and this says what should have been. A year group nobody has billed
     * still shows its debt rather than showing zero.
     *
     * Money is the other way about. What has come in is money that actually arrived
     * against this term's bills, so a parent clearing last term's balance does not
     * flatter this term's collection rate.
     */
    public function overview(Request $request): View
    {
        $this->authorize('fees.view');

        // Whatever was asked for, or where the school is standing. `find(0)` is null, so
        // an unfiltered page lands on the current session and its term without a branch.
        $session = AcademicSession::query()->find((int) $request->query('session'))
            ?? AcademicSession::current();

        $term = Term::query()->find((int) $request->query('term'))
            ?? $session?->currentTerm();

        $levels = SchoolLevel::query()->active()->orderBy('order')->orderBy('name')->get();

        $unitFees = $this->unitFees($levels, $session, $term);
        $onRoll = $this->studentsPerLevel();
        $paid = $this->paidPerStudent($session, $term);
        $discounts = $this->discountsPerLevel($session, $term);

        $rows = $levels->map(function (SchoolLevel $level, int $index) use ($unitFees, $onRoll, $paid, $discounts): array {
            $unit = (float) ($unitFees[$level->id] ?? 0);
            $students = (int) ($onRoll[$level->id] ?? 0);

            // One figure per child, so who has settled can be judged against the year
            // group's own fee — which is not the same number for every year group.
            $theirPayments = $paid[$level->id] ?? collect();

            $received = round((float) $theirPayments->sum(), 2);
            $expected = round($unit * $students, 2);
            $settled = $unit > 0 ? $theirPayments->filter(fn (float $amount): bool => $amount >= $unit)->count() : 0;

            return [
                'id' => $level->id,
                'name' => $level->name,
                'unit' => $unit,
                'students' => $students,
                'expected' => $expected,
                'received' => $received,
                'payers' => $theirPayments->count(),
                // A year group charged nothing has nobody owing, whatever the register
                // says about money that has come in.
                'owing' => $unit > 0 ? max($students - $settled, 0) : 0,
                'debt' => round(max($expected - $received, 0), 2),
                'rate' => $expected > 0 ? round(($received / $expected) * 100, 1) : 0.0,
                'discount' => (float) ($discounts[$level->id]['discount'] ?? 0),
                'discounted' => (int) ($discounts[$level->id]['students'] ?? 0),
                'index' => $index + 1,
            ];
        })->values();

        // Added up from the rows rather than counted again, so the cards at the top can
        // never disagree with the table underneath them.
        $expected = round($rows->sum('expected'), 2);
        $received = round($rows->sum('received'), 2);

        return view('admin.payments.overview', [
            'session' => $session,
            'term' => $term,
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->orderByDesc('id')->get(),
            'terms' => Term::query()->orderBy('position')->get(),
            'filters' => ['session' => $session?->id, 'term' => $term?->id],

            'rows' => $rows,
            'totals' => [
                'received' => $received,
                'expected' => $expected,
                'debt' => round(max($expected - $received, 0), 2),
                'rate' => $expected > 0 ? round(($received / $expected) * 100, 1) : 0.0,

                'discount' => round($rows->sum('discount'), 2),

                // Each count is of children, and they do not add up to the roll: a family
                // part-way through is counted as paying and as owing, because both are
                // true of them.
                'students' => $rows->sum('students'),
                'payers' => $rows->sum('payers'),
                'owing' => $rows->sum('owing'),
                'discounted' => $rows->sum('discounted'),
            ],
            'currency' => Setting::get('currency_symbol', '₦'),
        ]);
    }

    /**
     * One year group's children, with what each of them has paid.
     *
     * The detail behind one row of the overview, fetched when that row is opened rather
     * than drawn with the page: a school with a thousand children would otherwise send
     * every one of them to answer a question about one year group.
     *
     * Each child gets one of three standings, and the three are a partition — every child
     * on the roll is exactly one of them. That is deliberately not the same pair of
     * counts the row above shows, where "paid" and "owing" overlap for a family part-way
     * through; here the colours have to account for everybody between them.
     */
    public function level(Request $request): JsonResponse
    {
        $this->authorize('fees.view');

        [$level, $session, $term] = $this->levelFor($request);

        $roll = $this->levelRoll($level, $session, $term);

        return response()->json([
            'level' => ['id' => $level->id, 'name' => $level->name],
            'session' => $session?->name,
            'term' => $term?->name,
            'unit' => $roll['unit'],
            'currency' => Setting::get('currency_symbol', '₦'),
            'children' => $roll['children'],
        ]);
    }

    /**
     * The same list, as a spreadsheet.
     *
     * Written here rather than in the browser: `fputcsv` gets the quoting right without
     * anybody thinking about it, and a file the office can open is worth more than a
     * blob assembled in JavaScript — it works with the page's scripts half-loaded, and
     * it can be tested.
     *
     * `subset` is the four things the office asked to be able to take away: everybody, or
     * one of the three standings.
     */
    public function exportLevel(Request $request): StreamedResponse
    {
        $this->authorize('fees.view');

        [$level, $session, $term] = $this->levelFor($request);

        $subset = (string) $request->query('subset', 'all');

        // `all` is not one of the three standings — it is the absence of a choice between
        // them — so it is allowed by name rather than looked up in them. Anything else
        // has to be a standing the roll can actually be filtered to.
        abort_unless($subset === 'all' || array_key_exists($subset, self::STANDINGS), 404);

        $roll = $this->levelRoll($level, $session, $term, $subset);

        $filename = Str::slug($level->name).'-'.$subset.'.csv';

        return response()->streamDownload(function () use ($roll): void {
            $out = fopen('php://output', 'w');

            // A byte-order mark, because Excel on Windows reads a CSV as the machine's own
            // codepage unless it is told otherwise — and the naira sign is not in it.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, ['Student number', 'Name', 'Class', 'Discount', 'Expected', 'Received', 'Outstanding', 'Status']);

            foreach ($roll['children'] as $child) {
                fputcsv($out, [
                    $child['number'],
                    $child['name'],
                    $child['class'] ?? '',
                    number_format($child['discount'], 2, '.', ''),
                    number_format($child['expected'], 2, '.', ''),
                    number_format($child['received'], 2, '.', ''),
                    number_format($child['outstanding'], 2, '.', ''),
                    $child['status_label'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The year group, session and term a request is about.
     *
     * Shared by the page's detail and its spreadsheet so the two can never disagree about
     * which term they are showing — the export carries the filters as query parameters
     * precisely because it is a fresh request.
     *
     * @return array{0:SchoolLevel,1:?AcademicSession,2:?Term}
     */
    protected function levelFor(Request $request): array
    {
        $validated = $request->validate([
            'level' => ['required', 'integer', 'exists:school_levels,id'],
        ]);

        // The session and the term the page was filtered to, so the detail and the
        // spreadsheet cannot disagree with the row they were opened from.
        $session = AcademicSession::query()->find((int) $request->query('session'))
            ?? AcademicSession::current();

        $term = Term::query()->find((int) $request->query('term'))
            ?? $session?->currentTerm();

        return [SchoolLevel::query()->findOrFail($validated['level']), $session, $term];
    }

    /**
     * One year group's roll, read child by child.
     *
     * The active roll, like the expectation on the page above it: a child who has left is
     * not a child this year group is waiting on money from.
     *
     * @return array{unit:float,children:Collection<int,array<string,mixed>>}
     */
    protected function levelRoll(SchoolLevel $level, ?AcademicSession $session, ?Term $term, ?string $subset = null): array
    {
        $unit = (float) ($this->unitFees(collect([$level]), $session, $term)[$level->id] ?? 0);

        $children = Student::query()
            ->with('schoolClass')
            ->where('level_id', $level->id)
            ->active()
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        $totals = $this->childTotals($children->pluck('id'), $session, $term);

        $rows = $children->map(function (Student $child) use ($unit, $totals): array {
            $received = round($totals['paid'][$child->id] ?? 0, 2);
            $discount = round($totals['discount'][$child->id] ?? 0, 2);

            // What this child is expected to find, after anything taken off.
            $owed = round(max($unit - $discount, 0), 2);
            $standing = $this->standing($owed, $received);

            return [
                'id' => $child->id,
                'number' => (string) ($child->student_number ?: $child->admission_number),
                'name' => $child->full_name,
                'class' => $child->schoolClass?->name,
                'discount' => $discount,
                'expected' => $owed,
                'received' => $received,
                'outstanding' => round(max($owed - $received, 0), 2),
                'status' => $standing,
                'status_label' => self::STANDINGS[$standing],
            ];
        })->values();

        if ($subset !== null && $subset !== 'all') {
            $rows = $rows->where('status', $subset)->values();
        }

        return ['unit' => $unit, 'children' => $rows];
    }

    /**
     * What each student should have paid by now, and what is still to fall due.
     */
    public function schedule(): View
    {
        return $this->placeholder('schedule');
    }

    /**
     * What Paystack has actually paid out to the school's bank, and when.
     */
    public function settlements(): View
    {
        return $this->placeholder('settlements');
    }

    /**
     * The collection reports: by class, by term, by method, and arrears.
     */
    public function reports(): View
    {
        return $this->placeholder('reports');
    }

    /* ------------------------------------------------------------------ */
    /* The figures behind the overview */
    /* ------------------------------------------------------------------ */

    /**
     * What one child in each year group is charged for this term.
     *
     * The fee's own amount, unless the year group has been given one of its own — and an
     * override replaces the fee's amount rather than adding to it, which is why one that
     * has been switched off contributes nothing at all instead of falling back. That is
     * what the Class Amounts tab of a fee is for: JSS1 charged more than JSS2.
     *
     * Fees with no academic session are the ones that are not tied to a year, so they are
     * charged in every one.
     *
     * @param  Collection<int,SchoolLevel>  $levels
     * @return array<int,float> year group => what one child is charged
     */
    protected function unitFees(Collection $levels, ?AcademicSession $session, ?Term $term): array
    {
        // Null where the school has no term set. `isActiveForTerm` and `amountForTerm`
        // both take a position and speak about terms 1 to 3, so neither is asked.
        $position = $term?->position;

        $fees = Fee::query()
            ->active()
            ->when($session, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('academic_session_id', $session->id)
                ->orWhereNull('academic_session_id')))
            ->with('overrides')
            ->get();

        return $levels->mapWithKeys(function (SchoolLevel $level) use ($fees, $position): array {
            $unit = 0.0;

            foreach ($fees as $fee) {
                // A term this fee has been unticked for is a term it is not charged in.
                if ($position !== null && ! $fee->isActiveForTerm($position)) {
                    continue;
                }

                $override = $fee->overrides->firstWhere('level_id', $level->id);

                if ($override !== null) {
                    if ($override->isActive()) {
                        $unit += (float) $override->amount;
                    }

                    continue;
                }

                $unit += (float) ($position === null
                    ? $fee->amount
                    : $fee->amountForTerm($position));
            }

            return [$level->id => round($unit, 2)];
        })->all();
    }

    /**
     * How many children each year group is charged for.
     *
     * The active roll. A child who has left is not a child the school is expecting money
     * from, and counting them would put a debt on the board that nobody can collect.
     *
     * @return array<int,int>
     */
    protected function studentsPerLevel(): array
    {
        return Student::query()
            ->where('status', StudentStatus::Active->value)
            ->whereNotNull('level_id')
            ->groupBy('level_id')
            ->get([DB::raw('level_id'), DB::raw('count(*) as total')])
            ->mapWithKeys(fn ($row): array => [(int) $row->level_id => (int) $row->total])
            ->all();
    }

    /**
     * What each child has paid against this term's bills, grouped by year group.
     *
     * Per child rather than summed per year, because who has settled is judged against
     * the year group's own fee and those differ. Grouped in the database, so this is one
     * read rather than one per year group; the rows it returns are the children who have
     * paid something, which is a smaller set than the roll.
     *
     * A receipt with no bill behind it is left out: it is money the school is holding,
     * not income against anything, and it belongs to no term.
     *
     * @return array<int,Collection<int,float>> year group => child => paid
     */
    protected function paidPerStudent(?AcademicSession $session, ?Term $term): array
    {
        return Payment::query()
            ->where('payments.status', PaymentStatus::Successful->value)
            ->when($session, fn ($query) => $query->whereHas('invoice', fn ($invoice) => $invoice
                ->where('invoices.academic_session_id', $session->id)
                ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
                ->when($term, fn ($scoped) => $scoped->where('invoices.term_id', $term->id))))
            ->join('students', 'students.id', '=', 'payments.student_id')
            ->groupBy('payments.student_id', 'students.level_id')
            ->get([
                DB::raw('payments.student_id as student_id'),
                DB::raw('students.level_id as level_id'),
                DB::raw('sum(payments.amount) as paid'),
            ])
            ->groupBy('level_id')
            ->map(fn (Collection $children): Collection => $children
                ->pluck('paid', 'student_id')
                ->map(fn ($amount): float => (float) $amount))
            ->all();
    }

    /**
     * What has been taken off, by year group.
     *
     * The discount column on the bill, which is what approving a scholarship or a bursary
     * writes. Nothing else produces one, so an empty school shows nothing here and that
     * is the truth rather than a missing figure.
     *
     * @return array<int,array{discount:float,students:int}>
     */
    protected function discountsPerLevel(?AcademicSession $session, ?Term $term): array
    {
        return Invoice::query()
            ->where('invoices.discount', '>', 0)
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->when($session, fn ($query) => $query->where('invoices.academic_session_id', $session->id))
            ->when($term, fn ($query) => $query->where('invoices.term_id', $term->id))
            ->join('students', 'students.id', '=', 'invoices.student_id')
            ->groupBy('students.level_id')
            ->get([
                DB::raw('students.level_id as level_id'),
                DB::raw('sum(invoices.discount) as discount'),
                DB::raw('count(distinct invoices.student_id) as students'),
            ])
            ->mapWithKeys(fn ($row): array => [(int) $row->level_id => [
                'discount' => (float) $row->discount,
                'students' => (int) $row->students,
            ]])
            ->all();
    }

    /**
     * What each of a set of children has paid, and what has been taken off their bill.
     *
     * Asked for a year group's roll rather than the whole school, which is the difference
     * between opening one row and reading every payment in the session to answer it.
     *
     * @param  Collection<int,int>  $studentIds
     * @return array{paid:array<int,float>,discount:array<int,float>}
     */
    protected function childTotals(Collection $studentIds, ?AcademicSession $session, ?Term $term): array
    {
        if ($studentIds->isEmpty()) {
            return ['paid' => [], 'discount' => []];
        }

        $paid = Payment::query()
            ->where('payments.status', PaymentStatus::Successful->value)
            ->whereIn('payments.student_id', $studentIds)
            ->when($session, fn ($query) => $query->whereHas('invoice', fn ($invoice) => $invoice
                ->where('invoices.academic_session_id', $session->id)
                ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
                ->when($term, fn ($scoped) => $scoped->where('invoices.term_id', $term->id))))
            ->groupBy('payments.student_id')
            ->get([
                DB::raw('payments.student_id as student_id'),
                DB::raw('sum(payments.amount) as total'),
            ])
            ->pluck('total', 'student_id')
            ->map(fn ($amount): float => (float) $amount)
            ->all();

        $discount = Invoice::query()
            ->whereIn('invoices.student_id', $studentIds)
            ->where('invoices.discount', '>', 0)
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->when($session, fn ($query) => $query->where('invoices.academic_session_id', $session->id))
            ->when($term, fn ($query) => $query->where('invoices.term_id', $term->id))
            ->groupBy('invoices.student_id')
            ->get([
                DB::raw('invoices.student_id as student_id'),
                DB::raw('sum(invoices.discount) as total'),
            ])
            ->pluck('total', 'student_id')
            ->map(fn ($amount): float => (float) $amount)
            ->all();

        return ['paid' => $paid, 'discount' => $discount];
    }

    /**
     * How far a child has got with the term, and what that is called.
     *
     * A constant rather than a match in two places: the words are shown on the card and
     * written into the spreadsheet, and the two have to agree.
     */
    public const STANDINGS = [
        'completed' => 'Paid in full',
        'partial' => 'Part payment',
        'pending' => 'Not paid',
    ];

    /**
     * Green, yellow or red.
     *
     * Owing nothing counts as settled, and that covers two cases worth naming. A child
     * whose family was let off the whole fee owes nothing, and neither does anybody in a
     * year group whose fee is switched off for the term. Neither is money outstanding, so
     * neither is red — a page that said so would send somebody chasing it.
     */
    protected function standing(float $owed, float $received): string
    {
        if ($owed <= 0 || $received >= $owed) {
            return 'completed';
        }

        return $received > 0 ? 'partial' : 'pending';
    }

    /* ------------------------------------------------------------------ */

    /**
     * Draw one of the pages above.
     *
     * The page is looked up in PAGES rather than trusted from the URL, so which
     * permission it needs and what it is called are decided in this file and never by
     * what the browser asked for.
     */
    protected function placeholder(string $key): View
    {
        $page = collect(self::PAGES)->firstWhere('key', $key);

        abort_if($page === null, 404);

        $this->authorize($page['permission']);

        return view('admin.payments.'.$key, ['page' => $page]);
    }

    /**
     * The collection screens this controller serves.
     *
     * Not the same thing as the Payments submenu. Settlement is on this list and no
     * longer under Payments on the sidebar — the page is unchanged and still answers
     * at its own address; only where the menu puts it has moved. Which pages hang off
     * Payments is the sidebar's business, and that lives in AdminMenu.
     *
     * The school's own fees site has every one of these working; here each unbuilt page
     * says what will be on it, which is what keeps a complete menu from being a list of
     * pages that error. Defined next to the method that reads it.
     *
     * @var array<int,array{key:string,label:string,icon:string,permission:string}>
     */
    public const PAGES = [
        ['key' => 'overview', 'label' => 'Overview', 'icon' => 'chart', 'permission' => 'fees.view'],
        ['key' => 'schedule', 'label' => 'Payment Schedule', 'icon' => 'calendar', 'permission' => 'fees.view'],
        ['key' => 'settlements', 'label' => 'Settlements', 'icon' => 'briefcase', 'permission' => 'fees.view'],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'report', 'permission' => 'fees.reports'],
    ];
}
