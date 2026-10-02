<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StudentController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('students.view');

        $students = Student::query()
            ->with(['level', 'schoolClass', 'academicSession'])
            ->withCount('invoices')
            ->withSum('invoices as balance_due', 'balance')
            ->search($request->string('q')->toString())
            ->when($request->filled('level'), fn ($q) => $q->where('level_id', $request->integer('level')))
            ->when($request->filled('class'), fn ($q) => $q->where('school_class_id', $request->integer('class')))
            ->when($request->filled('session'), fn ($q) => $q->where('academic_session_id', $request->integer('session')))
            ->when($request->filled('owing'), fn ($q) => $q->whereHas('invoices', fn ($i) => $i->where('balance', '>', 0)))
            ->latest('admitted_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.students.index', [
            'students' => $students,
            'levels' => SchoolLevel::query()->active()->with('classes')->get(),
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(),
        ]);
    }

    public function show(Student $student): View
    {
        $this->authorize('view', $student);

        $student->load([
            'level', 'schoolClass', 'academicSession', 'applicant', 'user',
            'termResults.term', 'termResults.academicSession',
            // How they got here, and the examination they came in on.
            'promotions.fromSession', 'promotions.toSession',
            'promotions.fromClass', 'promotions.toClass', 'promotions.decidedBy',
            'applicant.scores.examSubject.subject',
        ]);

        return view('admin.students.show', [
            'student' => $student,
            'invoices' => $student->invoices()->with('term')->orderByDesc('issued_at')->get(),
            'payments' => $student->payments()->with('invoice')->orderByDesc('paid_at')->limit(20)->get(),
            'financials' => [
                'billed' => $student->totalBilled(),
                'paid' => $student->totalPaid(),
                'balance' => $student->outstandingBalance(),
            ],
        ]);
    }

    public function edit(Student $student): View
    {
        $this->authorize('update', $student);

        return view('admin.students.edit', [
            'student' => $student,
            'levels' => SchoolLevel::query()->active()->with('classes')->get(),
        ]);
    }

    public function update(Request $request, Student $student): RedirectResponse
    {
        $this->authorize('update', $student);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['nullable', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'level_id' => ['nullable', 'exists:school_levels,id'],
            'school_class_id' => ['nullable', 'exists:school_classes,id'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:150'],
            'status' => ['required', Rule::enum(StudentStatus::class)],
            'results_portal_enabled' => ['nullable', 'boolean'],
            'fees_portal_enabled' => ['nullable', 'boolean'],
        ]);

        // The year group is a property of the class, not a second opinion about it.
        // A child in JSS2A is in JSS2, and letting the two be set apart is how somebody
        // ends up filed under a year the register does not look for them in — the
        // register filters on the year group, so they would simply go missing from
        // their own class. Where a class is named, it decides.
        if ($class = SchoolClass::query()->find($validated['school_class_id'] ?? null)) {
            $validated['level_id'] = $class->level_id;
        }

        $student->update($validated + [
            'results_portal_enabled' => $request->boolean('results_portal_enabled'),
            'fees_portal_enabled' => $request->boolean('fees_portal_enabled'),
        ]);

        return redirect()
            ->route('admin.students.show', $student)
            ->with('status', 'Student record updated.');
    }

    /** Reset a student's portal password back to their admission number. */
    public function resetPassword(Request $request, Student $student): RedirectResponse
    {
        $this->authorize('students.manage');

        if (! $student->user) {
            return back()->with('error', 'This student does not have a portal account yet.');
        }

        $student->user->update([
            'password' => Hash::make($student->student_number),
        ]);

        return back()->with('status', "Portal password reset. {$student->first_name} signs in with {$student->student_number}.");
    }
}
