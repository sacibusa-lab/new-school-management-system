<?php

namespace App\Services\Fees;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Scholarship;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Approving what a student is let off.
 *
 * An award is money: approving one moves the amount off the student's bills, and it
 * is written onto the invoices themselves rather than computed at read time, because
 * a bill a parent has been handed must not change its numbers because somebody
 * approved something later without it being visible on the bill.
 *
 * Where the award covers more than one invoice it comes off the oldest first, the
 * same rule incoming payments follow — and it never comes off twice, so approving the
 * same award again cannot discount a second time.
 */
class ScholarshipService
{
    /**
     * Approve an award and write it off the student's bills.
     *
     * @return array{invoices:int,discounted:float}
     */
    public function approve(Scholarship $award, User $approver): array
    {
        return DB::transaction(function () use ($award, $approver) {
            $award->update([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            $remaining = round((float) $award->amount, 2);
            $touched = 0;

            // Oldest first, and only the invoices with something still owing: there is
            // nothing to take off a bill that is already settled.
            foreach ($award->invoices()->orderBy('issued_at')->orderBy('id')->get() as $invoice) {
                if ($remaining <= 0) {
                    break;
                }

                $owedBefore = round((float) $invoice->total, 2);
                $room = round($owedBefore - (float) $invoice->discount, 2);

                if ($room <= 0) {
                    continue;
                }

                $applied = min($remaining, $room);
                $remaining = round($remaining - $applied, 2);

                $invoice->discount = round((float) $invoice->discount + $applied, 2);
                $invoice->save();
                $invoice->recalculate();

                $touched++;
            }

            return ['invoices' => $touched, 'discounted' => round((float) $award->amount - $remaining, 2)];
        });
    }

    /**
     * Take an award back off the bills it was written onto.
     *
     * Used when an award is withdrawn or rejected after the fact. The discount is
     * only ever reduced back to nothing, so a bill cannot end up discounted by more
     * than the award that was taken away.
     */
    public function withdraw(Scholarship $award): int
    {
        return DB::transaction(function () use ($award) {
            $remaining = round((float) $award->amount, 2);
            $touched = 0;

            foreach ($award->invoices()->orderByDesc('issued_at')->orderByDesc('id')->get() as $invoice) {
                if ($remaining <= 0) {
                    break;
                }

                $existing = round((float) $invoice->discount, 2);

                if ($existing <= 0) {
                    continue;
                }

                $removed = min($remaining, $existing);
                $remaining = round($remaining - $removed, 2);

                $invoice->discount = round($existing - $removed, 2);
                $invoice->save();
                $invoice->recalculate();

                $touched++;
            }

            $award->update(['status' => 'rejected', 'approved_by' => null, 'approved_at' => null]);

            return $touched;
        });
    }

    /**
     * What an award would come off, without writing anything — what the office is
     * shown before they approve it.
     */
    public function preview(Scholarship $award): float
    {
        return (float) $award->invoices()
            ->whereIn('status', [InvoiceStatus::Unpaid->value, InvoiceStatus::Partial->value, InvoiceStatus::Overdue->value])
            ->sum('balance');
    }
}
