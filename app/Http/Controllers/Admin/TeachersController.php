<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The teaching staff: who they are, and the account they sign in with.
 *
 * The two pages under Teachers are this register and the form that fills it. They
 * are not Staff & roles: that page manages logins and what each one is allowed to
 * do, and it will hold the bursar, the office and everybody else besides. This is
 * the school's list of the people who teach, which is what a class has to be given
 * one of.
 *
 * A teacher is therefore an account with the Teacher role and nothing else special
 * — no second table, no second kind of person. Adding one here is the shortcut:
 * the role is chosen for you, because "Add Teachers" can only mean one thing.
 */
class TeachersController extends Controller
{
    public function index(): View
    {
        $this->authorize('teachers.manage');

        return view('admin.students-results.teachers.list', [
            'page' => collect(StudentsResultsController::TEACHER_PAGES)->firstWhere('key', 'teachers-list'),
            'teachers' => User::query()
                ->role('Teacher')
                ->with('taughtClasses.level')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('teachers.manage');

        return view('admin.students-results.teachers.create', [
            'page' => collect(StudentsResultsController::TEACHER_PAGES)->firstWhere('key', 'teachers-create'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('teachers.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            // Required, and not merely preferred: the school texts teachers, and a
            // teacher nobody can text is a teacher the office cannot reach.
            'phone' => ['required', 'string', 'max:30'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'email.unique' => 'That email already has an account. Staff & roles is where an account that exists is changed.',
            'phone.required' => 'A phone number is needed: the school reaches teachers by text message.',
        ]);

        $teacher = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            // A photograph is added once they have been taken on, so it is asked for
            // and not required: the record matters more than the picture.
            'avatar_path' => $request->file('avatar')?->store(config('saci.uploads.photos').'/teachers', 'public'),
            'password' => Hash::make($validated['password']),
            'is_active' => true,
            'must_change_password' => true,
        ]);

        $teacher->assignRole('Teacher');

        return redirect()
            ->route('admin.students-results.teachers.list')
            ->with('status', "{$teacher->name} added as a teacher. They change the password the first time they sign in.");
    }

    public function edit(User $teacher): View
    {
        $this->authorize('teachers.manage');

        $this->assertIsTeacher($teacher);

        return view('admin.students-results.teachers.edit', [
            'page' => collect(StudentsResultsController::TEACHER_PAGES)->firstWhere('key', 'teachers-list'),
            'teacher' => $teacher,
        ]);
    }

    /**
     * Change what the register says about a teacher.
     *
     * The password is not here and never will be: it belongs to the teacher, who
     * changes it from their own profile, and an office that could read or set it
     * would be an office that could sign in as them.
     *
     * A new photograph replaces the old one and the old one is deleted — a replaced
     * photograph is usually a replaced photograph because somebody objected to the
     * first one, and leaving it on the disk leaves it readable by whoever has the
     * link. Taking it off is offered next to it, and only where there is one to
     * take off.
     */
    public function update(Request $request, User $teacher): RedirectResponse
    {
        $this->authorize('teachers.manage');

        $this->assertIsTeacher($teacher);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($teacher->id)],
            'phone' => ['required', 'string', 'max:30'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'email.unique' => 'That email belongs to another account.',
            'phone.required' => 'A phone number is needed: the school reaches teachers by text message.',
        ]);

        $previous = $teacher->avatar_path;
        $avatar = $previous;

        if ($request->hasFile('avatar')) {
            $avatar = $request->file('avatar')->store(config('saci.uploads.photos').'/teachers', 'public');
        } elseif ($request->boolean('remove_avatar')) {
            $avatar = null;
        }

        if ($previous !== null && $previous !== $avatar) {
            Storage::disk('public')->delete($previous);
        }

        $teacher->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'avatar_path' => $avatar,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()
            ->route('admin.students-results.teachers.list')
            ->with('status', "{$teacher->name} saved.");
    }

    /**
     * This page is the teachers register, not Staff & roles: an account that does
     * not teach is not something it edits, and saying 404 is truer than opening one
     * up with half its fields missing.
     */
    private function assertIsTeacher(User $teacher): void
    {
        abort_unless($teacher->hasRole('Teacher'), 404);
    }
}
