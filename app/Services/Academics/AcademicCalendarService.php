<?php

namespace App\Services\Academics;

use App\Models\AcademicSession;
use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\Assessment;
use App\Models\Exam;
use App\Models\Invoice;
use App\Models\ResultPublication;
use App\Models\SessionTerm;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The school's calendar: the sessions it runs and the terms within them.
 *
 * Adding is easy. Deleting is the part that needs a home in code, because every
 * foreign key pointing at a session or a term is ON DELETE CASCADE — applicants,
 * students, examinations, invoices, assessments, term results and published
 * results all vanish silently with the session they belong to. A single DELETE is
 * therefore a data-loss trap, and the guard cannot be the database's.
 *
 * So nothing with anything written against it may be deleted, and the refusal
 * says what is in the way. Only a session or term that has never been used can
 * go, which is exactly the mistake this is here to catch: "2027/2028" created by
 * accident, or a term added twice.
 */
class AcademicCalendarService
{
    /**
    /**
     * Everything that would be destroyed with a session. The order of this array
     * is the order the office reads about it.
     */
    private const SESSION_DEPENDENTS = [
        'student' => Student::class,
        'applicant' => Applicant::class,
        'exam' => Exam::class,
        'invoice' => Invoice::class,
        'assessment' => Assessment::class,
        'term_result' => TermResult::class,
        'result_publication' => ResultPublication::class,
    ];

    private const TERM_DEPENDENTS = [
        'assessment' => Assessment::class,
        'term_result' => TermResult::class,
        'result_publication' => ResultPublication::class,
    ];

    /** How each counted thing is named: one of it, and many of it. */
    private const LABELS = [
        'student' => ['student', 'students'],
        'applicant' => ['applicant', 'applicants'],
        'exam' => ['examination', 'examinations'],
        'invoice' => ['invoice', 'invoices'],
        'assessment' => ['assessment', 'assessments'],
        'term_result' => ['term result', 'term results'],
        'result_publication' => ['published result', 'published results'],
    ];

