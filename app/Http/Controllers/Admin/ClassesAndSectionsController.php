<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\Setting;
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

        // The pencil in the Class Teacher list opens this screen on the Form Teacher
        // tab with that class in it: the same idea as the one above, for the other
        // thing a row of this page can be edited as.
        $editingTeacher = $request->integer('teacher')
            ? SchoolClass::query()->with(['level', 'section', 'formTeacher'])->find($request->integer('teacher'))
            : null;

        return view('admin.students-results.academics.classes', [
            'page' => collect(StudentsResultsController::ACADEMIC_PAGES)->firstWhere('key', 'classes'),
            // Sections first, and each one says which classes are drawn from it.
            'sections' => Section::query()->with('schoolClasses')->ordered()->get(),
            // The classes each name has, so the Section dropdown can offer only the
            // ones that class actually has — JSS1 and C make nothing at all.
            'levels' => SchoolLevel::query()->with('classes:id,level_id,section_id')->orderBy('order')->orderBy('name')->get(),
            'classes' => $classes,
            // Every class on one list for the Class Teacher table, in the same order
            // as the classes page above. The accounts on it are the teachers
            // already on the staff — the office picks a person, not a login.
            'allClasses' => $classes->flatten(1),
            'teachers' => User::query()->role('Teacher')->orderBy('name')->get(),
            'editing' => $editing,
            'editingTeacher' => $editingTeacher,
            // The school the allocation belongs to. There is one branch in this
            // platform, but the list has always carried the column and the office
            // reads a row by it, so it is named rather than left blank.
            'branch' => Setting::get('school_name', config('saci.school_name')),
            'tab' => match (true) {
                $editing !== null => 'edit',
                $editingTeacher !== null => 'teacher',
                default => match ($request->string('tab')->value()) {
                    'section' => 'section',
                    'teacher' => 'teacher',
                    default => 'class',
                },
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
    /* The class teacher of a class */
    /* ------------------------------------------------------------------ */

    /**
     * Allocate a class teacher, which is the Class Teacher Allocation form.
     *
     * The office names the class the way the school does — the class, then the
     * section — rather than picking a class from a list of twenty: JSS1 and A is
     * how JSS1A is said out loud, and it is how it is asked for here. A class that
     * does not exist yet is refused with the reason, because a teacher cannot be
     * put in charge of JSS1C when there is no JSS1C.
     *
     * Allocating to a class that already has one replaces them: that is what the
     * form means, and the pencil in the list is only a way of loading one row of it
     * without it being typed out again.
     */
    public function storeFormTeacher(Request $request): RedirectResponse
    {
        $this->authorize('academics.manage');

        $validated = $request->validate([
            'level_id' => ['required', 'integer', 'exists:school_levels,id'],
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'form_teacher_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $class = SchoolClass::query()
            ->where('level_id', $validated['level_id'])
            ->where('section_id', $validated['section_id'])
            ->first();

        if ($class === null) {
            $level = SchoolLevel::query()->find($validated['level_id']);
            $section = Section::query()->find($validated['section_id']);

            return redirect()
                ->route('admin.students-results.academics.classes', ['tab' => 'teacher'])
                ->with('error', "{$level->name}{$section->name} is not a class yet. Create it on the Class tab first.");
        }

        $teacher = User::query()->findOrFail($validated['form_teacher_id']);

        try {
            $this->structure->assignClassTeacher($class, $teacher, $request->user());
        } catch (RuntimeException $e) {
            return redirect()
                ->route('admin.students-results.academics.classes', ['tab' => 'teacher'])
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.students-results.academics.classes', ['tab' => 'teacher'])
            ->with('status', "{$teacher->name} is now the class teacher of {$class->name}.");
    }

    /**
     * Take the class teacher off a class.
     *
     * A class between teachers is left empty rather than keeping the name of the
     * one who has gone, which is a record of somebody who is not there any more.
     * Nothing else about the class moves with it.
     */
    public function destroyFormTeacher(Request $request, SchoolClass $schoolClass): RedirectResponse
    {
        $this->authorize('academics.manage');

        try {
            $this->structure->assignClassTeacher($schoolClass, null, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.students-results.academics.classes', ['tab' => 'teacher'])
            ->with('status', "{$schoolClass->name} has no class teacher now.");
    }
}
