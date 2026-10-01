<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card a parent buys to check one term's result.
 *
 * The PIN is stored as digits and printed in groups of four, so what is on the card
 * is easy to read across a counter and easy to type on a phone.
 */
class ResultPin extends Model
{
    protected $fillable = [
        'student_id', 'academic_session_id', 'term_id', 'pin', 'generated_by', 'used_at',
    ];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
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

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    /** The PIN as it is printed: 4839 2176 5504. */
    public function grouped(): string
    {
        return trim(chunk_split($this->pin, 4, ' '));
    }
}
