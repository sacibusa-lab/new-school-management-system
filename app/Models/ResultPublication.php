<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gate that decides whether results are visible on the public checker. */
class ResultPublication extends Model
{
    protected $fillable = [
        'academic_session_id', 'term_id', 'school_class_id',
        'is_published', 'published_at', 'published_by',
    ];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
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

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public static function isPublishedFor(int $sessionId, int $termId, ?int $classId = null): bool
    {
        return static::query()
            ->where('academic_session_id', $sessionId)
            ->where('term_id', $termId)
            ->when($classId, fn ($q) => $q->where('school_class_id', $classId))
            ->where('is_published', true)
            ->exists();
    }
}
