<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\ResultPublication;
use App\Models\SchoolClass;
use App\Models\Term;
use App\Models\TermResult;
use App\Services\Results\ResultComputationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ResultController extends Controller
{
    public function __construct(
        private readonly ResultComputationService $results,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('results.view');

        $termId = $request->integer('term') ?: Term::current()?->id;
        $classId = $request->integer('class');

        $results = TermResult::query()
            ->with(['student.level', 'student.schoolClass', 'term.academicSession'])
            ->when($termId, fn ($q) => $q->where('term_id', $termId))
            ->when($classId, fn ($q) => $q->where('school_class_id', $classId))
            ->orderBy('position')
            ->paginate(30)
            ->withQueryString();

        return view('admin.results.index', [
            'results' => $results,
            'terms' => Term::query()->with('academicSession')->orderByDesc('academic_session_id')->orderBy('position')->get(),
            'classes' => SchoolClass::query()->where('is_active', true)->with('level')->orderBy('name')->get(),
            'selectedTerm' => $termId,
            'selectedClass' => $classId,
            'published' => $termId && $classId
                ? ResultPublication::isPublishedFor(
                    (int) ($results->first()?->academic_session_id ?? AcademicSession::current()?->id),
                    $termId,
                    $classId,
                )
                : false,
        ]);
    }

    public function show(TermResult $termResult): View
    {
        $this->authorize('view', $termResult);

        $termResult->load([
            'student.level', 'student.schoolClass', 'term.academicSession',
            'items.subject', 'schoolClass',
        ]);

        return view('admin.results.show', [
            'result' => $termResult,
        ]);
    }

    public function compute(Request $request): RedirectResponse
    {
        $this->authorize('results.compute');

        $validated = $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'term_id' => ['required', 'exists:terms,id'],
        ]);

        $summary = $this->results->computeForClass(
            $validated['school_class_id'],
            $validated['academic_session_id'],
            $validated['term_id'],
            $request->user(),
        );

        if ($summary['students'] === 0) {
            return back()->with('error', 'No assessments found for that class and term, so there is nothing to compute.');
        }

        return redirect()
            ->route('admin.results.index', ['term' => $validated['term_id'], 'class' => $validated['school_class_id']])
            ->with('status', "{$summary['students']} report card(s) computed across {$summary['subjects']} subject(s). Review, then publish.");
    }

    public function publish(Request $request): RedirectResponse
    {
        $this->authorize('results.publish');

        $validated = $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'term_id' => ['required', 'exists:terms,id'],
        ]);

        $count = $this->results->publish(
            $validated['school_class_id'],
            $validated['academic_session_id'],
            $validated['term_id'],
            $request->user(),
        );

        return redirect()
            ->route('admin.results.index', ['term' => $validated['term_id'], 'class' => $validated['school_class_id']])
            ->with('status', "{$count} result(s) published. Students can now check them on the public result checker.");
    }

    public function unpublish(Request $request): RedirectResponse
    {
        $this->authorize('results.publish');

        $validated = $request->validate([
            'school_class_id' => ['required', 'exists:school_classes,id'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'term_id' => ['required', 'exists:terms,id'],
        ]);

        $count = $this->results->unpublish(
            $validated['school_class_id'],
            $validated['academic_session_id'],
            $validated['term_id'],
        );

        return redirect()
            ->route('admin.results.index', ['term' => $validated['term_id'], 'class' => $validated['school_class_id']])
            ->with('status', "{$count} result(s) withdrawn from public view.");
    }
}
