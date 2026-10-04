<?php

namespace App\Models;

use Database\Factories\StudentAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money added to or taken off one child's bill for one term.
 *
 * Signed: negative is taken off, positive is added. See the migration for why it is one
 * column rather than two.
 */
class StudentAdjustment extends Model
{
    /** @use HasFactory<StudentAdjustmentFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id', 'academic_session_id', 'term_id', 'amount', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
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

    /** Who decided it. Null if that user has since been removed. */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Money added, rather than taken off. */
    public function isCharge(): bool
    {
        return (float) $this->amount > 0;
    }

    /**
     * How it reads on a slip.
     *
     * A description is required, so this only has to decide the default when the office
     * leaves the box alone — and then one of the two words is right.
     */
    public function label(): string
    {
        $description = trim((string) $this->description);

        return $description === ''
            ? ($this->isCharge() ? 'Additional charge' : 'Discount')
            : $description;
    }
}
