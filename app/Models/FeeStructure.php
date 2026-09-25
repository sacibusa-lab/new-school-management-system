<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The billable template for a session / term / level. */
class FeeStructure extends Model
{
    protected $fillable = [
        'name', 'academic_session_id', 'term_id', 'level_id',
        'is_active', 'is_default_for_new_students', 'due_days', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default_for_new_students' => 'boolean',
            'due_days' => 'integer',
        ];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(SchoolLevel::class, 'level_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FeeStructureItem::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function total(): float
    {
        return (float) $this->items()->sum('amount');
    }

    /**
     * Pick the structure a newly admitted student should be billed against.
     */
    public static function defaultFor(int $sessionId, ?int $levelId, ?int $termId = null): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where('academic_session_id', $sessionId)
            ->when($levelId, fn ($q) => $q->where(fn ($q2) => $q2->where('level_id', $levelId)->orWhereNull('level_id')))
            ->when($termId, fn ($q) => $q->where(fn ($q2) => $q2->where('term_id', $termId)->orWhereNull('term_id')))
            ->orderByDesc('is_default_for_new_students')
            ->orderByDesc('level_id')
            ->orderByDesc('term_id')
            ->first();
    }
}
