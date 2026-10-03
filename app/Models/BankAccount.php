<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An account of the school's that fees are paid into.
 *
 * One of them is the primary: the account printed on a letter when nobody says
 * otherwise. The rest exist because a school has more than one — fees, PTA, boarding.
 */
class BankAccount extends Model
{
    protected $fillable = [
        'label', 'bank_name', 'bank_code', 'account_number',
        'account_name', 'sub_account_code', 'is_primary', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The account to print when nobody has chosen one. */
    public static function primary(): ?self
    {
        return static::query()->active()->orderByDesc('is_primary')->orderBy('id')->first();
    }

    /** How it reads on a letter. */
    public function label(): string
    {
        return "{$this->bank_name} {$this->account_number}";
    }
}
