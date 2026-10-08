<?php

namespace App\Services\Fees;

use App\Enums\PaymentStatus;
use App\Models\AcademicSession;
use App\Models\BankAccount;
use App\Models\Disbursement;
use App\Models\Fee;
use App\Models\Payment;
use App\Models\Term;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How the money collected in a session divides into the school's own accounts.
 *
 * The school does not keep what it collects in one place, and the gateway does not pay it
 * out in one place either. Each fee says how its money is carved up — a fixed amount to
 * this account, a fixed amount to that one — and the platform keeps back a flat charge per
 * transaction for moving it. This works the collected payments back into those parts, so
 * the office can see what has to be transferred where, and check it against what arrived.
 *
 * Worked out from the payments rather than stored: a payout is a fact about money that has
 * already moved, and a ledger row written for every payment would be a second copy of the
 * truth that can drift from the first.
 *
 * Money a fee has not spoken for — no splits, or splits that add up to less than the fee —
 * stays in the main account, and is reported as such rather than dropped.
 */
class SettlementService
{
    /** Collected, but the gateway has not finished with every payment of the day. */
    public const STATUS_AWAITING = 'awaiting';

    /** Every payment is settled, so the day is ready to be transferred to its accounts. */
    public const STATUS_READY = 'ready';

    /** The transfers have been made and the day is done with. */
    public const STATUS_DISBURSED = 'disbursed';

