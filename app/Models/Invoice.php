<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number', 'student_id', 'academic_session_id', 'term_id', 'fee_structure_id',
        'subtotal', 'discount', 'total', 'amount_paid', 'balance', 'status',
        'due_date', 'issued_at', 'is_auto_generated', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'due_date' => 'date',
            'issued_at' => 'datetime',
            'is_auto_generated' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Re-derive the money columns from the ledger. Always call this after
     * touching items or payments — never hand-set `balance`.
     */
    public function recalculate(): self
    {
        $subtotal = (float) $this->items()->sum('amount');
        $discount = (float) $this->discount;
        $total = max(round($subtotal - $discount, 2), 0);

        $paid = (float) $this->payments()
            ->where('status', \App\Enums\PaymentStatus::Successful->value)
            ->sum('amount');

        $pastDue = $this->due_date !== null && $this->due_date->isPast() && $paid < $total;

        $this->forceFill([
            'subtotal' => $subtotal,
            'total' => $total,
            'amount_paid' => round($paid, 2),
            'balance' => round(max($total - $paid, 0), 2),
            'status' => InvoiceStatus::derive($total, $paid, $pastDue),
        ])->save();

        return $this;
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    public function scopeOutstanding($query)
    {
        return $query->whereIn('status', [
            InvoiceStatus::Unpaid->value,
            InvoiceStatus::Partial->value,
            InvoiceStatus::Overdue->value,
        ]);
    }

    public function reference(): string
    {
        return $this->invoice_number;
    }
}
