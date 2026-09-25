<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\NumberSequenceService;
use App\Services\Sms\SmsNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(
        private readonly NumberSequenceService $sequences,
        private readonly SmsNotifier $sms,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('fees.view');

        return view('admin.payments.index', [
            'payments' => Payment::query()
                ->with(['student.level', 'invoice', 'recorder'])
                ->when($request->filled('q'), function ($q) use ($request) {
                    $term = '%' . trim($request->string('q')->toString()) . '%';

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

        $invoice->load(['student.level', 'student.schoolClass', 'term.academicSession']);
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
                'notes' => trim(($payment->notes ? $payment->notes . ' | ' : '') . 'Reversed: ' . $validated['reason']),
            ]);

            $payment->invoice?->recalculate();
        });

        return back()->with('status', "Payment {$payment->receipt_number} reversed and the invoice balance restored.");
    }
}