    /**
     * Everything collected in a session, nested the way the year is: session, term, month,
     * day.
     *
     * Each bucket carries how many payments it holds and how many of them the office has
     * marked settled, so a day can say where it has got to without the page counting
     * anything itself. A day also carries its status, and a month how many of its days are
     * still to be paid out.
     *
     * @return array{
     *     totals: array{collections:float,accounts:array<int,float>,it:float,unallocated:float,count:int,settled:int},
     *     accounts: Collection<int,BankAccount>,
     *     terms: array<int,array{
     *         label: string,
     *         totals: array{collections:float,accounts:array<int,float>,it:float,unallocated:float,count:int,settled:int},
     *         months: array<int,array{
     *             label: string,
     *             totals: array{collections:float,accounts:array<int,float>,it:float,unallocated:float,count:int,settled:int},
     *             pending: int,
     *             days: array<int,array{
     *                 key: string,
     *                 date: string,
     *                 label: string,
     *                 status: string,
     *                 disbursed_at: ?Carbon,
     *                 disbursed_by: ?string,
     *                 totals: array{collections:float,accounts:array<int,float>,it:float,unallocated:float,count:int,settled:int},
     *                 transactions: array<int,array{id:int,student:string,amount:float,time:string,settled:bool,settled_by:?string}>
     *             }>
     *         }>
     *     }>
     * }
     */
    public function forSession(AcademicSession $session): array
    {
        $payments = Payment::query()
            ->where('status', PaymentStatus::Successful->value)
            ->whereNotNull('paid_at')
            ->whereHas('invoice', fn ($query) => $query->where('academic_session_id', $session->id))
            // The fee and its splits travel with the invoice, because the query that says
            // which fee a payment is for is the same query that says where it goes. Loading
            // them per payment is what turns a page of two hundred into two hundred queries.
            ->with(['student', 'settler', 'invoice.items.fee.splits'])
            ->orderBy('paid_at')
            ->get();

        // Which days the office has already paid out. Read once for the session rather than
        // asked per day, which would be one query per row of the calendar.
        $disbursed = Disbursement::query()
            ->where('academic_session_id', $session->id)
            ->with('disburser')
            ->get()
            ->mapWithKeys(fn (Disbursement $row): array => [$row->collected_on->toDateString() => $row]);

        $terms = Term::query()->get()->keyBy('id');
        $seenAccounts = [];
        $totals = $this->emptyBucket();
        $byTerm = [];

        foreach ($payments as $payment) {
            $allocation = $this->allocate($payment);
            $amount = round((float) $payment->amount, 2);

            $paidAt = $payment->paid_at;
            $termId = (int) ($payment->invoice?->term_id ?? 0);
            $monthKey = $paidAt->format('Y-m');
            $dayKey = $paidAt->format('Y-m-d');

            $byTerm[$termId] ??= [
                'label' => $terms->get($termId)?->name ?? 'No term',
                'totals' => $this->emptyBucket(),
                'months' => [],
            ];

            $byTerm[$termId]['months'][$monthKey] ??= [
                'label' => $paidAt->format('F Y'),
                'totals' => $this->emptyBucket(),
                'days' => [],
            ];

            $byTerm[$termId]['months'][$monthKey]['days'][$dayKey] ??= [
                'key' => $dayKey,
                'date' => $paidAt->toDateString(),
                'label' => $paidAt->format('j F Y'),
                'totals' => $this->emptyBucket(),
                'transactions' => [],
            ];

            $settled = $payment->isSettled();

            $this->addAllocation($totals, $allocation, $amount, $settled);
            $this->addAllocation($byTerm[$termId]['totals'], $allocation, $amount, $settled);
            $this->addAllocation($byTerm[$termId]['months'][$monthKey]['totals'], $allocation, $amount, $settled);
            $this->addAllocation($byTerm[$termId]['months'][$monthKey]['days'][$dayKey]['totals'], $allocation, $amount, $settled);

            $byTerm[$termId]['months'][$monthKey]['days'][$dayKey]['transactions'][] = [
                'id' => $payment->id,
                'student' => $payment->student?->full_name ?? 'A student',
                'amount' => $amount,
                'time' => $paidAt->format('g:i A'),
                'settled' => $settled,
                'settled_by' => $payment->settler?->name,
            ];

            foreach (array_keys($allocation['accounts']) as $accountId) {
                $seenAccounts[$accountId] = true;
            }
        }

        $accounts = BankAccount::query()->whereIn('id', array_keys($seenAccounts))->get()->keyBy('id');

        // Terms in the order the year runs them, and the months inside each newest first.
        // The month the office is working in is the current one, and the ones below it are
        // history — reading down from the top is reading back in time.
        $termsOut = [];

        foreach ($byTerm as $termId => $term) {
            // Ordered by the key rather than the label: "April" sorts before "August" as
            // words, which is the wrong month.
            krsort($term['months']);

            foreach ($term['months'] as &$month) {
                krsort($month['days']);

                // A month says how much of it is still to be paid out, which is the number
                // the office works down. A day that has been disbursed is done with,
                // whatever state its payments are in.
                $pending = 0;

                foreach ($month['days'] as &$day) {
                    $row = $disbursed->get($day['key']);

                    $day['status'] = $this->statusFor($day['totals'], $row !== null);
                    $day['disbursed_at'] = $row?->disbursed_at;
                    $day['disbursed_by'] = $row?->disburser?->name;

                    if ($day['status'] !== self::STATUS_DISBURSED) {
                        $pending++;
                    }
                }

                unset($day);

                $month['days'] = array_values($month['days']);
                $month['pending'] = $pending;
            }

            unset($month);

            $term['months'] = array_values($term['months']);
            $termsOut[] = ['id' => $termId] + $term;
        }

        usort($termsOut, function (array $a, array $b) use ($terms): int {
            return ($terms->get($a['id'])?->position ?? PHP_INT_MAX)
                <=> ($terms->get($b['id'])?->position ?? PHP_INT_MAX);
        });

        return [
            'totals' => $totals,
            'accounts' => $accounts,
            'terms' => $termsOut,
        ];
    }

