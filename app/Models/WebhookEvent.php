<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What the gateway actually said, kept.
 *
 * Paystack retries, so the same event arrives more than once. The unique index on
 * provider, event type and reference is what turns the second arrival into a no-op
 * instead of a second receipt, and the payload is the only record of the raw event —
 * which is what to read when a parent's transfer is not showing on their child's
 * account.
 */
class WebhookEvent extends Model
{
    protected $fillable = [
        'provider', 'event_type', 'reference', 'payload',
        'status', 'error_message', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function markProcessed(): void
    {
        $this->update(['status' => 'processed', 'processed_at' => now()]);
    }

    /**
     * An event we understood and deliberately did nothing about — a charge against
     * an account this school does not hold, say. Kept apart from `failed`, which
     * means we could not finish and somebody should look.
     */
    public function markIgnored(): void
    {
        $this->update(['status' => 'ignored', 'processed_at' => now()]);
    }

    public function markFailed(string $reason): void
    {
        $this->update(['status' => 'failed', 'error_message' => $reason]);
    }
}
