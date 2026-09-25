<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradeScale extends Model
{
    protected $fillable = ['name', 'min_score', 'max_score', 'grade', 'remark', 'points'];

    protected function casts(): array
    {
        return [
            'min_score' => 'decimal:2',
            'max_score' => 'decimal:2',
            'points' => 'integer',
        ];
    }

    /**
     * Resolve a percentage to its grade row.
     */
    public static function gradeFor(float $score, ?string $scale = 'Default'): ?self
    {
        return static::query()
            ->when($scale, fn ($q) => $q->where('name', $scale))
            ->where('min_score', '<=', $score)
            ->where('max_score', '>=', $score)
            ->orderByDesc('min_score')
            ->first();
    }

    public static function gradeLetterFor(float $score): ?string
    {
        return static::gradeFor($score)?->grade;
    }
}