    /**
     * Where one payment goes.
     *
     * A payment is attributed to the fees its bill charges for, in proportion to how much
     * of the bill each accounts for. The ordinary case is one fee per bill, and then the
     * whole payment is that fee's. The platform's per-transaction charge is kept back once
     * per payment, on the fee that makes up most of the bill.
     *
     * The splits are paid in order, each taking its own amount and stopping when the money
     * runs out, so a fee whose splits add up to more than the payment pays the first ones in
     * full rather than sharing the shortfall out. Whatever the splits do not claim stays in
     * the main account.
     *
     * @return array{accounts:array<int,float>,it:float,unallocated:float}
     */
    protected function allocate(Payment $payment): array
    {
        $amount = round((float) $payment->amount, 2);
        $items = $payment->invoice?->items ?? collect();

        // The fees this bill charges for, and how much of it each accounts for. A line
        // that names no fee is money the school has not decided to divide, so it is left
        // out here rather than guessed at.
        $byFee = $items
            ->filter(fn ($item): bool => $item->fee_id !== null && $item->fee !== null)
            ->groupBy('fee_id')
            ->map(fn (Collection $lines): float => round((float) $lines->sum('amount'), 2))
            ->sortDesc();

        if ($byFee->isEmpty()) {
            return ['accounts' => [], 'it' => 0.0, 'unallocated' => $amount];
        }

        $primaryFeeId = (int) $byFee->keys()->first();
        $lineTotal = round((float) $byFee->sum(), 2);

        $accounts = [];
        $it = 0.0;
        $unallocated = 0.0;
        $left = $amount;
        $remainingFees = $byFee->count();

        foreach ($byFee as $feeId => $feeLines) {
            $remainingFees--;

            // Everything that is left belongs to the last fee, so rounding cannot lose a
            // kobo between the shares.
            $share = $remainingFees === 0
                ? round($left, 2)
                : round($amount * ($feeLines / $lineTotal), 2);

            $share = round(min($share, $left), 2);
            $left = round($left - $share, 2);

            /** @var Fee $fee */
            $fee = $items->firstWhere('fee_id', $feeId)->fee;

            // Kept back once, on the fee the payment is mostly for.
            if ((int) $feeId === $primaryFeeId) {
                $it = round(min($fee->itMaintenanceFee(), $share), 2);
            }

            $distributable = round($share - $it, 2);

            if ($distributable <= 0) {
                $unallocated = round($unallocated + $share, 2);

                continue;
            }

            $pool = $distributable;

            foreach ($fee->splits as $split) {
                $take = round(max(min(round((float) $split->amount, 2), $pool), 0), 2);

                if ($split->bank_account_id === null) {
                    // A share whose account has been removed has nowhere to go, and is
                    // reported as staying in the main account rather than silently lost.
                    $unallocated = round($unallocated + $take, 2);
                } else {
                    $accounts[$split->bank_account_id] = round(
                        ($accounts[$split->bank_account_id] ?? 0) + $take,
                        2,
                    );
                }

                $pool = round($pool - $take, 2);
            }

            $unallocated = round($unallocated + max($pool, 0), 2);
        }

        return [
            'accounts' => $accounts,
            'it' => $it,
            'unallocated' => round($unallocated + max($left, 0), 2),
        ];
    }

    /**
     * Where a day has got to on the way from collected to paid out.
     *
     * The three states are the money's journey rather than the software's: it arrives, the
     * gateway finishes with it, and somebody transfers it to the accounts. A day is only
     * ready once every one of its payments is settled, because a day half-settled is a
     * transfer that would have to be worked out again tomorrow.
     *
     * @param  array{count:int,settled:int}  $totals
     */
    protected function statusFor(array $totals, bool $disbursed): string
    {
        if ($disbursed) {
            return self::STATUS_DISBURSED;
        }

        if ($totals['count'] > 0 && $totals['settled'] === $totals['count']) {
            return self::STATUS_READY;
        }

        return self::STATUS_AWAITING;
    }

    /**
     * @return array{collections:float,accounts:array<int,float>,it:float,unallocated:float,count:int,settled:int}
     */
    protected function emptyBucket(): array
    {
        return [
            'collections' => 0.0,
            'accounts' => [],
            'it' => 0.0,
            'unallocated' => 0.0,
            'count' => 0,
            'settled' => 0,
        ];
    }

    /**
     * @param  array{collections:float,accounts:array<int,float>,it:float,unallocated:float,count:int,settled:int}  $bucket
     * @param  array{accounts:array<int,float>,it:float,unallocated:float}  $allocation
     */
    protected function addAllocation(array &$bucket, array $allocation, float $amount, bool $settled): void
    {
        $bucket['collections'] = round($bucket['collections'] + $amount, 2);
        $bucket['it'] = round($bucket['it'] + $allocation['it'], 2);
        $bucket['unallocated'] = round($bucket['unallocated'] + $allocation['unallocated'], 2);
        $bucket['count']++;
        $bucket['settled'] += $settled ? 1 : 0;

        foreach ($allocation['accounts'] as $accountId => $paid) {
            $bucket['accounts'][$accountId] = round(($bucket['accounts'][$accountId] ?? 0) + $paid, 2);
        }
    }
}
