<?php

namespace App\Models;

use App\Enums\PromotionAction;
use Database\Factories\StudentPromotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The decision taken about one student at the end of one session.
 *
 * The student row says where they are now; this says how they got there, and is
 * kept so the year can be read back after the fact.
 */
class StudentPromotion extends Model
{
    /** @use HasFactory<StudentPromotionFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id',
        'from_academic_session_id',
        'to_academic_session_id',
        'from_school_class_id',
        'to_school_class_id',
        'action',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => PromotionAction::class,
            'decided_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function fromSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'from_academic_session_id');
    }

    public function toSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'to_academic_session_id');
    }

    public function fromClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'from_school_class_id');
    }

    public function toClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'to_school_class_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
