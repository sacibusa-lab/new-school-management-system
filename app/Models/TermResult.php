<?php

namespace App\Models;

use App\Enums\ResultStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The report card summary for one student in one term. */
class TermResult extends Model
{
    protected $fillable = [
        'student_id', 'academic_session_id', 'term_id', 'school_class_id',
        'total_score', 'average', 'subjects_count', 'position', 'class_size', 'grade',
        'teacher_remark', 'principal_remark', 'status', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'total_score' => 'decimal:2',
            'average' => 'decimal:2',
            'subjects_count' => 'integer',
            'position' => 'integer',
            'class_size' => 'integer',
            'status' => ResultStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TermResultItem::class);
    }

    public function isPublished(): bool
    {
        return $this->status === ResultStatus::Published;
    }

    public function ordinalPosition(): string
    {
        if (! $this->position) {
            return '—';
        }

        $n = (int) $this->position;
        $suffix = match (true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };

        return $n . $suffix;
    }
}
