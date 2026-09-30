<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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

    /**
     * The terms this session runs — which is all of them.
     *
     * A term belongs to every session rather than to one, so this reaches them
     * through `session_terms`, the table that holds *when* each term runs in this
     * particular session. The list is the same for every session; only the dates
     * differ, and a session never has a term missing from it.
     */
    public function terms(): HasManyThrough
    {
        return $this->hasManyThrough(
            Term::class,
            SessionTerm::class,
            'academic_session_id',
            'id',
            'id',
            'term_id',
        )->orderBy('terms.position');
    }

    /** When each term runs in this session. */
    public function sessionTerms(): HasMany
    {
        return $this->hasMany(SessionTerm::class);
    }

    /** The dates a term runs here, if anybody has set them. */
    public function datesFor(Term $term): ?SessionTerm
    {
        return $this->sessionTerms()->where('term_id', $term->id)->first();
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

    /**
     * The term the school is in — or null, if this is not the session it is in.
     *
     * There is one active term and one current session, so a session that is not
     * the current one is not "in" a term at all. Answering with the active term
     * anyway would put this year's term on a past year's invoice.
     */
    public function currentTerm(): ?Term
    {
        return $this->is_current ? Term::current() : null;
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
