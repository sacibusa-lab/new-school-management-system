<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The admin/exam-officer controlled pass mark for a session + level.
 */
class AdmissionSetting extends Model
{
    protected $fillable = [
        'academic_session_id', 'level_id', 'cutoff_mark', 'subject_pass_mark',
        'available_slots', 'require_all_subjects', 'auto_admit', 'is_active', 'set_by',
    ];

    protected function casts(): array
    {
        return [
            'cutoff_mark' => 'decimal:2',
            'subject_pass_mark' => 'decimal:2',
            'available_slots' => 'integer',
            'require_all_subjects' => 'boolean',
            'auto_admit' => 'boolean',
            'is_active' => 'boolean',
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

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    public static function for(int $sessionId, int $levelId): ?self
    {
        return static::query()
            ->where('academic_session_id', $sessionId)
            ->where('level_id', $levelId)
            ->where('is_active', true)
            ->first();
    }
}
