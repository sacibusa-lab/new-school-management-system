<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImportDriver;
use App\Enums\ScoreImportRowStatus;
use App\Exceptions\ScoresheetExtractionException;
use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\ScoreImport;
use App\Models\ScoreImportRow;
use App\Services\Import\ScoreImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The review desk for uploaded scoresheets — whether they arrived as Excel, or
 * were read off a photograph by AI. Nothing is written until "Commit".
 */
class ScoreImportController extends Controller
{
    public function __construct(
        private readonly ScoreImportService $imports,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('scores.import');

        // A subject only makes sense for the examination it belongs to, so the
        // list is scoped to the chosen examination. Without a choice, every paper
        // is listed under its examination's name rather than as a bare name that
        // could come from anywhere.
        $selectedExam = $request->integer('exam');

        $subjectOptions = ExamSubject::query()
            ->with(['subject', 'exam'])
            ->when($selectedExam, fn ($query) => $query->where('exam_id', $selectedExam))
            ->get()
            ->mapWithKeys(fn (ExamSubject $paper) => [
                $paper->id => trim(
                    ($selectedExam ? '' : ($paper->exam?->title . ' — ')) . ($paper->subject?->name ?? 'Subject'),
                ),
            ])
            ->all();

        return view('admin.imports.index', [
            'imports' => ScoreImport::query()
                ->with(['exam.level', 'examSubject.subject', 'uploader', 'committer'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('exam'), fn ($q) => $q->where('exam_id', $request->integer('exam')))
                ->latest()
                ->paginate(15)
                ->withQueryString(),
            'exams' => Exam::query()->with('level')->orderByDesc('id')->get(),
            'subjectOptions' => $subjectOptions,
            'drivers' => ImportDriver::options(),
            'aiConfigured' => app(\App\Services\Import\AiVisionScoresheetExtractor::class)->isConfigured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('scores.import');

        $validated = $request->validate([
            'exam_id' => ['required', 'exists:exams,id'],
            'exam_subject_id' => ['nullable', 'exists:exam_subjects,id'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,xlsx,xls,csv,txt', 'max:12288'],
        ], [
            'file.mimes' => 'Upload an Excel or CSV file, or a photo/scan of the marked sheet (JPG, PNG, WEBP, PDF).',
            'file.max' => 'The file may not be larger than 12 MB.',
        ]);

        $exam = Exam::findOrFail($validated['exam_id']);

        $import = $this->imports->store(
            $exam,
            $validated['exam_subject_id'] ? $exam->examSubjects()->find($validated['exam_subject_id']) : null,
            $request->file('file'),
            $request->user(),
        );

        if ($import->driver === ImportDriver::ManualGrid) {
            return redirect()
                ->route('admin.imports.show', $import)
                ->with('error', 'This file type cannot be read automatically. Configure AI scoring (AI_PROVIDER / AI_API_KEY in .env) to read photographs, or type the scores in by hand.');
        }

        return $this->processAndRedirect($import);
    }

    public function show(ScoreImport $import): View
    {
        $this->authorize('scores.import');

        return $this->reviewView($import);
    }

    public function process(ScoreImport $import): RedirectResponse
    {
        $this->authorize('scores.import');

        return $this->processAndRedirect($import);
    }

    protected function processAndRedirect(ScoreImport $import): RedirectResponse
    {
        try {
            $this->imports->process($import);
        } catch (ScoresheetExtractionException $e) {
            return redirect()
                ->route('admin.imports.show', $import)
                ->with('error', $e->getMessage());
        }

        $import->refresh();

        if ($import->status === \App\Enums\ScoreImportStatus::Failed) {
            return redirect()
                ->route('admin.imports.show', $import)
                ->with('error', $import->error);
        }

        return redirect()
            ->route('admin.imports.show', $import)
            ->with('status', "Read {$import->rows_total} row(s) from {$import->original_name}. Review the matches below, then commit.");
    }

    /** Accept a hand correction from the review grid. */
    public function resolveRow(Request $request, ScoreImport $import, ScoreImportRow $row): RedirectResponse
    {
        $this->authorize('scores.import');

        abort_unless($row->score_import_id === $import->id, 404);

        $validated = $request->validate([
            'action' => ['required', 'in:save,ignore,accept'],
            'matched_applicant_id' => ['nullable', 'exists:applicants,id'],
            'raw_score' => ['nullable', 'numeric', 'min:0'],
            'raw_name' => ['nullable', 'string', 'max:150'],
            'exam_subject_id' => ['nullable', 'exists:exam_subjects,id'],
        ]);

        if ($validated['action'] === 'ignore') {
            $this->imports->ignore($row);

            return back()->with('status', "Row {$row->row_number} skipped.");
        }

        // "accept" keeps the machine's match but marks it reviewed; "save"
        // applies whatever the officer typed.
        $changes = [
            'matched_applicant_id' => $validated['matched_applicant_id'] ?? $row->matched_applicant_id,
            'raw_score' => $validated['raw_score'] ?? $row->raw_score,
            'raw_name' => $validated['raw_name'] ?? $row->raw_name,
            'exam_subject_id' => $validated['exam_subject_id'] ?? $row->exam_subject_id,
        ];

        $this->imports->applyCorrection($row, $changes);
        $import->refreshCounters();

        return back()->with('status', "Row {$row->row_number} updated.");
    }

    public function commit(Request $request, ScoreImport $import): RedirectResponse
    {
        $this->authorize('scores.import');

        if ($import->status === \App\Enums\ScoreImportStatus::Committed) {
            return back()->with('error', 'This import has already been committed.');
        }

        $result = $this->imports->commit($import, $request->user());

        if ($result['written'] === 0) {
            return back()->with('error', 'Nothing to commit — no row has both a student and a score. Resolve the flagged rows first.');
        }

        // A sheet read off a photograph is a guess until somebody signs it off, so
        // say plainly that the marks are held back rather than implying they count.
        $tail = $import->driver->isImageBased()
            ? ' They were read by machine, so they are held back until they are verified.'
            : '';

        return redirect()
            ->route('admin.imports.show', $import)
            ->with('status', "{$result['written']} score(s) committed to the examination."
                . ($result['skipped'] > 0 ? " {$result['skipped']} row(s) were skipped." : '')
                . $tail);
    }

    public function destroy(ScoreImport $import): RedirectResponse
    {
        $this->authorize('scores.import');

        if ($import->status === \App\Enums\ScoreImportStatus::Committed) {
            return back()->with('error', 'Committed imports are kept for audit. Reverse the scores instead.');
        }

        $import->delete();

        return redirect()
            ->route('admin.imports.index')
            ->with('status', 'Upload discarded.');
    }

    /* ------------------------------------------------------------------ */

    protected function reviewView(ScoreImport $import): View
    {
        $import->load(['exam.level', 'examSubject.subject', 'uploader', 'committer']);

        $rows = $import->rows()
            ->with(['matchedApplicant', 'examSubject.subject'])
            ->orderBy('row_number')
            ->get();

        // Candidates offered in the "assign student" dropdown. Alphabetical,
        // because the office is hunting for a name, not a number.
        $candidates = Applicant::query()
            ->where('academic_session_id', $import->exam->academic_session_id)
            ->when($import->exam->level_id, fn ($q) => $q->where('level_applied_for_id', $import->exam->level_id))
            ->inNameOrder()
            ->get();

        return view('admin.imports.show', [
            'import' => $import,
            'rows' => $rows,
            'summary' => $this->imports->summary($import),
            'candidates' => $candidates,
            'examSubjects' => $import->exam->examSubjects()->with('subject')->get(),
            'rowStatuses' => ScoreImportRowStatus::options(),
            'driverLabel' => $import->driver->label(),
        ]);
    }
}
