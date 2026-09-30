<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\User;
use App\Services\Academics\AcademicStructureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Classes and sections: the shape of the school.
 *
 * Sections are created first and class names second, because that is the order the
 * two are built in — JSS1 is not a class anybody sits in until it has a section, and
 * JSS1A is what JSS1 and section A make. The page is one screen with both lists on
 * it, in that order, so the office can see why it works that way rather than
 * discovering it from an empty dropdown somewhere else.
 *
 * Nothing here has a confirmation step of its own: a delete that would take a
 * child's class, a class's marks or a section's classes with it is refused by the
 * service, with the reason. The office is told what is in the way rather than asked
 * whether it is sure.
 */
class ClassesAndSectionsController extends Controller
{
    public function __construct(
        private readonly AcademicStructureService $structure,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('academics.manage');

        $classes = SchoolClass::query()
            ->with(['level', 'section', 'formTeacher'])
            ->withCount('students')
            ->orderBy('name')
            ->get()
            ->groupBy('level_id');

        // The pencil in the list opens this same screen on its Edit tab.
        $editing = $request->integer('edit')
            ? SchoolLevel::query()->find($request->integer('edit'))
            : null;

        return view('admin.students-results.academics.classes', [
            'page' => collect(StudentsResultsController::ACADEMIC_PAGES)->firstWhere('key', 'classes'),
            // Sections first, and each one says which classes are drawn from it.
            'sections' => Section::query()->with('schoolClasses')->ordered()->get(),
            'levels' => SchoolLevel::query()->orderBy('order')->orderBy('name')->get(),
            'classes' => $classes,
            // Every class on one list for the Form Teacher tab, in the same order
            // as the classes page above. The accounts on it are the teachers
            // already on the staff — the office picks a person, not a login.
            'allClasses' => $classes->flatten(1),
            'teachers' => User::query()->role('Teacher')->orderBy('name')->get(),
            'editing' => $editing,
            'tab' => $editing !== null
                ? 'edit'
                : match ($request->string('tab')->value()) {
                    'section' => 'section',
                    'teacher' => 'teacher',
                    default => 'class',
                },
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Sections */
    /* ------------------------------------------------------------------ */

    public function storeSection(Request $request): RedirectResponse
    {
        $this->authorize('academics.manage');

        $validated = $request->validate([
            'section_name' => ['required', 'string', 'max:20', 'unique:sections,name'],
        ], [
            'section_name.unique' => 'That section is already there.',
        ]);

        $section = $this->structure->addSection($validated['section_name'], $request->user());

        return back()->with('status', "Section {$section->name} added.");
    }

    public function destroySection(Section $section): RedirectResponse
    {
        $this->authorize('academics.manage');

        try {
            $this->structure->deleteSection($section);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Section {$section->name} deleted.");
    }

    /* ------------------------------------------------------------------ */
    /* Class names */
    /* ------------------------------------------------------------------ */

    public function storeClass(Request $request): RedirectResponse
    {
        $this->authorize('academics.manage');

        $validated = $request->validate([
            'class_name' => ['required', 'string', 'max:20'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ]);

        $section = Section::query()->findOrFail($validated['section_id']);

        try {
            $class = $this->structure->addClassFrom($validated['class_name'], $section, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$class->name} added.");
    }

    public function destroyClass(SchoolLevel $level): RedirectResponse
    {
        $this->authorize('academics.manage');

        try {
            $this->structure->deleteClassName($level);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Class {$level->name} deleted.");
    }

    /**
     * The Edit tab, in one submit: the name, and the sections it should have.
     *
     * A name that is already taken stops the whole thing — there is no sense in half
     * a rename. A section that cannot be dropped does not: the refusal is reported and
     * the rest of the form is saved, because a class holding children in one section
     * is no reason to refuse its rename.
     */
    public function updateClass(Request $request, SchoolLevel $level): RedirectResponse
    {
        $this->authorize('academics.manage');

        $validated = $request->validate([
            'class_name' => ['required', 'string', 'max:20'],
            'sections' => ['array'],
            'sections.*' => ['integer', 'exists:sections,id'],
        ]);

        try {
            $this->structure->renameClassName($level, $validated['class_name'], $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $refused = $this->structure->syncSections($level, $validated['sections'] ?? [], $request->user());

        if ($refused !== []) {
            return back()->with('warning', implode(' ', $refused));
        }

        return back()->with('status', "{$level->refresh()->name} saved.");
    }

    /* ------------------------------------------------------------------ */
    /* A class: a class name and a section */
    /* ------------------------------------------------------------------ */

    public function storeClassSection(Request $request, SchoolLevel $level): RedirectResponse
    {
        $this->authorize('academics.manage');

        $validated = $request->validate([
            'section_id' => ['required', 'integer', 'exists:sections,id'],
        ]);

        $section = Section::query()->findOrFail($validated['section_id']);

        try {
            $class = $this->structure->addClass($level, $section, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$class->name} added.");
    }

    public function destroyClassSection(SchoolClass $schoolClass): RedirectResponse
    {
        $this->authorize('academics.manage');

        try {
            $this->structure->deleteClass($schoolClass);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$schoolClass->name} deleted.");
    }

    /* ------------------------------------------------------------------ */
    /* The form teacher of a class */
    /* ------------------------------------------------------------------ */

    /**
     * The Form Teacher tab, one row at a time.
     *
     * A class is between teachers as often as it is mid-year, so a blank choice is
     * a real answer rather than a mistake to be refused: it takes the last teacher
     * off the class instead of leaving a name there that nobody chose again.
     */
    public function updateFormTeacher(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $this->authorize('academics.manage');

        $validated = $request->validate([
            'form_teacher_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $teacher = isset($validated['form_teacher_id'])
            ? User::query()->find($validated['form_teacher_id'])
            : null;

        try {
            $this->structure->assignFormTeacher($schoolClass, $teacher, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'status',
            $teacher === null
                ? "{$schoolClass->name} has no form teacher now."
                : "{$teacher->name} is now the form teacher of {$schoolClass->name}.",
        );
    }
}
