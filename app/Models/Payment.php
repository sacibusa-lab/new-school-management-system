<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'receipt_number', 'invoice_id', 'student_id', 'amount', 'method', 'reference',
        'gateway', 'status', 'paid_at', 'settled_at', 'settled_by', 'notes', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentStatus::class,
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Who said the money for this payment had been moved. */
    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    /**
     * Whether the office has moved this money.
     *
     * A payment that was collected and never marked settled is not an error — it is one
     * waiting to be transferred — so this is asked rather than assumed from anything else
     * about the row.
     */
    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    public function methodLabel(): string
    {
        return self::labelFor($this->method);
    }

    /**
     * The human name for a method, without needing a payment to ask.
     *
     * Kept here rather than in whichever screen needs it so a method is spelled the same
     * way on a receipt, in the day book and in the breakdown on the dashboard.
     */
    public static function labelFor(?string $method): string
    {
        return match ($method) {
            'cash' => 'Cash',
            'bank_transfer' => 'Bank transfer',
            'card' => 'Card',
            'gateway' => 'Online payment',
            'cheque' => 'Cheque',
            null, '' => 'Not recorded',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }
}
