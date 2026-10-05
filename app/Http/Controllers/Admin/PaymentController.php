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
use App\Models\StudentAdjustment;
use App\Models\Term;
use App\Services\Branding\BrandingService;
use App\Services\Fees\InvoiceGenerationService;
use App\Services\NumberSequenceService;
use App\Services\Sms\SmsNotifier;
use App\Services\Students\StudentPortraitService;
use App\Support\ClassOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
            // The section comes with the class: the panel filters by the arm, and the arm is
            // a thing of its own. JSS1A and SS1A share the letter A, so it has to be read
            // from the section rather than off the end of the class name — which would also
            // break the moment a school names an arm "Blue" or "JSS 1A".
            ->with(['schoolClass.section'])
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
                // The arm on its own, for the panel's filter. The card and the CSV keep the
                // whole class name, because a slip that says A does not say which year.
                'subclass' => $child->schoolClass?->section?->name,
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

    /* ------------------------------------------------------------------ */
    /* Payment Schedule */
    /* ------------------------------------------------------------------ */

    /**
     * What each child is charged this term, and what is still owed on it.
     *
     * A payment slip to a child, which is what the office hands over the counter: the fees
     * being charged for, anything taken off, anything brought forward from an earlier
     * session, the account the money goes into, and what is left to pay.
     *
     * Built from the same fees the overview works from rather than from the bills, for the
     * reason the overview gives — the expectation is what the year group is charged, so a
     * child nobody has raised a bill for still owes what they owe. A schedule that went
     * blank until an invoice existed would be blank exactly when the office needed it.
     *
     * The filters are a plain GET form rather than a fetch: the sheet is a page of money,
     * and it should be able to be linked to, bookmarked and printed from a school computer
     * that has not finished loading the rest of the site.
     */
    public function schedule(Request $request): View
    {
        $this->authorize('fees.view');

        $filters = $this->scheduleFilters($request);

        return view('admin.payments.schedule', [
            'sheet' => $this->scheduleSheet($filters),
            'filters' => $filters,
            'statuses' => self::STANDINGS,
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->orderByDesc('id')->get(),
            'terms' => Term::query()->orderBy('position')->get(),
            'classOptions' => ClassOptions::grouped(),
            'feeOptions' => $this->feeOptions(),
            'currency' => Setting::get('currency_symbol', '₦'),
        ]);
    }

    /**
     * The same sheet as a spreadsheet.
     *
     * Written by the server rather than assembled in the browser, so the file is whole
     * whether or not the page has finished loading, and so what is in it can be asserted.
     * The four subsets are the three standings and the whole sheet, which is what the
     * office asks for by name.
     */
    public function exportSchedule(Request $request): StreamedResponse
    {
        $this->authorize('fees.view');

        $filters = $this->scheduleFilters($request);
        $subset = $this->subset($request);
        $sheet = $this->scheduleSheet($filters, $subset);

        return response()->streamDownload(function () use ($sheet): void {
            $file = fopen('php://output', 'w');

            // For Excel: without it the naira sign and the accented names arrive as rubble.
            fwrite($file, "\xEF\xBB\xBF");

            fputcsv($file, [
                'Student number', 'Name', 'Class', 'Charged', 'Discount',
                'Expected', 'Received', 'Outstanding', 'Status',
            ]);

            foreach ($sheet['slips'] as $slip) {
                fputcsv($file, [
                    $slip['number'],
                    $slip['name'],
                    $slip['class'],
                    number_format($slip['charged'], 2, '.', ''),
                    number_format($slip['discount'], 2, '.', ''),
                    number_format($slip['expected'], 2, '.', ''),
                    number_format($slip['paid'], 2, '.', ''),
                    number_format($slip['due'], 2, '.', ''),
                    self::STANDINGS[$slip['status']],
                ]);
            }

            fclose($file);
        }, $this->scheduleFilename($filters, $subset, 'csv'), ['Content-Type' => 'text/csv']);
    }

    /**
     * The same slips on paper.
     *
     * A PDF rather than a print stylesheet because the slips go home: the office prints a
     * class at a time and cuts them up, so they have to sit several to a sheet and not be
     * split across two of them.
     */
    public function downloadSchedule(
        Request $request,
        BrandingService $branding,
        StudentPortraitService $portraits,
    ): Response {
        $this->authorize('fees.view');

        $filters = $this->scheduleFilters($request);
        $subset = $this->subset($request);

        $sheet = $this->scheduleSheet($filters, $subset);

        // The photographs travel inside the file for the same reason the crest does: DomPDF
        // does not fetch an image over HTTP. They are cropped round here rather than in the
        // view because the slip's slot is round and DomPDF will not clip a picture to a
        // border radius — it draws the ring and leaves the face square inside it.
        $sheet['slips'] = $sheet['slips']->map(fn (array $slip): array => [
            ...$slip,
            'photo' => $portraits->circled($slip['photo_path'] ?? null),
        ]);

        // What the slip is a bill for, in the office's own words: the fee the sheet was
        // narrowed to, or the term when it is every one of them. "FIRST TERM FEE" is what
        // the printed slip is headed, and a sheet of them is read at a glance.
        $heading = $filters['fee'] !== 0
            ? (Fee::query()->find($filters['fee'])?->title ?? 'School fees')
            : mb_strtoupper((string) ($filters['term']?->name ?? 'School')).' FEE';

        // `$school` is not passed: AppServiceProvider shares the branding object with every
        // view, and a `school` key of our own would be overwritten by it. Its `currency` is
        // used for the same reason — one place to change the symbol.
        $pdf = Pdf::loadView('admin.payments.schedule-pdf', [
            'sheet' => $sheet,
            'session' => $filters['session']?->name,
            'term' => $filters['term']?->name,
            'heading' => $heading,
            // DomPDF does not fetch images over HTTP, so the crest travels inline or not at
            // all — the same reason the admission letter inlines its signature. Sized for
            // the head of a slip, and capped at that height: a square crest would otherwise
            // make every slip on the sheet taller and push the sixth onto a second page.
            'logo' => $branding->logoForPdf(16, 9),
            // What a slip shows when no crest has been uploaded: the same letters the sidebar
            // and the admit cards show, from the same rule.
            'schoolMonogram' => BrandingService::monogram(),
            // On every slip rather than once at the foot of the sheet: a slip is cut off and
            // sent home on its own, so a credit on the sheet alone would never reach anybody.
            'credit' => config('saci.credit'),
        ])->setPaper('a4');

        return $pdf->download($this->scheduleFilename($filters, $subset, 'pdf'));
    }

    /**
     * What the sheet has been asked for.
     *
     * @return array{session:?AcademicSession,term:?Term,class:string,fee:int}
     */
    protected function scheduleFilters(Request $request): array
    {
        $session = AcademicSession::query()->find($request->integer('session'))
            ?? AcademicSession::current();

        return [
            'session' => $session,
            'term' => Term::query()->find($request->integer('term'))
                ?? $session?->currentTerm()
                ?? Term::current(),
            // Kept as it was written rather than taken apart, so the control recognises its
            // own choice when the page is drawn again. ClassOptions does the reading.
            'class' => (string) $request->string('class'),
            'fee' => $request->integer('fee'),
        ];
    }

    /**
     * Which of the three standings a download is narrowed to.
     *
     * `all` is not one of them — it is the absence of a choice between them, and anything
     * that is not one of the four is treated as the whole sheet rather than as an error.
     */
    protected function subset(Request $request): string
    {
        $subset = (string) $request->string('subset', 'all');

        return array_key_exists($subset, self::STANDINGS) ? $subset : 'all';
    }

    /**
     * Every slip on the sheet, and the figures across them.
     *
     * @param  array{session:?AcademicSession,term:?Term,class:string,fee:int}  $filters
     * @return array{slips:Collection<int,array<string,mixed>>,counts:array<string,int>,totals:array<string,float>}
     */
    protected function scheduleSheet(array $filters, string $subset = 'all'): array
    {
        $chosen = ClassOptions::parse($filters['class']);

        $students = Student::query()
            ->active()
            ->with(['schoolClass.section', 'level', 'virtualAccount'])
            ->when($chosen['level'] !== 0, fn ($query) => $query->where('level_id', $chosen['level']))
            ->when($chosen['section'] !== 0, fn ($query) => $query->whereHas(
                'schoolClass',
                fn ($classes) => $classes->where('section_id', $chosen['section']),
            ))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        // Fees with no academic session are the ones not tied to a year, so they are charged
        // in every one — the same rule the overview charges by.
        $fees = Fee::query()
            ->active()
            ->when($filters['session'], fn ($query, $session) => $query->where(fn ($inner) => $inner
                ->where('academic_session_id', $session->id)
                ->orWhereNull('academic_session_id')))
            ->when($filters['fee'] !== 0, fn ($query) => $query->where('id', $filters['fee']))
            ->with('overrides')
            ->get();

        $position = $filters['term']?->position;

        $money = $this->childTotals($students->pluck('id'), $filters['session'], $filters['term']);
        $arrears = $this->arrearsPerChild($students->pluck('id'), $filters['session']);
        $adjustments = $this->adjustmentsPerChild($students->pluck('id'), $filters['session'], $filters['term']);

        $slips = $students->map(function (Student $child) use ($fees, $position, $money, $arrears, $adjustments): array {
            $level = $child->level;

            $lines = $level === null ? [] : $this->feeLinesFor($level, $fees, $position);
            $brought = $arrears[$child->id] ?? [];
            $changed = $adjustments[$child->id] ?? [];

            // What the slip asks for before anything is taken off: this term's fees, whatever
            // the office has added or taken off, and whatever earlier sessions still owe.
            $charged = round(
                collect($lines)->sum('amount')
                + collect($changed)->sum('amount')
                + collect($brought)->sum('amount'),
                2,
            );
            $discount = round($money['discount'][$child->id] ?? 0, 2);
            $expected = round(max($charged - $discount, 0), 2);
            $paid = round($money['paid'][$child->id] ?? 0, 2);

            return [
                'id' => $child->id,
                'number' => (string) ($child->student_number ?: $child->admission_number),
                'name' => $child->full_name,
                'class' => $child->schoolClass?->name,
                'arm' => $child->schoolClass?->section?->name,
                'level' => $level?->name,
                // The letters on the navy tile, from the same rule the sidebar and the admit
                // cards use — see BrandingService::monogram().
                'initials' => BrandingService::monogram($child->full_name),
                // The face for the round slot, if the child has one. Only the path travels:
                // the bytes are read for the printed sheet alone, so a page of two hundred
                // children does not carry two hundred photographs in its markup.
                'photo_path' => $child->photo_path,
                'account' => $child->virtualAccount?->account_number,
                'account_name' => $child->virtualAccount?->account_name,
                'bank' => $child->virtualAccount?->bank_name,
                'lines' => $lines,
                'adjustments' => $changed,
                'arrears' => $brought,
                'charged' => $charged,
                'discount' => $discount,
                'expected' => $expected,
                'paid' => $paid,
                'due' => round(max($expected - $paid, 0), 2),
                'status' => $this->standing($expected, $paid),
            ];
        })->values();

        // Tallied before the subset narrows anything: the four figures say what the whole
        // sheet holds, which is the question the buttons beside them are asked.
        $counts = [
            'all' => $slips->count(),
            'completed' => $slips->where('status', 'completed')->count(),
            'partial' => $slips->where('status', 'partial')->count(),
            'pending' => $slips->where('status', 'pending')->count(),
        ];

        if ($subset !== 'all') {
            $slips = $slips->where('status', $subset)->values();
        }

        return [
            'slips' => $slips,
            'counts' => $counts,
            'totals' => [
                'expected' => round($slips->sum('expected'), 2),
                'paid' => round($slips->sum('paid'), 2),
                'due' => round($slips->sum('due'), 2),
            ],
        ];
    }

    /**
     * What each child still owes from sessions that have ended.
     *
     * A bill raised in an earlier session and not settled. The outstanding is the bill less
     * what has been paid against it — the same pair of columns the register reads — and a
     * cancelled bill is owed by nobody.
     *
     * Only earlier sessions count. A bill for a session that has not started is not arrears,
     * and putting it on this term's slip would ask a family to pay next year's fees now.
     *
     * @param  Collection<int,int>  $studentIds
     * @return array<int,array<int,array{title:string,amount:float}>> child => what is carried
     */
    protected function arrearsPerChild(Collection $studentIds, ?AcademicSession $session): array
    {
        if ($session === null || $studentIds->isEmpty()) {
            return [];
        }

        $rows = Invoice::query()
            ->whereIn('invoices.student_id', $studentIds)
            ->where('invoices.academic_session_id', '!=', $session->id)
            ->where('invoices.status', '!=', InvoiceStatus::Cancelled->value)
            ->whereColumn('invoices.total', '>', 'invoices.amount_paid')
            ->join('academic_sessions', 'academic_sessions.id', '=', 'invoices.academic_session_id')
            ->where('academic_sessions.starts_on', '<', $session->starts_on)
            ->groupBy(
                'invoices.student_id',
                'academic_sessions.id',
                'academic_sessions.name',
                'academic_sessions.starts_on',
            )
            ->orderBy('academic_sessions.starts_on')
            ->get([
                DB::raw('invoices.student_id as student_id'),
                DB::raw('academic_sessions.name as session_name'),
                DB::raw('sum(invoices.total - invoices.amount_paid) as outstanding'),
            ]);

        $arrears = [];

        foreach ($rows as $row) {
            $arrears[(int) $row->student_id][] = [
                // The year is its own field rather than part of the title, because the printed
                // slip has a Session column to put it in and the screen has a sentence.
                'title' => 'Brought forward',
                'session' => $row->session_name,
                'amount' => round((float) $row->outstanding, 2),
            ];
        }

        return $arrears;
    }

    /**
     * What has been added to or taken off each child's bill this term.
     *
     * One line to an adjustment rather than a sum: a parent reading the slip is owed the
     * reason, and two discounts given for two reasons are two decisions. A slip that said
     * only "less ₦10,000" starts an argument at the counter.
     *
     * Scoped to one session, because that is what makes an adjustment belong to a year. The
     * term is applied when there is one; an adjustment made against the session alone is
     * money moved for the year and shows on whichever term is being read.
     *
     * @param  Collection<int,int>  $studentIds
     * @return array<int,array<int,array{title:string,amount:float}>> child => what was changed
     */
    protected function adjustmentsPerChild(Collection $studentIds, ?AcademicSession $session, ?Term $term): array
    {
        if ($session === null || $studentIds->isEmpty()) {
            return [];
        }

        $rows = StudentAdjustment::query()
            ->whereIn('student_id', $studentIds)
            ->where('academic_session_id', $session->id)
            ->when($term, fn ($query, $term) => $query->where('term_id', $term->id))
            ->orderBy('id')
            ->get();

        $adjustments = [];

        foreach ($rows as $row) {
            $adjustments[$row->student_id][] = [
                'title' => $row->label(),
                'amount' => round((float) $row->amount, 2),
            ];
        }

        return $adjustments;
    }

    /**
     * Add to, or take off, the bills of a set of children at once.
     *
     * One adjustment each rather than one row shared between them, because these are
     * separate children's bills: a shared row would make "what did we do to Ada's bill" a
     * join, and undoing it for one child a special case.
     *
     * The amount is stored signed. Nothing here edits a fee or a bill — an adjustment sits
     * beside them and is added up with them, so the reason survives and can be reversed by
     * making the opposite one.
     */
    public function adjustSchedule(Request $request): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['integer', 'exists:students,id'],
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'type' => ['required', 'in:add,subtract'],
            'description' => ['nullable', 'string', 'max:120'],
        ], [
            'students.required' => 'Tick at least one child whose amount is being changed.',
            'amount.min' => 'Enter an amount greater than zero.',
        ]);

        $filters = $this->scheduleFilters($request);

        $signed = $validated['type'] === 'subtract'
            ? -1 * (float) $validated['amount']
            : (float) $validated['amount'];

        $description = trim((string) ($validated['description'] ?? ''));

        if ($description === '') {
            $description = $signed < 0 ? 'Discount' : 'Additional charge';
        }

        $changed = DB::transaction(function () use ($validated, $filters, $signed, $description, $request): int {
            foreach ($validated['students'] as $studentId) {
                StudentAdjustment::create([
                    'student_id' => $studentId,
                    'academic_session_id' => $filters['session']?->id,
                    'term_id' => $filters['term']?->id,
                    'amount' => $signed,
                    'description' => $description,
                    'created_by' => $request->user()->id,
                ]);
            }

            return count($validated['students']);
        });

        $money = Setting::get('currency_symbol', '₦').number_format(abs($signed), 2);
        $word = $signed < 0 ? 'taken off' : 'added to';

        return back()->with(
            'status',
            $money.' '.$word.' '.$changed.' '.Str::plural('child', $changed).' — '.$description.'.',
        );
    }

    /**
     * Record money received for a set of children at once.
     *
     * The money goes against each child's bill for this session and this term, and the bill
     * is raised from the published fee structure if it does not exist yet — the same service
     * the rest of the school bills with, so a bill raised here is a bill like any other
     * rather than a second kind of money.
     *
     * Children the office cannot usefully mark are counted and named rather than passed over.
     * A run that quietly did nothing looks exactly like one that worked, and the place that
     * shows up is a parent being told they had paid.
     *
     * No text message goes out from here. The counter taking one payment sends a receipt;
     * this is the office catching up on a term, and forty texts is not a favour to anybody.
     */
    public function recordSchedule(Request $request, InvoiceGenerationService $billing): RedirectResponse
    {
        $this->authorize('payments.record');

        $validated = $request->validate([
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['integer', 'exists:students,id'],
            'method' => ['required', 'in:cash,bank_transfer,card,gateway,cheque'],
            'mode' => ['required', 'in:full,part'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:99999999', 'required_if:mode,part'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'students.required' => 'Tick at least one child who is paying.',
            'amount.required_if' => 'Enter the amount each of them is paying.',
        ]);

        $filters = $this->scheduleFilters($request);

        $recorded = 0;
        $settled = 0;
        $unbilled = [];

        foreach ($validated['students'] as $studentId) {
            $student = Student::query()->find($studentId);

            if ($student === null) {
                continue;
            }

            $invoice = $billing->generateForStudent($student, null, $filters['term']?->id, $request->user());

            if ($invoice === null) {
                $unbilled[] = $student->full_name;

                continue;
            }

            $outstanding = round((float) $invoice->balance, 2);

            if ($outstanding <= 0) {
                $settled++;

                continue;
            }

            // "Full" is what the bill says is left, never the slip's figure: the two can
            // disagree while the fees catalogue and the published structures are out of step,
            // and money has to be recorded against the thing it settles.
            $amount = $validated['mode'] === 'full'
                ? $outstanding
                : min((float) $validated['amount'], $outstanding);

            DB::transaction(function () use ($invoice, $amount, $validated, $request): void {
                Payment::create([
                    'receipt_number' => $this->sequences->nextReceiptNumber(),
                    'invoice_id' => $invoice->id,
                    'student_id' => $invoice->student_id,
                    'amount' => $amount,
                    'method' => $validated['method'],
                    'status' => PaymentStatus::Successful,
                    'paid_at' => $validated['paid_at'] ?? now(),
                    'notes' => $validated['notes'] ?? null,
                    'recorded_by' => $request->user()->id,
                ]);

                $invoice->recalculate();
            });

            $recorded++;
        }

        return back()->with('status', $this->recordedMessage($recorded, $settled, $unbilled));
    }

    /**
     * What to say once the run is over.
     *
     * The children who were left alone are in the sentence on purpose.
     *
     * @param  array<int,string>  $unbilled
     */
    protected function recordedMessage(int $recorded, int $settled, array $unbilled): string
    {
        $sentence = $recorded.' '.Str::plural('payment', $recorded).' recorded.';

        if ($settled > 0) {
            $sentence .= ' '.$settled.' already settled.';
        }

        if ($unbilled !== []) {
            $sentence .= ' Nothing to pay against for '.implode(', ', $unbilled)
                .' — publish a fee structure for their year group first.';
        }

        return $sentence;
    }

    /**
     * The fees a sheet can be narrowed to.
     *
     * By name rather than by id, because the office filters a sheet by what it is for —
     * "the termly fee" — and an id is not a thing anybody says out loud.
     *
     * @return array<int,string>
     */
    protected function feeOptions(): array
    {
        return Fee::query()->active()->orderBy('title')->pluck('title', 'id')->all();
    }

    /** What a downloaded sheet is called, so two terms' files cannot be confused. */
    protected function scheduleFilename(array $filters, string $subset, string $extension): string
    {
        $name = implode(' ', array_filter([
            'Payment schedule',
            $filters['session']?->name,
            $filters['term']?->name,
            $subset === 'all' ? null : self::STANDINGS[$subset],
        ]));

        // The slashes in "2026/2027" come out of a slug as "20262027", which is a year nobody
        // recognises. Spaces keep the two halves apart.
        $name = str_replace(['/', '\\'], ' ', $name);

        return Str::slug($name).'.'.$extension;
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

        return $levels->mapWithKeys(fn (SchoolLevel $level): array => [
            $level->id => round(collect($this->feeLinesFor($level, $fees, $position))->sum('amount'), 2),
        ])->all();
    }

    /**
     * What one child in a year group is charged this term, fee by fee.
     *
     * The rule unitFees() adds up, itemised rather than summed, because a payment slip has
     * to name what it is charging for. One method rather than two: a slip that itemised a
     * different total from the one the overview says the year group owes would be a page
     * arguing with itself.
     *
     * @param  Collection<int,Fee>  $fees
     * @return array<int,array{title:string,amount:float}>
     */
    protected function feeLinesFor(SchoolLevel $level, Collection $fees, ?int $position): array
    {
        $lines = [];

        foreach ($fees as $fee) {
            // A term this fee has been unticked for is a term it is not charged in.
            if ($position !== null && ! $fee->isActiveForTerm($position)) {
                continue;
            }

            $override = $fee->overrides->firstWhere('level_id', $level->id);

            if ($override !== null) {
                if ($override->isActive()) {
                    $lines[] = [
                        'title' => $this->feeTitle($fee),
                        'amount' => round((float) $override->amount, 2),
                    ];
                }

                continue;
            }

            $lines[] = [
                'title' => $this->feeTitle($fee),
                'amount' => round((float) ($position === null
                    ? $fee->amount
                    : $fee->amountForTerm($position)), 2),
            ];
        }

        return $lines;
    }

    /**
     * What a fee is called on a slip.
     *
     * The catalogue's own words, falling back to the description and then to something
     * printable, because a slip is read by a parent rather than by the office and an empty
     * row with a price beside it explains nothing.
     */
    protected function feeTitle(Fee $fee): string
    {
        $title = trim((string) ($fee->title ?: $fee->description));

        return $title === '' ? 'School fee' : $title;
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
