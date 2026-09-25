<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicSession extends Model
{
    protected $fillable = ['name', 'starts_on', 'ends_on', 'is_current', 'is_admission_open'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'is_admission_open' => 'boolean',
        ];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class)->orderBy('position');
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function admissionSettings(): HasMany
    {
        return $this->hasMany(AdmissionSetting::class);
    }

    public function currentTerm(): ?Term
    {
        return $this->terms()->where('is_current', true)->first();
    }

    /**
     * The academic year portion, used for admission numbers: "2026/2027" => 2026.
     */
    public function startYear(): int
    {
        return (int) str($this->name)->before('/')->trim()->value() ?: (int) $this->created_at?->year;
    }

    public static function current(): ?self
    {
        return static::query()->where('is_current', true)->first()
            ?? static::query()->orderByDesc('starts_on')->first();
    }
}
