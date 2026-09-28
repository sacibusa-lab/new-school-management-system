<?php

namespace App\Models;

use App\Enums\ImportDriver;
use App\Enums\ScoreImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single uploaded raw scoresheet that is being read into the system.
 */
class ScoreImport extends Model
{
    protected $fillable = [
        'exam_id', 'exam_subject_id', 'original_name', 'file_path', 'mime_type', 'file_size',
        'driver', 'status', 'rows_total', 'rows_matched', 'rows_unmatched',
        'meta', 'error', 'uploaded_by', 'committed_by', 'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'driver' => ImportDriver::class,
            'status' => ScoreImportStatus::class,
            'meta' => 'array',
            'rows_total' => 'integer',
            'rows_matched' => 'integer',
            'rows_unmatched' => 'integer',
            'committed_at' => 'datetime',
            'file_size' => 'integer',
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

    /**
     * The parsed lines.
     *
     * Deliberately UNORDERED. An `orderBy` on the relation leaks into any
     * aggregate built on top of it, and MySQL rejects `ORDER BY row_number` in a
     * `GROUP BY status` count with "not in GROUP BY clause" — which took the whole
     * review screen down. Whoever is displaying a list asks for the order itself.
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ScoreImportRow::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function committer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }

    public function isReviewable(): bool
    {
        return in_array($this->status, [ScoreImportStatus::NeedsReview, ScoreImportStatus::Pending], true);
    }

    public function humanFileSize(): string
    {
        $bytes = (int) $this->file_size;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1) . ' TB';
    }

    /** Recompute the counters shown on the review screen. */
    public function refreshCounters(): void
    {
        $rows = $this->rows();

        $this->forceFill([
            'rows_total' => (clone $rows)->count(),
            'rows_matched' => (clone $rows)->whereIn('status', [
                \App\Enums\ScoreImportRowStatus::Matched->value,
                \App\Enums\ScoreImportRowStatus::Committed->value,
            ])->count(),
            'rows_unmatched' => (clone $rows)->whereIn('status', [
                \App\Enums\ScoreImportRowStatus::Unmatched->value,
                \App\Enums\ScoreImportRowStatus::Ambiguous->value,
                \App\Enums\ScoreImportRowStatus::Invalid->value,
            ])->count(),
        ])->save();
    }
}
