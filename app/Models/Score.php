<?php

namespace App\Models;

use App\Enums\ScoreSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Score extends Model
{
    protected $fillable = [
        'exam_id', 'exam_subject_id', 'applicant_id', 'score', 'is_absent',
        'grade', 'source', 'confidence', 'entered_by', 'verified_by', 'verified_at', 'notes',
        'is_resit', 'attempt',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'decimal:2',
            'is_absent' => 'boolean',
            'source' => ScoreSource::class,
            'confidence' => 'decimal:2',
            'verified_at' => 'datetime',
            'is_resit' => 'boolean',
            'attempt' => 'integer',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function examSubject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class);
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /** The row of the same subject this one re-sits, if any. */
    public function hasPriorAttempt(): bool
    {
        return $this->attempt > 1;
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** Machine-read scores start unverified and are flagged in the UI. */
    public function needsVerification(): bool
    {
        return $this->source->needsVerification() && ! $this->isVerified();
    }

    public function percentage(): ?float
    {
        return $this->examSubject?->toPercentage($this->score);
    }
}
