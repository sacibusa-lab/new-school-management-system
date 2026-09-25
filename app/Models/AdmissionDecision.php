<?php

namespace App\Models;

use App\Enums\AdmissionDecisionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The computed merit result for one applicant in one exam, plus the verdict.
 */
class AdmissionDecision extends Model
{
    protected $fillable = [
        'applicant_id', 'exam_id', 'total_score', 'average_score', 'highest_score',
        'subjects_offered', 'subjects_passed', 'subjects_failed', 'has_absent',
        'cutoff_mark', 'position', 'position_in_level', 'decision', 'is_auto',
        'remarks', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'total_score' => 'decimal:2',
            'average_score' => 'decimal:2',
            'highest_score' => 'decimal:2',
            'cutoff_mark' => 'decimal:2',
            'subjects_offered' => 'integer',
            'subjects_passed' => 'integer',
            'subjects_failed' => 'integer',
            'has_absent' => 'boolean',
            'position' => 'integer',
            'position_in_level' => 'integer',
            'decision' => AdmissionDecisionStatus::class,
            'is_auto' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function passedCutoff(): bool
    {
        return (float) $this->average_score >= (float) $this->cutoff_mark;
    }

    /** Distance from the cutoff — positive means above the line. */
    public function margin(): float
    {
        return round((float) $this->average_score - (float) $this->cutoff_mark, 2);
    }
}
