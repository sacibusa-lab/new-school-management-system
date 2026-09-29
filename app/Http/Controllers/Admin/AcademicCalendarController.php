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
            'term_id' => [
                'nullable', 'integer', 'exists:terms,id',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if (! $value) {
                        return;
                    }

                    $belongs = Term::query()
                        ->whereKey($value)
                        ->where('academic_session_id', $request->input('academic_session_id'))
                        ->exists();

                    if (! $belongs) {
                        $fail('That term belongs to a different academic session. Pick the term listed under the session you chose.');
                    }
                },
            ],
        ], [
            'academic_session_id.required' => 'Choose the academic session the school is in.',
            'academic_session_id.exists' => 'That academic session no longer exists.',
            'term_id.exists' => 'That term no longer exists.',
        ]);

        $session = AcademicSession::query()->findOrFail($validated['academic_session_id']);

        $created = [];

        DB::transaction(function () use ($session, $validated, &$created): void {
            AcademicSession::query()->whereKeyNot($session->id)->update(['is_current' => false]);
            $session->forceFill(['is_current' => true])->save();

            // A session with no terms cannot be "in" a term. The terms were created
            // when it was added, so this only catches one whose terms were all
            // deleted afterwards.
            $created = $this->calendar->layDownStandardTerms($session);

            $term = ! empty($validated['term_id'])
                ? $session->terms->firstWhere('id', (int) $validated['term_id'])
                : null;

            // No term named means "the first one of this session", which is what
            // somebody moving to a new session expects to get.
            $term ??= $session->terms->sortBy('position')->first();

            if ($term === null) {
                return;
            }

            Term::query()->whereKeyNot($term->id)->update(['is_current' => false]);
            $term->forceFill(['is_current' => true])->save();
        });

        $term = $session->terms()->where('is_current', true)->first();

        ActivityLog::record(
            'settings.academic',
            $session,
            sprintf(
                'Moved the school to %s, %s%s',
                $session->name,
                $term?->name ?? 'no term set',
                $created === [] ? '' : ' (' . implode(', ', $created) . ' created)',
            ),
            ['module' => 'settings'],
        );

        $message = sprintf('The school is now in %s', $session->name);

        if ($term) {
            $message .= ', ' . $term->name;
        }

        if ($created !== []) {
            $message .= '. This session had no terms, so ' . implode(', ', $created) . ' were created for it.';
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
            'with_terms' => ['nullable', 'boolean'],
        ], [
            'name.regex' => 'Write the session as two years, like 2027/2028.',
            'name.unique' => 'That academic session already exists.',
            'ends_on.after' => 'The session cannot end before it starts.',
        ]);

        $session = $this->calendar->addSession(
            $validated['name'],
            $validated['starts_on'] ?? null,
            $validated['ends_on'] ?? null,
            $request->boolean('with_terms', true),
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

    public function storeTerm(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'name' => ['required', 'string', 'max:40'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
        ], [
            'ends_on.after' => 'A term cannot end before it starts.',
        ]);

        $session = AcademicSession::query()->findOrFail($validated['academic_session_id']);

        // A session may run two terms or four; the count is the school's business.
        // A name repeated inside the same session is not — that is a second
        // attempt at a term that already exists.
        if ($session->terms()->where('name', $validated['name'])->exists()) {
            return back()->with('error', sprintf(
                '%s already has a term called %s.',
                $session->name,
                $validated['name'],
            ));
        }

        $term = $this->calendar->addTerm(
            $session,
            $validated['name'],
            $validated['starts_on'] ?? null,
            $validated['ends_on'] ?? null,
            $request->user(),
        );

        return back()->with('status', sprintf('%s added to %s.', $term->name, $session->name));
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
