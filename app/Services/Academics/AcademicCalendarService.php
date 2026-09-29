<?php

namespace App\Services\Academics;

use App\Models\AcademicSession;
use App\Models\ActivityLog;
use App\Models\Applicant;
use App\Models\Assessment;
use App\Models\Exam;
use App\Models\Invoice;
use App\Models\ResultPublication;
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
     * The terms a session runs, in order.
     *
     * Used to lay them down for a session that has none — never to correct an
     * existing one, since a school that renamed or reordered its terms has said
     * something and should not have it overwritten.
     */
    public const STANDARD_TERMS = [1 => 'First Term', 2 => 'Second Term', 3 => 'Third Term'];

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
    /* Adding                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Create a session, optionally with the three terms it will run.
     */
    public function addSession(
        string $name,
        ?string $startsOn = null,
        ?string $endsOn = null,
        bool $withTerms = true,
        ?User $actor = null,
    ): AcademicSession {
        return DB::transaction(function () use ($name, $startsOn, $endsOn, $withTerms, $actor) {
            $session = AcademicSession::create([
                'name' => $name,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                // A new session is not where the school is; moving to it is a
                // separate, deliberate act.
                'is_current' => false,
                'is_admission_open' => false,
            ]);

            if ($withTerms) {
                $this->layDownStandardTerms($session);
            }

            $this->log($session, sprintf('Added the academic session %s', $session->name), $actor);

            return $session;
        });
    }

    /**
     * Add one term to a session.
     *
     * It takes the lowest free position, so a term added after a deletion sits
     * back in the gap where it belongs rather than at the end.
     */
    public function addTerm(
        AcademicSession $session,
        string $name,
        ?string $startsOn = null,
        ?string $endsOn = null,
        ?User $actor = null,
    ): Term {
        return DB::transaction(function () use ($session, $name, $startsOn, $endsOn, $actor) {
            $taken = $session->terms()->pluck('position')->all();
            $position = 1;

            while (in_array($position, $taken, true)) {
                $position++;
            }

            $term = $session->terms()->create([
                'name' => $name,
                'position' => $position,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'is_current' => false,
            ]);

            $this->log($term, sprintf('Added %s to %s', $term->name, $session->name), $actor);

            return $term;
        });
    }

    /**
     * The standard three, for a session that has none.
     *
     * @return array<int,string> the names created, empty if there were already terms
     */
    public function layDownStandardTerms(AcademicSession $session): array
    {
        if ($session->terms()->exists()) {
            return [];
        }

        $created = [];

        foreach (self::STANDARD_TERMS as $position => $name) {
            $session->terms()->create([
                'name' => $name,
                'position' => $position,
                'is_current' => false,
            ]);

            $created[] = $name;
        }

        $session->load('terms');

        return $created;
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
        $terms = $session->terms()->count();

        DB::transaction(function () use ($session): void {
            // The terms go with it — the foreign key says so, and a term with no
            // session is not a thing.
            $session->delete();
        });

        ActivityLog::record(
            'academic.session',
            null,
            sprintf('Deleted the academic session %s, with its %d term(s)', $name, $terms),
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

        $label = $term->label();

        $term->delete();

        ActivityLog::record(
            'academic.term',
            null,
            sprintf('Deleted the term %s', $label),
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
