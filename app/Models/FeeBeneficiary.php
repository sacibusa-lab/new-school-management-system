<?php

namespace App\Models;

use Database\Factories\FeeBeneficiaryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A share of one fee, paid into one of the school's own bank accounts.
 *
 * The account is referenced rather than its details copied onto this row, so a corrected
 * account number follows through to every fee split to it. A fee with no splits at all has
 * not been left half-finished: the whole of it pays into the main account.
 */
class FeeBeneficiary extends Model
{
    /** @use HasFactory<FeeBeneficiaryFactory> */
    use HasFactory;

    protected $fillable = ['fee_id', 'bank_account_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
