<?php

namespace App\Models;

use Database\Factories\FeeClassOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a year group is charged instead of the fee's default amount.
 *
 * Per year group rather than per class arm: senior school pays more for tuition, and every
 * arm of the senior school pays it — one decision, not one per JSS1A, JSS1B and so on.
 */
class FeeClassOverride extends Model
{
    /** @use HasFactory<FeeClassOverrideFactory> */
    use HasFactory;

    /** An override switched off is a price set up in advance, not a deleted one. */
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive'];

    protected $fillable = ['fee_id', 'level_id', 'amount', 'status'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function fee(): BelongsTo
    {
        return $this->belongsTo(Fee::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(SchoolLevel::class, 'level_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
