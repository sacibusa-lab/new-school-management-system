<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The bank account number a student's fees are paid into.
 *
 * One per child, and it stays theirs: a parent saves it once, and every transfer
 * afterwards is matched to that child by the account the money landed in. Nobody
 * has to quote a reference, and nobody has to reconcile a bank statement by hand.
 */
class StudentVirtualAccount extends Model
{
    protected $fillable = [
        'student_id', 'customer_code', 'bank_name', 'account_number',
        'account_name', 'account_slug', 'provider', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** How the office reads it out to a parent over the phone. */
    public function label(): string
    {
        return trim("{$this->bank_name} {$this->account_number}");
    }
}
