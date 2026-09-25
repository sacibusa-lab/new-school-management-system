<?php

namespace App\Models;

use App\Enums\ScoreImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One parsed line from a scoresheet, held for human review.
 *
 * Nothing reaches the `scores` table until an operator commits the row.
 */
class ScoreImportRow extends Model
{
    protected $fillable = [
        'score_import_id', 'row_number', 'raw',
        'raw_identifier', 'raw_name', 'raw_subject', 'raw_score',
        'exam_subject_id', 'matched_applicant_id', 'match_confidence',
        'status', 'message', 'confidence',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'raw_score' => 'decimal:2',
            'status' => ScoreImportRowStatus::class,
            'match_confidence' => 'decimal:2',
            'confidence' => 'decimal:2',
            'row_number' => 'integer',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ScoreImport::class, 'score_import_id');
    }

    public function examSubject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class);
    }

    public function matchedApplicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'matched_applicant_id');
    }

    public function isReady(): bool
    {
        return $this->status->isWritable() && $this->matched_applicant_id !== null;
    }

    /**
     * Percentage confidence for display, normalised to 0-100.
     */
    public function confidencePercent(): ?int
    {
        $value = $this->confidence ?? $this->match_confidence;

        if ($value === null) {
            return null;
        }

        $value = (float) $value;

        // Accept either 0-1 or 0-100 conventions.
        return (int) round($value <= 1 ? $value * 100 : $value);
    }
}