    /* ------------------------------------------------------------------ */
    /* Sessions                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Create a session, and give it every term the school runs.
     *
     * The terms are not created here: they exist already, and a new session simply
     * joins them. What is created is one row per term for this session, which is
     * where that term's dates will go — empty until somebody knows them.
     */
    public function addSession(
        string $name,
        ?string $startsOn = null,
        ?string $endsOn = null,
        ?User $actor = null,
    ): AcademicSession {
        return DB::transaction(function () use ($name, $startsOn, $endsOn, $actor) {
            $this->ensureStandardTerms();

            $session = AcademicSession::create([
                'name' => $name,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                // A new session is not where the school is; moving to it is a
                // separate, deliberate act.
                'is_current' => false,
                'is_admission_open' => false,
            ]);

            $this->attachEveryTermTo($session);

            $this->log($session, sprintf('Added the academic session %s', $session->name), $actor);

            return $session;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Terms — one set, shared by every session                            */
    /* ------------------------------------------------------------------ */

    /**
     * Add a term to the school.
     *
     * It belongs to every session from the moment it exists, so a row is created
     * for it in each one. Dates, if given, are set for the session named: a term
     * added while the school is in 2026/2027 is almost always being scheduled
     * there, and the form asks which session it is for.
     *
     * @throws RuntimeException when the position is already taken
     */
    public function addTerm(
        string $name,
        int $position,
        ?AcademicSession $datesIn = null,
        ?string $startsOn = null,
        ?string $endsOn = null,
        ?User $actor = null,
    ): Term {
        return DB::transaction(function () use ($name, $position, $datesIn, $startsOn, $endsOn, $actor) {
            if (Term::query()->where('position', $position)->exists()) {
                throw new RuntimeException(sprintf(
                    'The school already has a term in position %d. Terms are the same for every session, so a new one has to take a free position.',
                    $position,
                ));
            }

            $term = Term::create([
                'name' => $name,
                'position' => $position,
                'is_current' => false,
            ]);

            // A term is for every session, so it starts with a row in each of them.
            foreach (AcademicSession::query()->pluck('id') as $sessionId) {
                SessionTerm::create([
                    'academic_session_id' => $sessionId,
                    'term_id' => $term->id,
                    'starts_on' => $sessionId === $datesIn?->id ? $startsOn : null,
                    'ends_on' => $sessionId === $datesIn?->id ? $endsOn : null,
                ]);
            }

            $this->log($term, sprintf('Added the term %s, for every session', $term->name), $actor);

            return $term;
        });
    }

    /**
     * Record when a term runs in one session.
     *
     * The dates are per session, not per term: First Term is September in both
     * years, and setting next year's must not touch this year's.
     */
    public function setTermDates(
        AcademicSession $session,
        Term $term,
        ?string $startsOn,
        ?string $endsOn,
        ?User $actor = null,
    ): SessionTerm {
        $dates = SessionTerm::updateOrCreate(
            ['academic_session_id' => $session->id, 'term_id' => $term->id],
            ['starts_on' => $startsOn, 'ends_on' => $endsOn],
        );

        $this->log(
            $term,
            sprintf('Set %s dates for %s: %s', $term->name, $session->name, $dates->describe() ?? 'cleared'),
            $actor,
        );

        return $dates;
    }

    /**
     * Make sure the three terms a Nigerian school runs exist. Once, ever.
     *
     * @return array<int,string> the names created, empty if they were already there
     */
    public function ensureStandardTerms(): array
    {
        $created = [];

        foreach (Term::standard() as $position => $name) {
            if (Term::query()->where('position', $position)->exists()) {
                continue;
            }

            Term::create(['name' => $name, 'position' => $position, 'is_current' => false]);
            $created[] = $name;
        }

        return $created;
    }

    /** Give a session a row for every term, so none is ever missing from it. */
    public function attachEveryTermTo(AcademicSession $session): void
    {
        foreach (Term::query()->pluck('id') as $termId) {
            SessionTerm::firstOrCreate([
                'academic_session_id' => $session->id,
                'term_id' => $termId,
            ]);
        }
    }

    /** The lowest position no term is using. */
    public function nextTermPosition(): int
    {
        $taken = Term::query()->pluck('position')->all();
        $position = 1;

        while (in_array($position, $taken, true)) {
            $position++;
        }

        return $position;
    }

    /* ------------------------------------------------------------------ */
    /* Deleting                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Delete a session, or refuse and say why.
     *
     * @throws RuntimeException when the session is the current one, or anything is written against it
     */
    public function deleteSession(AcademicSession $session, ?User $actor = null): void
    {
        if ($session->is_current) {
            throw new RuntimeException(sprintf(
                '%s is the session the school is in. Move the school to another session first — deleting the one it is in would leave every screen without an answer.',
                $session->name,
            ));
        }

        if (($blocked = $this->blockers($session)) !== []) {
            throw new RuntimeException(sprintf(
                '%s cannot be deleted: %s %s written against it, and deleting it would take %s with it. Remove those first.',
                $session->name,
                $this->describe($blocked),
                count($blocked) === 1 ? 'is' : 'are',
                count($blocked) === 1 ? 'it' : 'them',
            ));
        }

        $name = $session->name;

        // The session goes; the terms stay, because they never belonged to it. What
        // goes with it is this session's row of dates for each of them.
        $session->delete();

        ActivityLog::record(
            'academic.session',
            null,
            sprintf('Deleted the academic session %s', $name),
            ['module' => 'settings'],
        );
    }

    /**
     * Delete a term, or refuse and say why.
     *
     * @throws RuntimeException when the term is the active one, or marks are written against it
     */
    public function deleteTerm(Term $term, ?User $actor = null): void
    {
        if ($term->is_current) {
            throw new RuntimeException(sprintf(
                '%s is the active term. Set another term for the school first.',
                $term->name,
            ));
        }

        if (($blocked = $this->termBlockers($term)) !== []) {
            throw new RuntimeException(sprintf(
                '%s cannot be deleted: %s %s written against it. Remove those first.',
                $term->name,
                $this->describe($blocked),
                count($blocked) === 1 ? 'is' : 'are',
            ));
        }

        $name = $term->name;

        // And with it the dates it had in every session, which is what the cascade
        // on session_terms is for.
        $term->delete();

        ActivityLog::record(
            'academic.term',
            null,
            sprintf('Deleted the term %s', $name),
            ['module' => 'settings'],
        );
    }

    /* ------------------------------------------------------------------ */
    /* What is in the way                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Counted things written against a session. Empty means it is safe to delete.
     *
     * @return array<string,int> e.g. ['student' => 12, 'invoice' => 40]
     */
    public function blockers(AcademicSession $session): array
    {
        return $this->count(
            self::SESSION_DEPENDENTS,
            fn (string $model) => $model::query()->where('academic_session_id', $session->id)->count(),
        );
    }

    /**
     * @return array<string,int>
     */
    public function termBlockers(Term $term): array
    {
        return $this->count(
            self::TERM_DEPENDENTS,
            fn (string $model) => $model::query()->where('term_id', $term->id)->count(),
        );
    }

    /**
     * @param  array<string,class-string>  $dependents
     * @param  callable(string):int  $count
     * @return array<string,int>
     */
    private function count(array $dependents, callable $count): array
    {
        $counts = [];

        foreach ($dependents as $key => $model) {
            $total = $count($model);

            if ($total > 0) {
                $counts[$key] = $total;
            }
        }

        return $counts;
    }

    /** "12 students, 3 examinations and 40 invoices" */
    public function describe(array $blocked): string
    {
        if ($blocked === []) {
            return '';
        }

        $parts = [];

        foreach ($blocked as $key => $total) {
            [$singular, $plural] = self::LABELS[$key] ?? [$key, $key];

            $parts[] = $total . ' ' . ($total === 1 ? $singular : $plural);
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts) . ' and ' . $last;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The name to suggest for the next session, e.g. 2026/2027 becomes 2027/2028.
     *
     * Only a suggestion, and only when the last one can be read — a school that
     * names its sessions something else is not corrected, it is asked.
     */
    public function suggestNextSessionName(): ?string
    {
        $latest = AcademicSession::query()
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            $year = (int) now()->year;

            return $year . '/' . ($year + 1);
        }

        if (! preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', $latest->name, $matches)) {
            return null;
        }

        return ((int) $matches[1] + 1) . '/' . ((int) $matches[2] + 1);
    }

    private function log(AcademicSession|Term $subject, string $message, ?User $actor): void
    {
        ActivityLog::record('academic.calendar', $subject, $message, [
            'module' => 'settings',
            'by' => $actor?->name,
        ]);
    }
}
