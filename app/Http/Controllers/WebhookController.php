<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\StudentVirtualAccount;
use App\Models\WebhookEvent;
use App\Services\Payment\PaymentAllocationService;
use App\Services\Payment\PaystackProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where Paystack tells us a parent has paid.
 *
 * Lifted from the school's own fees site, because the parts that matter are right
 * and have been running in anger: verifying the signature first, writing the event
 * down before acting on it, and deciding which child a charge belongs to from the
 * account the money landed in rather than from anything the payer typed.
 *
 * The one thing that could not come across is what happens to the money — the fee
 * site recomputes a term's total from its own fee tables here, and this platform
 * settled that number once, at billing time, and keeps it on the invoice. See
 * PaymentAllocationService.
 *
 * This endpoint is public and carries no CSRF token, because Paystack cannot send
 * one. The signature is what stands in for it, and nothing is written against a
 * child's account until it checks out.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly PaystackProvider $paystack,
    ) {}

    public function paystack(Request $request, PaymentAllocationService $allocations): JsonResponse
    {
        $signature = (string) $request->header('x-paystack-signature');
        $body = $request->getContent();

        if (! $this->paystack->signatureIsValid($signature, $body)) {
            // Somebody else's POST, or a key that has been rotated since. Either way
            // this is not money, and it is never treated as any.
            Log::warning('A Paystack webhook arrived with a signature that did not match.');

            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);

        // Paystack retries, so the same charge arrives more than once. The unique
        // index behind firstOrCreate is what makes the second arrival a no-op rather
        // than a second receipt — which is the difference between a school and a
        // school with money missing.
        $record = WebhookEvent::firstOrCreate(
            [
                'provider' => 'paystack',
                'event_type' => $event,
                'reference' => $data['reference'] ?? null,
            ],
            ['payload' => $data, 'status' => 'pending'],
        );

        if (! $record->wasRecentlyCreated) {
            return response()->json(['message' => 'Already handled'], 200);
        }

        try {
            match ($event) {
                'charge.success' => $this->chargeSucceeded($data, $record, $allocations),
                'dedicatedaccount.assign.success' => $record->markProcessed(),
                default => $record->markIgnored(),
            };
        } catch (Throwable $e) {
            // Kept with the event itself, so the office can see what the gateway said
            // and what we did about it, rather than a line in a log they cannot read.
            Log::error('A Paystack webhook could not be processed', [
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            $record->markFailed($e->getMessage());
        }

        // Always 200 once the signature is good: a non-2xx makes Paystack retry, and
        // a retry of something we have already recorded as failed only makes noise.
        return response()->json(['message' => 'Webhook received'], 200);
    }

    /**
     * A charge that succeeded.
     *
     * Which child it belongs to is decided by the account the money landed in, which
     * is the entire reason each of them has one of their own.
     *
     * @param  array<string,mixed>  $data
     */
    protected function chargeSucceeded(array $data, WebhookEvent $record, PaymentAllocationService $allocations): void
    {
        $reference = (string) ($data['reference'] ?? '');
        $amount = round(((int) ($data['amount'] ?? 0)) / 100, 2);
        $customerCode = $data['customer']['customer_code'] ?? null;

        $student = $customerCode
            ? StudentVirtualAccount::query()->where('customer_code', $customerCode)->first()?->student
            : null;

        // Otherwise a payment that was started from this side, where the reference on
        // the charge is one we issued.
        if (! $student && $reference !== '') {
            $student = Payment::query()->where('reference', $reference)->first()?->student;
        }

        if (! $student || $amount <= 0) {
            // Nothing to credit: an account this school does not hold, or a charge
            // carrying no money. Not a failure — there is nothing here to look into.
            $record->markIgnored();

            return;
        }

        $allocations->apply($student, $amount, $reference);

        $record->markProcessed();

        Log::info('A Paystack charge was applied to a student\'s bill', [
            'reference' => $reference,
            'student_id' => $student->id,
            'amount' => $amount,
        ]);
    }
}
