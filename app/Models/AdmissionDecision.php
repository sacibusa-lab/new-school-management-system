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

    /**
     * Whether a person set this verdict, rather than the cutoff — or nothing yet.
     *
     * Two tests, not one. A merit row the cutoff has not judged is stored Pending
     * with `is_auto` at its default of false, exactly like a row a person decided
     * by hand, so `is_auto` on its own cannot tell them apart. Asking also whether
     * a verdict exists is what separates "waiting to be decided" from "decided by
     * hand" — and that difference is the whole reason a recomputation may not
     * quietly overrule the office.
     */
    public function isManuallyDecided(): bool
    {
        return ! $this->is_auto
            && $this->decision !== null
            && $this->decision !== AdmissionDecisionStatus::Pending;
    }

    /** Distance from the cutoff — positive means above the line. */
    public function margin(): float
    {
        return round((float) $this->average_score - (float) $this->cutoff_mark, 2);
    }
}
