<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Teachers\TeacherImportService;
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

    public function __construct(
        private readonly TeacherImportService $imports,
    ) {}

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
                'file' => 'No teacher rows were found in that file. Each row needs a name, a phone number and an email address.',
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
     * The password is asked for here rather than on the upload, and reaches the
     * office's hands rather than a file: every account made this way is forced to
     * change it the first time it is signed in with.
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
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'lines.required' => 'Tick at least one teacher to add.',
            'lines.min' => 'Tick at least one teacher to add.',
        ]);

        $result = $this->imports->commit(
            $staged['rows'],
            $validated['lines'],
            $validated['password'],
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
            ->route('admin.students-results.teachers.list')
            ->with('status', $message.' They change the password the first time they sign in.');
    }

    /** The sheet to fill in, with the headings the reader looks for. */
    public function downloadTemplate(): Response
    {
        $this->authorize('teachers.manage');

        $rows = [
            $this->imports->templateHeaders(),
            ['Chidera Okafor', '08031234567', 'chidera@example.com'],
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
