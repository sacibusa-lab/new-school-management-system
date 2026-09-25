<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermResultItem extends Model
{
    protected $fillable = [
        'term_result_id', 'subject_id', 'ca_score', 'exam_score', 'total_score',
        'subject_average', 'highest_in_class', 'lowest_in_class',
        'grade', 'remark', 'subject_position', 'is_absent',
    ];

    protected function casts(): array
    {
        return [
            'ca_score' => 'decimal:2',
            'exam_score' => 'decimal:2',
            'total_score' => 'decimal:2',
            'subject_average' => 'decimal:2',
            'highest_in_class' => 'decimal:2',
            'lowest_in_class' => 'decimal:2',
            'subject_position' => 'integer',
            'is_absent' => 'boolean',
        ];
    }

    public function termResult(): BelongsTo
    {
        return $this->belongsTo(TermResult::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
