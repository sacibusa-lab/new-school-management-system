<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSubject extends Model
{
    protected $fillable = ['exam_id', 'subject_id', 'total_marks', 'pass_mark', 'weight', 'sort_order', 'is_compulsory'];

    protected function casts(): array
    {
        return [
            'total_marks' => 'decimal:2',
            'pass_mark' => 'decimal:2',
            'weight' => 'integer',
            'sort_order' => 'integer',
            'is_compulsory' => 'boolean',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    /** Effective pass mark: explicit, else the exam-wide cutoff, else 40%. */
    public function effectivePassMark(): float
    {
        return (float) ($this->pass_mark
            ?? $this->exam?->cutoff_mark
            ?? 40);
    }

    public function label(): string
    {
        return $this->subject?->name ?? 'Subject';
    }

    /**
     * Convert a raw score into a percentage of this subject's total.
     */
    public function toPercentage(float|int|null $raw): ?float
    {
        if ($raw === null || (float) $this->total_marks <= 0) {
            return null;
        }

        return round(((float) $raw / (float) $this->total_marks) * 100, 2);
    }
}
