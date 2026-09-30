<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A term of the school year.
 *
 * Universal: there is one First Term, one Second Term, one Third Term, and every
 * academic session runs them. A term used to be a row of its own per session,
 * which meant the office rebuilt the same three terms every year and the same
 * term was a different record depending on when you looked.
 *
 * *When* a term runs is a different question, answered per session in
 * `session_terms` — see {@see SessionTerm} — so starting a new session does not
 * rewrite the dates a report card was signed against. Which term the school is
 * *in* is the one flag here, and it is single-valued because exactly one session
 * is current.
 */
class Term extends Model
{
    protected $fillable = ['name', 'position', 'is_current'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    /** The sessions this term runs in — every one of them. */
    public function academicSessions(): BelongsToMany
    {
        return $this->belongsToMany(AcademicSession::class, 'session_terms')
            ->withPivot(['starts_on', 'ends_on'])
            ->withTimestamps();
    }

    public function sessionTerms(): HasMany
    {
        return $this->hasMany(SessionTerm::class);
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * The dates this term runs in one session, if anybody has set them.
     *
     * A term on its own cannot know when it runs, so asking without a session is
     * answered with null rather than a guess.
     */
    public function datesIn(?AcademicSession $session): ?SessionTerm
    {
        if ($session === null) {
            return null;
        }

        return $this->relationLoaded('sessionTerms')
            ? $this->sessionTerms->firstWhere('academic_session_id', $session->id)
            : $this->sessionTerms()->where('academic_session_id', $session->id)->first();
    }

    /** "First Term", or "First Term — 2026/2027" when a session is named. */
    public function label(?AcademicSession $session = null): string
    {
        return $session ? "{$this->name} — {$session->name}" : $this->name;
    }

    /** The term the school is in. One session is current, so one term is too. */
    public static function current(): ?self
    {
        return static::query()->where('is_current', true)->first();
    }

    /** The three terms a Nigerian school runs, in order. */
    public static function standard(): array
    {
        return [1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'];
    }
}
