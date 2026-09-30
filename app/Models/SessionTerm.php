<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a term runs in one session.
 *
 * A term is the same term in every session — First Term is First Term — but the
 * dates it runs are not: First Term is 1 Sep to 18 Dec in 2026/2027 and the same
 * months a year later in 2027/2028. Keeping the dates on the term would mean
 * starting the new session rewrote what last session's report cards said, so they
 * live here, one row per term per session.
 *
 * The row exists for every pair from the moment the session or the term does, so
 * a session always has all of its terms and this is simply where their dates are
 * put when somebody knows them.
 */
class SessionTerm extends Model
{
    protected $fillable = ['academic_session_id', 'term_id', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function academicSession(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** "1 Sep 2026 to 18 Dec 2026", or null when neither end is known. */
    public function describe(): ?string
    {
        if (! $this->starts_on && ! $this->ends_on) {
            return null;
        }

        return ($this->starts_on?->format('j M Y') ?? '—')
            . ' to '
            . ($this->ends_on?->format('j M Y') ?? '—');
    }
}
