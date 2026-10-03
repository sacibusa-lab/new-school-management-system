<?php

namespace App\Services\Payment;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Student;
use App\Services\NumberSequenceService;
use App\Services\Sms\SmsNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Puts money a parent has transferred onto their child's bill.
 *
 * The rule is the fee site's, kept because it is the right one: **oldest debt
 * first**. A child with last term still owing and this term running has an incoming
 * transfer applied to last term, which is what the school means by settling up —
 * and it stops an old balance sitting there for ever while the new one gets paid.
 *
 * Where the fee site recalculates the whole school's fee matrix on every webhook to
 * work out what a term costs, here that number was settled once, at billing time,
 * and is stored on the invoice as its own balance. Same rule, no arithmetic.
 */
class PaymentAllocationService
{
    public function __construct(
        private readonly NumberSequenceService $sequences,
        private readonly SmsNotifier $sms,
    ) {}

    /**
     * Apply an amount that arrived through the gateway.
     *
     * @return array<int,Payment> one per invoice the money reached, oldest first
     */
    public function apply(
        Student $student,
        float $amount,
        string $reference,
        string $gateway = 'paystack',
    ): array {
        $owing = Invoice::query()
            ->where('student_id', $student->id)
            ->where('status', '!=', InvoiceStatus::Cancelled->value)
            ->where('balance', '>', 0)
            // Oldest first: by the day it was raised, then by id for invoices raised
            // in the same run, which is what a term's billing produces.
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        $remaining = round($amount, 2);
        $payments = [];

        foreach ($owing as $invoice) {
            if ($remaining <= 0) {
                break;
            }

            $applied = min($remaining, round((float) $invoice->balance, 2));
            $remaining = round($remaining - $applied, 2);

            $payments[] = DB::transaction(function () use ($invoice, $applied, $reference, $gateway, $student) {
                $payment = Payment::create([
                    'receipt_number' => $this->sequences->nextReceiptNumber(),
                    'invoice_id' => $invoice->id,
                    'student_id' => $student->id,
                    'amount' => $applied,
                    'method' => 'gateway',
                    'reference' => $reference,
                    'gateway' => $gateway,
                    'status' => PaymentStatus::Successful,
                    'paid_at' => now(),
                    'notes' => 'Received by bank transfer into the student\'s dedicated account.',
                ]);

                $invoice->recalculate();

                return $payment;
            });
        }

        // More money than was owed. A transfer cannot be refused — it has already
        // landed — so it is receipted and held as a credit on the account, where the
        // office can see it rather than it disappearing into a rounded total.
        if ($remaining > 0) {
            $payments[] = Payment::create([
                'receipt_number' => $this->sequences->nextReceiptNumber(),
                'invoice_id' => null,
                'student_id' => $student->id,
                'amount' => $remaining,
                'method' => 'gateway',
                'reference' => $reference,
                'gateway' => $gateway,
                'status' => PaymentStatus::Successful,
                'paid_at' => now(),
                'notes' => 'More than was owed. Held as a credit on the account.',
            ]);
        }

        // After commit: a parent must never wait on a gateway for their receipt.
        foreach ($payments as $payment) {
            $this->sms->paymentReceived($payment);
        }

        return $payments;
    }
}
