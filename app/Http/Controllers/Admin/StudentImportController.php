<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Services\Students\StudentImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Multiple import: taking a class of children onto the roll from a spreadsheet.
 *
 * Two steps, and the second one is the point. The office picks the class, uploads the
 * file, and is shown every child it read with the line each one came from — nothing has
 * been created at that moment and the file is not kept. Only the rows left ticked on that
 * second screen are written.
 *
 * The class is chosen here rather than read from the sheet. It is what the office is
 * doing: JSS1A's list has arrived, and all of it is JSS1A. A class column in the file
 * would be a second way of saying the same thing, and the two would disagree the first
 * time somebody filled one in wrongly.
 *
 * It answers to `students.import`, not to the register's `students.view`: reading a class
 * list is not the same authority as creating a hundred children at once.
 */
class StudentImportController extends Controller
{
    /** Where the read rows wait between the preview and the commit. */
    private const SESSION_KEY = 'student_import';

    public function __construct(private readonly StudentImportService $imports) {}

    public function index(Request $request): View
    {
        $this->authorize('students.import');

        return view('admin.students-results.students.multiple-import', [
            'columns' => $this->imports->allColumns(),
            // `classes` comes with them because the page reads which arms each year group
            // runs, to offer only the sections that actually exist.
            'levels' => SchoolLevel::query()
                ->active()
                ->with('classes:id,level_id,section_id')
                ->orderBy('order')
                ->orderBy('name')
                ->get(),
            'sections' => Section::query()->orderBy('order')->orderBy('name')->get(),
            'staged' => $request->session()->get(self::SESSION_KEY),
            'maxRows' => StudentImportService::MAX_ROWS,
        ]);
    }

    /**
     * Read the sheet and show exactly which children it holds.
     *
     * The class is resolved to a real one before the file is looked at: JSS1 and C are
     * JSS1C, and if the school has not created that arm there is nothing to import into,
     * which is worth saying before somebody reads three hundred names.
     */
    public function preview(Request $request): RedirectResponse
    {
        $this->authorize('students.import');

        $validated = $request->validate([
            'level_id' => ['required', 'integer', Rule::exists('school_levels', 'id')->where('is_active', true)],
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:8192'],
        ], [
            'level_id.required' => 'Choose the class these children are going into.',
            'level_id.exists' => 'That year group is not one the school is offering. Choose another.',
            'section_id.required' => 'Choose the section as well — JSS1 and A are JSS1A.',
            'file.required' => 'Choose the file of children.',
            'file.mimes' => 'Upload a CSV or Excel file (.csv, .xlsx or .xls).',
            'file.max' => 'That file is larger than 8 MB. Split it into smaller batches.',
        ]);

        $level = SchoolLevel::query()->findOrFail($validated['level_id']);
        $section = Section::query()->findOrFail($validated['section_id']);

        $class = SchoolClass::query()->forArm($level, $section)->first();

        if ($class === null) {
            return back()->withInput()->withErrors([
                'section_id' => "{$level->name}{$section->name} is not a class yet. Create it on the Classes & Sections page first.",
            ]);
        }

        try {
            $parsed = $this->imports->parse($request->file('file'), $class);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        }

        if ($parsed['rows'] === []) {
            return back()->withInput()->withErrors([
                'file' => 'No children were found in that file. Each row needs a surname and a first name.',
            ]);
        }

        $request->session()->put(self::SESSION_KEY, $parsed + [
            'level_id' => $level->id,
            'section_id' => $section->id,
            'class_id' => $class->id,
            'class_name' => $class->name,
            'staged_at' => now()->toIso8601String(),
        ]);

        $message = sprintf(
            '%d row(s) read for %s — %d ready, %d need fixing.',
            $parsed['summary']['total'],
            $class->name,
            $parsed['summary']['ok'],
            $parsed['summary']['errors'],
        );

        if ($parsed['truncated']) {
            $message .= sprintf(
                ' Only the first %d rows were read; add these, then upload the rest as a second file.',
                StudentImportService::MAX_ROWS,
            );
        }

        return redirect()
            ->route('admin.students-results.students.multiple-import')
            ->with($parsed['summary']['ok'] > 0 ? 'status' : 'error', $message);
    }

    /**
     * Add the children the office left ticked, and land on their new class list.
     *
     * The register rather than back here, because the question after an import is
     * always "did they go in, and who are they?" — and the register filtered to that
     * class is the answer, with the number each child was given against their name.
     *
     * Every child added this way is given a portal login as well, the same as an
     * admission gives them, so the results and fees screens work from the first day.
     * There is no sheet of passwords to hand over: a student signs in with their
     * admission number, which is printed on the list this lands on.
     */
    public function commit(Request $request): RedirectResponse
    {
        $this->authorize('students.import');

        $staged = $request->session()->get(self::SESSION_KEY);

        if (! $staged) {
            return redirect()
                ->route('admin.students-results.students.multiple-import')
                ->with('error', 'That upload has expired. Please choose the file again.');
        }

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['integer'],
        ], [
            'lines.required' => 'Tick at least one child to add.',
            'lines.min' => 'Tick at least one child to add.',
        ]);

        $level = SchoolLevel::query()->find($staged['level_id']);
        $class = SchoolClass::query()->find($staged['class_id']);
        $session = AcademicSession::current();

        $register = redirect()->route('admin.students-results.students', [
            'class' => $staged['level_id'],
            'section' => $staged['section_id'],
        ]);

        if ($level === null || $class === null || $session === null) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()
                ->route('admin.students-results.students.multiple-import')
                ->with('error', 'That class is not there any more. Start again and choose the class.');
        }

        $result = $this->imports->commit($staged, $validated['lines'], $level, $class, $session);

        $request->session()->forget(self::SESSION_KEY);

        if ($result['created'] === 0) {
            return redirect()
                ->route('admin.students-results.students.multiple-import')
                ->with('error', 'Nobody was added. '.collect($result['failed'])->pluck('reason')->unique()->implode(' '));
        }

        $message = sprintf(
            '%d child(ren) added to %s, each with a portal login. They sign in with their admission number.',
            $result['created'],
            $class->name,
        );

        if ($result['failed'] !== []) {
            $message .= ' '.count($result['failed']).' row(s) could not be added and were skipped.';
        }

        return $register->with('status', $message);
    }

    /**
     * The sheet to fill in, with the headings the reader looks for.
     *
     * A byte-order mark is written first, or Excel reads the file as Latin-1 and mangles
     * every accented surname in it — which, in this school, is most of them.
     */
    public function template(): Response
    {
        $this->authorize('students.import');

        $rows = [
            $this->imports->templateHeaders(),
            ['Okafor', 'Chidera', 'Ada', 'F', '12/03/2013', 'Mrs Ngozi Okafor', '08039876543', 'ngozi@example.com', '12 Ogbeh Street, Ibusa'],
        ];

        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="student-import-sample.csv"',
        ]);
    }
}
