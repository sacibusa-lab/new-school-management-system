<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\User;
use App\Services\Teachers\TeacherImportService;
use App\Services\Teachers\TeacherRegisterService;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

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
    /** Where a parsed upload waits between the preview and the commit. */
    private const IMPORT_SESSION_KEY = 'teacher_import';

    /**
     * Where the made-up passwords wait, for exactly one request.
     *
     * Flashed rather than kept: they are written down nowhere else, so the page that
     * hands them out reads them once and a refresh finds nothing. That is what lets
     * the page promise they are not stored and mean it.
     */
    private const IMPORT_CREDENTIALS_KEY = 'teacher_import_credentials';

    public function __construct(
        private readonly TeacherImportService $imports,
        private readonly TeacherRegisterService $register,
    ) {}

    public function index(): View
    {
        $this->authorize('teachers.manage');

        $teachers = User::query()
            ->role('Teacher')
            ->with('taughtClasses.level')
            ->orderBy('name')
            ->get();

        return view('admin.students-results.teachers.list', [
            'page' => collect(StudentsResultsController::TEACHER_PAGES)->firstWhere('key', 'teachers-list'),
            'teachers' => $teachers,
            // What each removal takes with it, so the page can say so in the confirmation
            // instead of the office finding out from a blank column weeks later. Read from
            // the relation the list has already loaded.
            'held' => $teachers
                ->mapWithKeys(fn (User $teacher) => [$teacher->id => $this->register->heldClassesFor($teacher)])
                ->all(),
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
            // Optional: not every teacher has an address the school knows, and the
            // phone number is what they sign in with.
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            // Required, and not merely preferred: it is the number they sign in with,
            // and the one the school texts them on.
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'email.unique' => 'That email already has an account. Staff & roles is where an account that exists is changed.',
            'phone.required' => 'A phone number is needed: it is the number the teacher signs in with.',
            'phone.unique' => 'That phone number is already the sign-in number for another account.',
        ]);

        $teacher = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => PhoneNumber::normalize($validated['phone']),
            // A photograph is added once they have been taken on, so it is asked for
            // and not required: the record matters more than the picture.
            'avatar_path' => $request->file('avatar')?->store(config('saci.uploads.photos').'/teachers', 'public'),
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        $teacher->assignRole('Teacher');

        return redirect()
            ->route('admin.students-results.teachers.list')
            ->with('status', "{$teacher->name} added as a teacher. The password is theirs to keep.");
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
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($teacher->id)],
            'phone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($teacher->id)],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'email.unique' => 'That email belongs to another account.',
            'phone.required' => 'A phone number is needed: it is the number the teacher signs in with.',
            'phone.unique' => 'That phone number is already the sign-in number for another account.',
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
            'email' => $validated['email'] ?? null,
            'phone' => PhoneNumber::normalize($validated['phone']),
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

    /* ------------------------------------------------------------------ */
    /* Removing a teacher */
    /* ------------------------------------------------------------------ */

    /**
     * Take one teacher off the register.
     *
     * A teacher who has left is normally deactivated, which keeps the record and
     * takes away the login. Deleting is for the record that should not be there at
     * all — a duplicate, somebody taken on and never taken up, a name typed by
     * mistake — so it asks no questions beyond whose account it is. The classes they
     * were class teacher of are named in the confirmation before this runs, and named
     * again in the flash, because those classes are left without one.
     */
    public function destroy(User $teacher): RedirectResponse
    {
        $this->authorize('teachers.manage');

        $this->assertIsTeacher($teacher);

        if (($refusal = $this->refusal($teacher)) !== null) {
            return back()->with('error', $refusal);
        }

        $classes = $this->register->heldClassesFor($teacher);

        $this->register->delete($teacher);

        $message = "{$teacher->name} removed from the teaching staff.";

        if ($classes !== '') {
            $message .= " They were the class teacher of {$classes} — give those classes somebody else.";
        }

        return redirect()
            ->route('admin.students-results.teachers.list')
            ->with('status', $message);
    }

    /**
     * Take several off at once, which is what the end of a session looks like.
     *
     * One account that cannot go does not stop the rest, and whoever was left behind
     * is named with the reason: an office that ticks six boxes and sees five leave
     * has to be told which one stayed.
     */
    public function destroySelected(Request $request): RedirectResponse
    {
        $this->authorize('teachers.manage');

        $validated = $request->validate([
            'teachers' => ['required', 'array', 'min:1'],
            'teachers.*' => ['integer'],
        ], [
            'teachers.required' => 'Tick the teacher(s) to remove.',
            'teachers.min' => 'Tick the teacher(s) to remove.',
        ]);

        $teachers = User::query()
            ->role('Teacher')
            ->whereIn('id', $validated['teachers'])
            ->orderBy('name')
            ->get();

        if ($teachers->isEmpty()) {
            return back()->with('error', 'None of those accounts is on the teachers register.');
        }

        $removed = [];
        $freed = [];
        $kept = [];

        foreach ($teachers as $teacher) {
            if (($refusal = $this->refusal($teacher)) !== null) {
                $kept[] = "{$teacher->name} — {$refusal}";

                continue;
            }

            $classes = $this->register->heldClassesFor($teacher);

            $this->register->delete($teacher);

            $removed[] = $teacher->name;

            if ($classes !== '') {
                $freed[] = $classes;
            }
        }

        if ($removed === []) {
            return back()->with('error', 'Nobody was removed. '.implode('; ', $kept).'.');
        }

        $message = count($removed) === 1
            ? "{$removed[0]} removed from the teaching staff."
            : count($removed).' teachers removed: '.implode(', ', $removed).'.';

        if ($freed !== []) {
            $message .= ' Class teacher of '.implode('; ', $freed).' — those classes need somebody new.';
        }

        if ($kept !== []) {
            $message .= ' Left alone: '.implode('; ', $kept).'.';
        }

        return back()->with('status', $message);
    }

    /**
     * Why this account cannot be removed by the person removing it.
     *
     * Only one thing stops it: deleting the account you are signed in as would take
     * the session out from under you mid-request. Everything else — a class they
     * still hold — is a warning, not a reason to refuse.
     */
    private function refusal(User $teacher): ?string
    {
        return $teacher->is(auth()->user())
            ? 'that is the account you are signed in with'
            : null;
    }

    /* ------------------------------------------------------------------ */
    /* Bulk upload */
    /* ------------------------------------------------------------------ */

    public function import(Request $request): View
    {
        $this->authorize('teachers.manage');

        return view('admin.students-results.teachers.import', [
            'page' => collect(StudentsResultsController::TEACHER_PAGES)->firstWhere('key', 'teachers-list'),
            'columns' => $this->imports->allColumns(),
            'staged' => $request->session()->get(self::IMPORT_SESSION_KEY),
        ]);
    }

    /** Parse the uploaded sheet and show exactly which accounts would be opened. */
    public function previewImport(Request $request): RedirectResponse
    {
        $this->authorize('teachers.manage');

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:8192'],
        ], [
            'file.required' => 'Choose the spreadsheet of teachers.',
            'file.mimes' => 'Upload a CSV or Excel file (.csv, .xlsx or .xls).',
            'file.max' => 'That file is larger than 8 MB. Split it into smaller batches.',
        ]);

        try {
            $parsed = $this->imports->parse($request->file('file'));
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        if ($parsed['rows'] === []) {
            return back()->withErrors([
                'file' => 'No teacher rows were found in that file. Each row needs a name and a phone number.',
            ]);
        }

        $request->session()->put(self::IMPORT_SESSION_KEY, $parsed + [
            'filename' => $request->file('file')->getClientOriginalName(),
            'staged_at' => now()->toIso8601String(),
        ]);

        $message = sprintf(
            '%d row(s) read — %d ready, %d need fixing.',
            $parsed['summary']['total'],
            $parsed['summary']['ok'],
            $parsed['summary']['errors'],
        );

        if ($parsed['truncated']) {
            $message .= sprintf(
                ' Only the first %d rows were read; split the rest into another file.',
                TeacherImportService::MAX_ROWS,
            );
        }

        return redirect()
            ->route('admin.students-results.teachers.import')
            ->with($parsed['summary']['ok'] > 0 ? 'status' : 'error', $message);
    }

    /**
     * Open the accounts for the rows the office left ticked.
     *
     * No password is asked for. Each account is given one of its own, and the plain
     * ones are flashed to the next screen to be written down and handed over; nothing
     * is stored, so that screen is the only chance to read them. Every account is
     * still forced to change it the first time it is signed in with, so a sheet of
     * paper is not the way in a term later.
     */
    public function commitImport(Request $request): RedirectResponse
    {
        $this->authorize('teachers.manage');

        $staged = $request->session()->get(self::IMPORT_SESSION_KEY);

        if (! $staged) {
            return redirect()
                ->route('admin.students-results.teachers.import')
                ->with('error', 'That upload has expired. Please choose the file again.');
        }

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['integer'],
        ], [
            'lines.required' => 'Tick at least one teacher to add.',
            'lines.min' => 'Tick at least one teacher to add.',
        ]);

        $result = $this->imports->commit(
            $staged['rows'],
            $validated['lines'],
            $request->user(),
        );

        $request->session()->forget(self::IMPORT_SESSION_KEY);

        if ($result['created'] === 0) {
            return redirect()
                ->route('admin.students-results.teachers.import')
                ->with('error', 'No teacher was added. '.collect($result['failed'])->pluck('reason')->unique()->implode(' '));
        }

        $message = sprintf('%d teacher(s) added as teachers.', $result['created']);

        if ($result['failed'] !== []) {
            $message .= ' '.count($result['failed']).' row(s) could not be added and were skipped.';
        }

        return redirect()
            ->route('admin.students-results.teachers.import.done')
            ->with(self::IMPORT_CREDENTIALS_KEY, $result['credentials'])
            ->with('status', $message.' Print them now and hand each card over.');
    }

    /**
     * The handover: a card per teacher, to print and cut up.
     *
     * Reached from the commit and nowhere else, and readable for that one request.
     * A refresh, a bookmark or the back button finds nothing, which is what "shown
     * once" has to mean when the passwords are never written down anywhere.
     *
     * A card rather than a row in a table: the password has to leave the screen to
     * reach the teacher, and a printed card that carries the school's own heading is
     * the difference between something handed over at the gate and something copied
     * out by hand.
     */
    public function importDone(Request $request): View
    {
        $this->authorize('teachers.manage');

        return view('admin.students-results.teachers.import-done', [
            'credentials' => $request->session()->get(self::IMPORT_CREDENTIALS_KEY, []),
            'academicSession' => AcademicSession::current(),
        ]);
    }

    /**
     * Give a teacher a new password, to be read out once.
     *
     * The way back from a password that never reached anybody: the import shows each
     * one a single time and keeps none of them, so this makes another rather than
     * trying to recall the first. It lands on the same handover screen and prints on
     * the same card, and the one it replaces stops working.
     */
    public function resetPassword(User $teacher): RedirectResponse
    {
        $this->authorize('teachers.manage');

        $this->assertIsTeacher($teacher);

        $password = $this->register->issuePassword($teacher);

        return redirect()
            ->route('admin.students-results.teachers.import.done')
            ->with(self::IMPORT_CREDENTIALS_KEY, [[
                'name' => $teacher->name,
                'phone' => $teacher->phone,
                'password' => $password,
            ]])
            ->with('status', "A new password was made for {$teacher->name}. Read it out now — it is not stored.");
    }

    /** The sheet to fill in, with the headings the reader looks for. */
    public function downloadTemplate(): Response
    {
        $this->authorize('teachers.manage');

        $rows = [
            $this->imports->templateHeaders(),
            ['Chidera Okafor', '08031234567'],
        ];

        $handle = fopen('php://temp', 'r+');

        // A UTF-8 byte-order mark, or Excel mangles accented names.
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="teacher-import-template.csv"',
        ]);
    }
}
