<?php

namespace App\Models;

use Database\Factories\FeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /** The terms a fee can be switched on for, in the order the year runs. */
    public const TERMS = [
        'first_term_active' => 'First term',
        'second_term_active' => 'Second term',
        'third_term_active' => 'Third term',
    ];

    protected $fillable = [
        'title', 'description', 'cycle', 'academic_session_id', 'amount',
        'first_term_active', 'second_term_active', 'third_term_active', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
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

        foreach (self::TERMS as $column => $label) {
            if ($this->{$column}) {
                $terms[] = $label;
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
     * The active terms in one short line, for the row of a list.
     *
     * "All three terms" rather than naming them: a fee that comes round every term is the
     * ordinary case and does not need three words spent on it. Naming them is what the
     * unusual fee needs.
     */
    public function termsSummary(): string
    {
        $active = [];

        foreach (array_keys(self::TERMS) as $column) {
            if ($this->{$column}) {
                $active[] = ucfirst(str_replace('_term_active', '', $column));
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
