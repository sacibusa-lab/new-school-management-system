<?php

namespace App\Models;

use App\Enums\ExamStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exam extends Model
{
    protected $fillable = [
        'title', 'academic_session_id', 'level_id', 'exam_date', 'starts_at', 'venue',
        'cutoff_mark', 'status', 'instructions', 'results_locked', 'created_by', 'published_at',
        'resit_of_exam_id', 'is_resit', 'resit_round',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'status' => ExamStatus::class,
            'cutoff_mark' => 'decimal:2',
            'results_locked' => 'boolean',
            'published_at' => 'datetime',
            'is_resit' => 'boolean',
            'resit_round' => 'integer',
        ];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(SchoolLevel::class, 'level_id');
    }

    /** The sitting this exam repeats, when this exam is itself a resit. */
    public function resitOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resit_of_exam_id');
    }

    /** Every resit spawned from this sitting. */
    public function resits(): HasMany
    {
        return $this->hasMany(self::class, 'resit_of_exam_id')->orderBy('resit_round');
    }

    /** "2026/2027 Entrance Examination · Resit 2" */
    public function displayTitle(): string
    {
        return $this->is_resit
            ? "{$this->title} · Resit {$this->resit_round}"
            : $this->title;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function examSubjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class)->orderBy('sort_order');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(AdmissionDecision::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(ScoreImport::class);
    }

    /** Set of applicants who sat this exam. */
    public function applicants()
    {
        return Applicant::query()
            ->whereIn('id', $this->scores()->select('applicant_id'))
            ->orderBy('registration_number');
    }

    public function totalMarks(): float
    {
        return (float) $this->examSubjects()->sum('total_marks');
    }

    public function isEditable(): bool
    {
        return ! $this->results_locked
            && ! in_array($this->status, [ExamStatus::Published], true);
    }
}
