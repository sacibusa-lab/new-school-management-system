<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Services\Admissions\StudentEnrolmentService;
use App\Services\NumberSequenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Add Students: taking one child onto the roll by hand.
 *
 * For the children who do not arrive in a file — a transfer in from another school, or a
 * name the office has on paper and nowhere else. The classroom's own import is next door,
 * and both of them finish in the same place: {@see StudentEnrolmentService}, which gives
 * the child an admission number and a portal login the same way an admission does.
 *
 * **The number is typed here rather than issued.** Everywhere else the series hands out
 * SAC/2026/001, 002, 003. On this screen the office types it, because the number on their
 * paper register is the school's, not ours — a child transferring in has been called
 * something since long before they arrived. What that costs is that the series does not
 * know the number is spent, so the series is raised above it afterwards; see
 * {@see NumberSequenceService::raiseStudentNumberTo()}.
 *
 * Asking for a name and a number, and not for an application, is the whole difference
 * between this and the admissions module. Nothing here is assessed, decided or billed.
 */
class AddStudentController extends Controller
{
    public function __construct(
        private readonly StudentEnrolmentService $enrolment,
        private readonly NumberSequenceService $sequences,
    ) {}

    public function create(Request $request): View
    {
        $this->authorize('students.manage');

        return view('admin.students-results.students.add-student', [
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->orderByDesc('name')->get(),
            'current' => AcademicSession::current(),
            // The two halves of a class, so the section dropdown can offer only the arms
            // the year group actually runs.
            'levels' => SchoolLevel::query()
                ->active()
                ->with('classes:id,level_id,section_id')
                ->orderBy('order')
                ->orderBy('name')
                ->get(),
            'sections' => Section::query()->orderBy('order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('students.manage');

        $validated = $request->validate([
            'academic_session_id' => ['required', 'integer', Rule::in(AcademicSession::query()->pluck('id')->all())],
            // The school's own number for this child, and the one they will sign in with,
            // so it has to be theirs alone. The unique rule is the same index the database
            // would enforce — said here so the office gets a sentence rather than an error.
            'student_number' => ['required', 'string', 'max:40', Rule::unique('students', 'student_number')],
            'level_id' => ['required', 'integer', Rule::exists('school_levels', 'id')->where('is_active', true)],
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')],
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            // No `email` and no `phone`: a pupil has neither. What the school rings and
            // texts is the parent, whose details are further down this form.
            'gender' => ['nullable', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:150'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'academic_session_id.required' => 'Choose the session this child is joining.',
            'academic_session_id.in' => 'That session is not one the school runs. Choose another.',
            'student_number.required' => 'Type the register number this child is known by.',
            'student_number.unique' => 'That register number is already on another child. Check the register before adding.',
            'level_id.required' => 'Choose the class.',
            'level_id.exists' => 'That year group is not one the school is offering. Choose another.',
            'section_id.required' => 'Choose the section as well — JSS1 and A are JSS1A.',
            'photo.mimes' => 'That has to be a photograph — JPG, PNG or WEBP.',
            'photo.max' => 'That photograph is over 5 MB.',
        ]);

        $level = SchoolLevel::query()->findOrFail($validated['level_id']);
        $section = Section::query()->findOrFail($validated['section_id']);
        $session = AcademicSession::query()->findOrFail($validated['academic_session_id']);

        $class = SchoolClass::query()->forArm($level, $section)->first();

        if ($class === null) {
            return back()->withInput()->withErrors([
                'section_id' => "{$level->name}{$section->name} is not a class yet. Create it on the Classes & Sections page first.",
            ]);
        }

        $student = $this->enrolment->enrolFromDetails([
            'first_name' => $validated['first_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'last_name' => $validated['last_name'],
            'gender' => $validated['gender'] ?? null,
            'date_of_birth' => $validated['date_of_birth'] ?? null,
            'address' => $validated['address'] ?? null,
            'guardian_name' => $validated['guardian_name'] ?? null,
            'guardian_phone' => $validated['guardian_phone'] ?? null,
            'guardian_email' => $validated['guardian_email'] ?? null,
            'photo_path' => $request->file('photo')?->store(config('saci.uploads.photos').'/students', 'public'),
        ], $level, $class, $session, $validated['student_number']);

        // The typed number is spent, and the series does not know it. Raise it, or an
        // admission later in the year would be offered a number already on a child.
        $this->sequences->raiseStudentNumberTo($student->student_number);

        return redirect()
            ->route('admin.students.show', $student)
            ->with('status', "{$student->full_name} was added as {$student->student_number}. "
                .'They sign in to the results and fees portals with that number.');
    }
}
