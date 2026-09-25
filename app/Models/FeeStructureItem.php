<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeStructureItem extends Model
{
    protected $fillable = ['fee_structure_id', 'fee_category_id', 'description', 'amount', 'is_compulsory'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_compulsory' => 'boolean',
        ];
    }

    public function feeStructure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FeeCategory::class, 'fee_category_id');
    }

    public function label(): string
    {
        return $this->description ?: ($this->category?->name ?? 'Fee');
    }
}
