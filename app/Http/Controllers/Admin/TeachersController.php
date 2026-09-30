<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
}
