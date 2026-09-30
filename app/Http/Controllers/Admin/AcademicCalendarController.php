<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\ActivityLog;
use App\Models\Term;
use App\Services\Academics\AcademicCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The academic calendar, managed from the settings page.
 *
 * One controller rather than four, because every action here is about the same
 * two rows and the rules that keep them single-valued — which session the school
 * is in, and which term within it. Splitting them would put the guard in one place
 * and the thing it guards in another.
 */
class AcademicCalendarController extends Controller
{
    public function __construct(private readonly AcademicCalendarService $calendar)
    {
    }

    /* ------------------------------------------------------------------ */
    /* Where the school is now                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Move the school to a different session and term.
     *
     * Setting a value is three writes, not one: the chosen row becomes current,
     * and everything else stops being. A session left current behind us is the
     * stale answer to "which session are we in?", and a term left current in the
     * session we have just left is the same question answered twice.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            // No check that the term belongs to the session: it belongs to every
            // session. That is the whole point of a term being universal.
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
        ], [
            'academic_session_id.required' => 'Choose the academic session the school is in.',
            'academic_session_id.exists' => 'That academic session no longer exists.',
            'term_id.exists' => 'That term no longer exists.',
        ]);

        $session = AcademicSession::query()->findOrFail($validated['academic_session_id']);

        DB::transaction(function () use ($session, $validated): void {
            AcademicSession::query()->whereKeyNot($session->id)->update(['is_current' => false]);
            $session->forceFill(['is_current' => true])->save();

            // A session always has every term, so this is only ever filling a gap
            // left by a session created before terms were shared.
            $this->calendar->attachEveryTermTo($session);

            $term = ! empty($validated['term_id'])
                ? Term::query()->find($validated['term_id'])
                : null;

            // No term named means "the first one of this session", which is what
            // somebody moving to a new session expects to get.
            $term ??= Term::query()->orderBy('position')->first();

            if ($term === null) {
                return;
            }

            Term::query()->whereKeyNot($term->id)->update(['is_current' => false]);
            $term->forceFill(['is_current' => true])->save();
        });

        $term = Term::current();

        ActivityLog::record(
            'settings.academic',
            $session,
            sprintf('Moved the school to %s, %s', $session->name, $term?->name ?? 'no term set'),
            ['module' => 'settings'],
        );

        $message = sprintf('The school is now in %s', $session->name);

        if ($term) {
            $message .= ', ' . $term->name;
        }

        return back()->with('status', $message . '.');
    }

    /* ------------------------------------------------------------------ */
    /* Sessions                                                            */
    /* ------------------------------------------------------------------ */

    public function storeSession(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        // Tidied first, so "2027 / 2028" cannot slip past the uniqueness rule as a
        // second, differently-spelled session of the same year.
        $request->merge(['name' => $this->tidyYear($request->input('name'))]);

        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:20',
                'regex:/^\d{4}\/\d{4}$/',
                Rule::unique('academic_sessions', 'name'),
            ],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
        ], [
            'name.regex' => 'Write the session as two years, like 2027/2028.',
            'name.unique' => 'That academic session already exists.',
            'ends_on.after' => 'The session cannot end before it starts.',
        ]);

        // No "create the terms for it": the terms exist, and a session has all of
        // them the moment it is created.
        $session = $this->calendar->addSession(
            $validated['name'],
            $validated['starts_on'] ?? null,
            $validated['ends_on'] ?? null,
            $request->user(),
        );

        return back()->with('status', sprintf(
            '%s added. It is not the session the school is in — set that when you are ready.',
            $session->name,
        ));
    }

    public function destroySession(Request $request, AcademicSession $academicSession): RedirectResponse
    {
        $this->authorize('settings.manage');

        try {
            $this->calendar->deleteSession($academicSession, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', sprintf('%s deleted.', $academicSession->name));
    }

    /* ------------------------------------------------------------------ */
    /* Terms                                                               */
    /* ------------------------------------------------------------------ */

    /**
     * Add a term to the school. It belongs to every session.
     *
     * Dates are optional and belong to one session — the one the form names,
     * which is the session the school is working in.
     */
    public function storeTerm(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'dates_in' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
        ], [
            'ends_on.after' => 'A term cannot end before it starts.',
        ]);

        // A session may run two terms or four; the count is the school's business.
        // A name repeated is not — that is a second attempt at a term that already
        // exists, and now it would be repeated in every session at once.
        if (Term::query()->where('name', $validated['name'])->exists()) {
            return back()->with('error', sprintf(
                'The school already has a term called %s. Terms are the same for every session.',
                $validated['name'],
            ));
        }

        $session = ! empty($validated['dates_in'])
            ? AcademicSession::query()->find($validated['dates_in'])
            : AcademicSession::current();

        try {
            $term = $this->calendar->addTerm(
                $validated['name'],
                $this->calendar->nextTermPosition(),
                $session,
                $validated['starts_on'] ?? null,
                $validated['ends_on'] ?? null,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', sprintf(
            '%s added. It applies to every session%s.',
            $term->name,
            ! empty($validated['starts_on']) || ! empty($validated['ends_on'])
                ? ', with its dates set for ' . ($session?->name ?? 'the current session')
                : '',
        ));
    }

    /** Record when a term runs in one session — last year's dates stay last year's. */
    public function updateTermDates(Request $request, Term $term): RedirectResponse
    {
        $this->authorize('settings.manage');

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
        ], [
            'ends_on.after' => 'A term cannot end before it starts.',
        ]);

        $session = AcademicSession::query()->findOrFail($validated['academic_session_id']);

        $this->calendar->setTermDates(
            $session,
            $term,
            $validated['starts_on'] ?? null,
            $validated['ends_on'] ?? null,
            $request->user(),
        );

        return back()->with('status', sprintf(
            'Dates saved for %s in %s.',
            $term->name,
            $session->name,
        ));
    }

    public function destroyTerm(Request $request, Term $term): RedirectResponse
    {
        $this->authorize('settings.manage');

        $label = $term->name;

        try {
            $this->calendar->deleteTerm($term, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $label . ' deleted.');
    }

    /** "2027 / 2028" and "2027/2028" are the same session; store one shape. */
    protected function tidyYear(?string $name): string
    {
        $name = trim((string) $name);

        // Anything that is not two years is handed through untouched, so the
        // format rule reports it rather than this quietly mangling it.
        return preg_match('/^\d{4}\s*\/\s*\d{4}$/', $name)
            ? (string) preg_replace('/\s*\/\s*/', '/', $name)
            : $name;
    }
}
