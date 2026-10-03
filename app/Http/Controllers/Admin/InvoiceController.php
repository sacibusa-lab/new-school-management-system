<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Services\Fees\InvoiceGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceGenerationService $invoices,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('fees.view');

        $session = AcademicSession::current();

        $invoices = Invoice::query()
            ->with(['student.level', 'student.schoolClass', 'term'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.trim($request->string('q')->toString()).'%';

                $q->where(function ($inner) use ($term) {
                    $inner->where('invoice_number', 'like', $term)
                        ->orWhereHas('student', fn ($s) => $s
                            ->where('student_number', 'like', $term)
                            ->orWhere('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term));
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('session'), fn ($q) => $q->where('academic_session_id', $request->integer('session')))
            ->when($request->filled('class'), fn ($q) => $q->whereHas('student', fn ($s) => $s->where('school_class_id', $request->integer('class'))))
            ->latest('issued_at')
            ->paginate(25)
            ->withQueryString();

        $totals = Invoice::query()
            ->when($session, fn ($q) => $q->where('academic_session_id', $session->id))
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->selectRaw('COALESCE(SUM(total),0) as billed, COALESCE(SUM(amount_paid),0) as collected, COALESCE(SUM(balance),0) as outstanding')
            ->first();

        return view('admin.invoices.index', [
            'invoices' => $invoices,
            'totals' => $totals,
            'statuses' => InvoiceStatus::options(),
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(),
            'classes' => SchoolClass::query()->where('is_active', true)->with('level')->orderBy('name')->get(),
            'currency' => Setting::get('currency_symbol', '₦'),
            'session' => $session,
            // Only for the single-student form at the foot of the page. Billing a whole
            // year group is done from its fee structure; this is for the exceptions, so
            // the picker holds the students who are on the roll now.
            'students' => Student::query()
                ->when($session, fn ($q) => $q->where('academic_session_id', $session->id))
                ->where('status', StudentStatus::Active->value)
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
        ]);
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('fees.view');

        // No `term.academicSession`: a term is universal and belongs to every session,
        // so the session is named by the invoice itself, not by the term under it.
        $invoice->load([
            'student.level', 'student.schoolClass', 'term',
            'items.category', 'payments.recorder', 'feeStructure',
        ]);

        return view('admin.invoices.show', [
            'invoice' => $invoice,
            'currency' => Setting::get('currency_symbol', '₦'),
        ]);
    }

    /** Raise a single invoice on demand. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('fees.invoice');

        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'fee_structure_id' => ['nullable', 'exists:fee_structures,id'],
            'term_id' => ['nullable', 'exists:terms,id'],
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $structure = $validated['fee_structure_id'] ? FeeStructure::find($validated['fee_structure_id']) : null;

        $invoice = $this->invoices->generateForStudent(
            $student,
            $structure,
            $validated['term_id'] ?? null,
            $request->user(),
        );

        if (! $invoice) {
            return back()->with('error', "No fee structure applies to {$student->student_number} for that term. Publish one first.");
        }

        if (! $invoice->wasRecentlyCreated) {
            return redirect()
                ->route('admin.invoices.show', $invoice)
                ->with('error', 'This student already has an invoice for that term.');
        }

        return redirect()
            ->route('admin.invoices.show', $invoice)
            ->with('status', "Invoice {$invoice->invoice_number} raised for {$student->full_name}.");
    }
}
