<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PromotionAction;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Services\Academics\StudentPromotionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Promotion: moving a class on at the end of a session.
 *
 * The page is worked one class at a time, because that is how the school does it —
 * a class teacher knows their class, and a whole school on one screen is a list
 * nobody can check. Every student in the class gets a decision, and the decision
 * is the office's: the session average is shown beside each name to inform it, not
 * to make it.
 *
 * "Promoted" names the class they move into, which is what lets a student change
 * arm as well as year — JSS1A to JSS2B is one decision, not a move and a fix-up.
 */
class PromotionController extends Controller
{
    public function __construct(
        private readonly StudentPromotionService $promotions,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('academics.manage');

        $sessions = AcademicSession::query()->orderBy('starts_on')->orderBy('id')->get();

        $fromSession = $sessions->firstWhere('id', $request->integer('from'))
            ?? AcademicSession::current()
            ?? $sessions->last();

        // The session after this one in the school's own order, which is the one a
        // class moves into. Null at the end of the road, where the school has not
        // set next year up yet — the page says so rather than guessing.
        $fromIndex = $fromSession === null
            ? false
            : $sessions->search(fn (AcademicSession $session): bool => $session->id === $fromSession->id);
        $nextSession = $fromIndex === false ? null : $sessions->get($fromIndex + 1);

        $toSession = $sessions->firstWhere('id', $request->integer('to')) ?? $nextSession;

        if ($toSession !== null && $toSession->id === $fromSession?->id) {
            $toSession = $nextSession;
        }

        $classes = SchoolClass::query()
            ->with(['level:id,name,order', 'section:id,name,order'])
            ->where('is_active', true)
            ->whereHas('level', fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('section')
            ->orderBy('level_id')
            ->orderBy('section_id')
            ->get();

        $selectedClass = $classes->firstWhere('id', $request->integer('class')) ?? $classes->first();

        $students = $selectedClass !== null && $fromSession !== null
            ? $this->classRegister($selectedClass, $fromSession)->load([
                'termResults' => fn (HasMany $query) => $query->where('academic_session_id', $fromSession->id),
            ])
            : collect();

        $decisions = StudentPromotion::query()
            ->whereIn('student_id', $students->pluck('id'))
            ->where('from_academic_session_id', $fromSession?->id)
            ->get()
            ->keyBy('student_id');

        // The session's average, shown beside each name so the decision is made with
        // the marks in sight. Null where no result was computed — not a zero.
        $averages = $students->mapWithKeys(fn (Student $student): array => [
            $student->id => $student->termResults->isEmpty()
                ? null
                : round((float) $student->termResults->avg('average'), 2),
        ]);

        // Where a promoted student goes by default: the same section one level up,
        // falling back to anything in that level. Null on the final level, where
        // "promoted" is not on the table and graduating is.
        $nextLevel = $selectedClass?->level === null
            ? null
            : SchoolLevel::query()
                ->where('order', '>', $selectedClass->level->order)
                ->orderBy('order')
                ->first();

        $defaultTarget = $nextLevel === null ? null : (
            $classes->first(fn (SchoolClass $class): bool => $class->level_id === $nextLevel->id
                && $class->section_id === $selectedClass?->section_id)
            ?? $classes->firstWhere('level_id', $nextLevel->id)
        );

        return view('admin.students-results.academics.promotion', [
            'sessions' => $sessions,
            'fromSession' => $fromSession,
            'toSession' => $toSession,
            'nextSession' => $nextSession,
            'classes' => $classes,
            'classesByLevel' => $classes->groupBy(fn (SchoolClass $class): string => $class->level?->name ?? '—'),
            'selectedClass' => $selectedClass,
            'students' => $students,
            'decisions' => $decisions,
            'averages' => $averages,
            'defaultTarget' => $defaultTarget,
            'actions' => PromotionAction::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('academics.manage');

        $validated = $request->validate([
            'school_class_id' => ['required', 'integer', 'exists:school_classes,id'],
            'from_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
            'to_session_id' => ['required', 'integer', 'different:from_session_id', 'exists:academic_sessions,id'],
            'decisions' => ['required', 'array'],
            'decisions.*.action' => ['required', Rule::in(PromotionAction::values())],
            'decisions.*.class_id' => ['nullable', 'integer', Rule::exists('school_classes', 'id')],
        ], [
            'to_session_id.different' => 'A session cannot be promoted into itself.',
            'decisions.required' => 'There is nobody in this class to decide about.',
        ]);

        $fromSession = AcademicSession::query()->findOrFail($validated['from_session_id']);
        $toSession = AcademicSession::query()->findOrFail($validated['to_session_id']);
        $schoolClass = SchoolClass::query()->findOrFail($validated['school_class_id']);

        // The ids came from the page, so they are checked against the class the page
        // was showing rather than trusted: a decision cannot be taken about a
        // student who is not in this class.
        $register = $this->classRegister($schoolClass, $fromSession)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach (array_keys($validated['decisions']) as $studentId) {
            if (! in_array((int) $studentId, $register, true)) {
                throw ValidationException::withMessages([
                    'decisions' => 'That student is not in this class.',
                ]);
            }
        }

        $this->promotions->apply(
            $schoolClass,
            $fromSession,
            $toSession,
            $validated['decisions'],
            $request->user(),
        );

        $decided = count($validated['decisions']);

        return redirect()
            ->route('admin.students-results.academics.promotion', [
                'from' => $fromSession->id,
                'to' => $toSession->id,
                'class' => $schoolClass->id,
            ])
            ->with('status', "Saved {$decided} "
                .Str::plural('decision', $decided)
                ." for {$schoolClass->name} into {$toSession->name}.");
    }

    /**
     * The students a class's promotion page is about.
     *
     * Normally that is the class's register for the session. Once the class has
     * been promoted, though, those students have moved on and no longer answer to
     * this class — so anyone the session's decision was taken about is listed too,
     * which is what makes the page revisitable and a mistake correctable.
     *
     * @return Collection<int, Student>
     */
    private function classRegister(SchoolClass $schoolClass, AcademicSession $fromSession): Collection
    {
        return Student::query()
            ->where(function (Builder $query) use ($schoolClass, $fromSession): void {
                $query->where(function (Builder $inner) use ($schoolClass, $fromSession): void {
                    $inner->where('school_class_id', $schoolClass->id)
                        ->where('academic_session_id', $fromSession->id);
                })->orWhereHas('promotions', fn (Builder $promotion) => $promotion
                    ->where('from_school_class_id', $schoolClass->id)
                    ->where('from_academic_session_id', $fromSession->id));
            })
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();
    }
}
