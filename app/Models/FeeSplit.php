<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's share of one fee.
 *
 * The school does not keep all of a fee's money in one place: part of it belongs to the parish,
 * part to the college, and the fee is the instruction that says how much goes where. That
 * instruction is a fixed amount per account rather than a percentage, which is why the total
 * has to be checked against the fee rather than trusted — a split that adds up to more than the
 * fee is paying out money the school never collected.
 *
 * Read by the settlements page, which adds these up per account for a session, a term and a
 * month. Nothing else in the app spends them.
 */
class FeeSplit extends Model
{
    use HasFactory;

    protected $fillable = [
        'fee_id', 'bank_account_id', 'amount', 'position',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * How the destination reads on screen: the account, its number, and the bank it is with.
     */
    public function destination(): string
    {
        $account = $this->bankAccount;

        if ($account === null) {
            return 'An account that has been removed';
        }

        return $account->account_name.' ('.$account->account_number.')'.', '.$account->bank_name;
    }
}
