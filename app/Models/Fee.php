<?php

namespace App\Models;

use Database\Factories\FeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fee the school charges.
 *
 * The catalogue entry, not the bill: what it is called, how often it comes round, which
 * session it belongs to, and what it costs by default. The amount is a default rather
 * than a price — a year group billed something else does not change this row.
 */
class Fee extends Model
{
    /** @use HasFactory<FeeFactory> */
    use HasFactory;

    /** How often a fee comes round. Keys are what is stored; labels are what is read. */
    public const CYCLES = [
        'termly' => 'Termly',
        'annually' => 'Annually',
        'one-time' => 'One-time',
    ];

    /**
     * The terms a fee runs in, in the order the year does.
     *
     * One description of each term — the column that switches it on, the column that
     * prices it, and how it is written — so the checkbox, the amount beside it and the
     * question "is this fee active in the second term" cannot disagree about which
     * column is which.
     */
    public const TERMS = [
        1 => ['active' => 'first_term_active', 'amount' => 'first_term_amount', 'label' => 'First term', 'short' => 'First'],
        2 => ['active' => 'second_term_active', 'amount' => 'second_term_amount', 'label' => 'Second term', 'short' => 'Second'],
        3 => ['active' => 'third_term_active', 'amount' => 'third_term_amount', 'label' => 'Third term', 'short' => 'Third'],
    ];

    /**
     * What the platform keeps back from a transaction when a fee says nothing of its own.
     *
     * A flat per-transaction charge rather than a share: the platform's cost of moving the
     * money does not grow with the size of the fee, so a percentage would take more for
     * doing the same work.
     */
    public const DEFAULT_IT_MAINTENANCE_FEE = 100.00;

    protected $fillable = [
        'title', 'description', 'revenue_code', 'cycle', 'academic_session_id', 'amount',
        'it_maintenance_fee',
        'first_term_amount', 'second_term_amount', 'third_term_amount',
        'first_term_active', 'second_term_active', 'third_term_active', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'it_maintenance_fee' => 'decimal:2',
            'first_term_amount' => 'decimal:2',
            'second_term_amount' => 'decimal:2',
            'third_term_amount' => 'decimal:2',
            'first_term_active' => 'boolean',
            'second_term_active' => 'boolean',
            'third_term_active' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Null means the fee applies to every session, which is why the relation is
     * nullable rather than required.
     */
    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    /**
     * Who this fee is divided between, in the order the office set them out. Empty means
     * the whole of it pays into the school's main account, which is the ordinary case
     * rather than a fee nobody has finished.
     */
    public function splits(): HasMany
    {
        return $this->hasMany(FeeSplit::class)->orderBy('position');
    }

    /** What a year group is charged instead of the default amount. */
    public function overrides(): HasMany
    {
        return $this->hasMany(FeeClassOverride::class);
    }

    public function cycleLabel(): string
    {
        return self::CYCLES[$this->cycle] ?? ucfirst((string) $this->cycle);
    }

    /**
     * The terms this fee comes round in, as labels.
     *
     * @return array<int,string>
     */
    public function activeTerms(): array
    {
        $terms = [];

        foreach (self::TERMS as $term) {
            if ($this->{$term['active']}) {
                $terms[] = $term['label'];
            }
        }

        return $terms;
    }

    /**
     * Only a termly fee has terms to be active in. An annual or one-time fee is billed
     * once and the checkboxes mean nothing, so the page says nothing about them.
     */
    public function isTermly(): bool
    {
        return $this->cycle === 'termly';
    }

    /**
     * Is this fee charged in the term at that position in the year?
     *
     * A term the fee says nothing about is charged: a position outside the three is not
     * a reason to stop billing.
     */
    public function isActiveForTerm(int $position): bool
    {
        $column = self::TERMS[$position]['active'] ?? null;

        return $column === null ? true : (bool) $this->{$column};
    }

    /**
     * What this fee costs in the term at that position.
     *
     * A term with no amount of its own falls back to the default. Empty means "the
     * default", not zero — a fee with no term amounts set is not a free fee.
     */
    public function amountForTerm(int $position): string
    {
        $column = self::TERMS[$position]['amount'] ?? null;

        if ($column !== null && $this->{$column} !== null) {
            return (string) $this->{$column};
        }

        return (string) $this->amount;
    }

    /**
     * How much of this fee has been promised to another account.
     *
     * Deliberately able to be less than the fee. The remainder pays into the school's main
     * account, and a split that adds up to the whole fee is the uncommon case.
     */
    public function splitTotal(): float
    {
        return round((float) $this->splits->sum('amount'), 2);
    }

    /** What is left of the fee after the splits, which pays into the main account. */
    public function unsplitAmount(): float
    {
        return round(max((float) $this->amount - $this->splitTotal(), 0), 2);
    }

    /**
     * What the platform keeps back from each transaction before the rest is divided.
     *
     * An empty column means the default rather than nothing: a fee that never said
     * otherwise is still maintained by the platform, and a school that has not thought
     * about it is not thereby opting out of paying for it.
     */
    public function itMaintenanceFee(): float
    {
        return round((float) ($this->it_maintenance_fee ?? self::DEFAULT_IT_MAINTENANCE_FEE), 2);
    }

    /**
     * The active terms in one short line, for the row of a list.
     *
     * "All three terms" rather than naming them: a fee that comes round every term is the
     * ordinary case and does not need three words spent on it. Naming them is what the
     * unusual fee needs.
     */
    public function termsSummary(): string
    {
        $active = [];

        foreach (self::TERMS as $term) {
            if ($this->{$term['active']}) {
                $active[] = $term['short'];
            }
        }

        return match (count($active)) {
            0 => 'No term ticked',
            3 => 'All three terms',
            default => implode(' · ', $active),
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
