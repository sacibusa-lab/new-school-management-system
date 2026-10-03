<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a student has been let off, and by whom.
 *
 * An award is a decision rather than a note: it changes what a family owes, which is
 * why it is approved by somebody and why approving it writes a discount onto the
 * student's bill. Rejecting it changes nothing at all.
 */
class Scholarship extends Model
{
    public const TYPES = [
        'scholarship' => 'Scholarship',
        'bursary' => 'Bursary',
        'staff_ward' => 'Staff ward',
        'sibling' => 'Sibling discount',
    ];

    protected $fillable = [
        'student_id', 'academic_session_id', 'term_id', 'type', 'amount',
        'description', 'status', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_at' => 'datetime',
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

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    /** An award that has not been decided on yet is not money off anything. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * The invoices this award applies to: the student's bills for the session, and
     * for the term where one was named.
     *
     * @return Builder<Invoice>
     */
    public function invoices(): Builder
    {
        return Invoice::query()
            ->where('student_id', $this->student_id)
            ->when($this->academic_session_id, fn ($q) => $q->where('academic_session_id', $this->academic_session_id))
            ->when($this->term_id, fn ($q) => $q->where('term_id', $this->term_id));
    }
}
