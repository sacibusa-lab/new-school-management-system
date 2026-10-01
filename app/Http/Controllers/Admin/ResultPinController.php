<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Term;
use App\Services\Results\ResultPinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

/**
 * The PINs a parent buys to check a result.
 *
 * The screen is two questions and a button: which class, which section of it, and
 * Generate. Everything else on the page is the answer to what that produced — who in
 * the class has a PIN for this term and who has not, so the second press is aimed at
 * the children who joined since the first one.
 *
 * Nothing here gates the public page yet. Checking a result still needs only the
 * session, the term and the admission number; the day the office has cards in hand,
 * the printed PINs are already waiting in this table.
 */
class ResultPinController extends Controller
{
    public function __construct(
        private readonly ResultPinService $pins,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('results.pins');

        $session = AcademicSession::current();
        $term = Term::current();

        $class = $request->filled('class')
            ? SchoolClass::query()->with(['level', 'section'])->find($request->integer('class'))
            : null;

        $students = collect();
        $pins = collect();

        if ($class !== null && $session !== null && $term !== null) {
            $students = $this->pins->studentsFor($class);
            $pins = $this->pins->forClass($class, $session, $term);
        }

        return view('admin.students-results.pins', [
            'levels' => $this->levels(),
            'sections' => Section::query()->orderBy('order')->orderBy('name')->get(),
            'session' => $session,
            'term' => $term,
            'class' => $class,
            'students' => $students,
            'pins' => $pins,
        ]);
    }

    /** Generate whatever is missing for the class that was chosen. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('results.pins');

        $validated = $request->validate([
            'level_id' => ['required', 'integer', 'exists:school_levels,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ], [
            'level_id.required' => 'Choose the class the PINs are for.',
            'section_id.required' => 'Choose the section of that class.',
        ]);

        $class = $this->classFor($validated['level_id'], $validated['section_id']);

        if (is_string($class)) {
            return back()->withInput()->with('error', $class);
        }

        $session = AcademicSession::current();
        $term = Term::current();

        if ($session === null || $term === null) {
            return back()->with('error', 'There is no current session and term. Set them on the Settings page, then come back.');
        }

        try {
            $result = $this->pins->generateFor($class, $session, $term, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $message = $result['created'] === 0
            ? "Every student in {$class->name} already has a PIN for {$term->name}."
            : sprintf(
                '%d PIN(s) generated for %s, %s. %d student(s) already had one.',
                $result['created'],
                $class->name,
                $term->name,
                $result['already'],
            );

        return redirect()
            ->route('admin.students-results.pins', ['class' => $class->id])
            ->with('status', $message);
    }

    /** The sheet the office cuts up, one line to a student. */
    public function sheet(Request $request): View
    {
        $this->authorize('results.pins');

        $session = AcademicSession::current();
        $term = Term::current();

        $class = $request->filled('class')
            ? SchoolClass::query()->with(['level', 'section'])->find($request->integer('class'))
            : null;

        abort_if($class === null || $session === null || $term === null, 404);

        return view('admin.students-results.pins-sheet', [
            'class' => $class,
            'session' => $session,
            'term' => $term,
            'students' => $this->pins->studentsFor($class),
            'pins' => $this->pins->forClass($class, $session, $term),
        ]);
    }

    /**
     * The class a level and a section mean, or the sentence to show if they mean none.
     *
     * The same rule the Classes & Sections page uses: JSS1 and A are only a class once
     * somebody has made JSS1A.
     */
    private function classFor(int|string $levelId, int|string $sectionId): SchoolClass|string
    {
        $class = SchoolClass::query()
            ->where('level_id', $levelId)
            ->where('section_id', $sectionId)
            ->first();

        if ($class !== null) {
            return $class;
        }

        $level = SchoolLevel::query()->find($levelId);
        $section = Section::query()->find($sectionId);

        return "{$level?->name}{$section?->name} is not a class yet. Create it on the Classes & Sections page first.";
    }

    /** The classes each level actually has, so the section list only offers those. */
    private function levels(): Collection
    {
        return SchoolLevel::query()
            ->active()
            ->with('classes:id,level_id,section_id')
            ->orderBy('order')
            ->get();
    }
}
