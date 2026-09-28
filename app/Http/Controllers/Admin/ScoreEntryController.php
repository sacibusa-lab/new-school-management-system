<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExamStatus;
use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\GradeScale;
use App\Models\Score;
use App\Services\Scores\ScoreEntryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Hand-typing marks from a marked paper.
 *
 * Two screens, one set of rules: the grid for keying a whole class across every
 * paper at once, and the single-subject screen for working through one paper at
 * a time. Both hand off to ScoreEntryService so a mark cannot be treated
differently depending on where it was typed.
 */
class ScoreEntryController extends Controller
{
    public function __construct(
        private readonly ScoreEntryService $entry,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Exam::class);

        $exams = Exam::query()
            ->with(['level', 'academicSession', 'examSubjects.subject'])
            ->whereIn('status', [
                ExamStatus::Scheduled->value,
                ExamStatus::Ongoing->value,
                ExamStatus::Marking->value,
                ExamStatus::AwaitingReview->value,
                ExamStatus::Completed->value,
            ])
            ->orderByDesc('exam_date')
            ->get();

        $selectedExam = $request->filled('exam')
            ? $exams->firstWhere('id', $request->integer('exam'))
            : $exams->first();

        return view('admin.scores.index', [
            'exams' => $exams,
            'selectedExam' => $selectedExam,
            'subjects' => $selectedExam?->examSubjects ?? collect(),
            'progress' => $selectedExam ? $this->progressFor($selectedExam) : collect(),
            'awaitingVerification' => $selectedExam && request()->user()?->can('scores.verify')
                ? $this->entry->awaitingCount($selectedExam)
                : 0,
        ]);
    }

    /**
     * How much of each paper has been captured, in two queries rather than two
     * per subject — this used to fire 30 queries for a 15-subject examination.
     *
     * @return \Illuminate\Support\Collection<int,object>
     */
    protected function progressFor(Exam $exam)
    {
        return Score::query()
            ->where('exam_id', $exam->id)
            ->selectRaw('exam_subject_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN score IS NOT NULL THEN 1 ELSE 0 END) as captured')
            ->selectRaw('SUM(CASE WHEN is_absent = 1 THEN 1 ELSE 0 END) as absent')
            ->groupBy('exam_subject_id')
            ->get()
            ->keyBy('exam_subject_id');
    }

    /** One paper at a time, for working down a single marked script stack. */
    public function entry(Exam $exam, ExamSubject $examSubject): View
    {
        $this->authorize('scores.enter');

        abort_unless($examSubject->exam_id === $exam->id, 404);

        $examSubject->load('subject');
        $exam->load(['level', 'academicSession']);

        $scores = $examSubject->scores()
            ->with(['applicant.levelAppliedFor', 'enteredBy'])
            ->join('applicants', 'applicants.id', '=', 'scores.applicant_id')
            ->orderBy('applicants.registration_number')
            ->select('scores.*')
            ->get();

        return view('admin.scores.entry', [
            'exam' => $exam,
            'examSubject' => $examSubject,
            'scores' => $scores,
            'gradeScale' => GradeScale::query()->orderByDesc('min_score')->get(),
            'editable' => $exam->isEditable(),
            'canOverride' => $this->canOverride(),
            'mayVerify' => (bool) request()->user()?->can('scores.verify'),
        ]);
    }

    /**
     * Every candidate against every paper, on one screen.
     *
     * Built for a clerk keying marks off paper: one save for the whole class, and
     * the value can be a mark or A for absent.
     */
    public function grid(Exam $exam): View
    {
        $this->authorize('scores.enter');

        $exam->load(['level', 'academicSession']);

        $scores = Score::query()
            ->where('exam_id', $exam->id)
            ->with('verifiedBy')
            ->get();

        return view('admin.scores.grid', [
            'exam' => $exam,
            'examSubjects' => $exam->examSubjects()->with('subject')->get(),
            'candidates' => Applicant::query()
                ->whereIn('id', $scores->pluck('applicant_id')->unique())
                ->with('levelAppliedFor')
                ->orderBy('registration_number')
                ->get(),
            // [applicant id][exam subject id] => the score row, so the view can
            // render a cell without hunting for it.
            'cells' => $scores->groupBy('applicant_id')
                ->map(fn ($rows) => $rows->keyBy('exam_subject_id')),
            'gradeScale' => GradeScale::query()->orderByDesc('min_score')->get(),
            'editable' => $exam->isEditable(),
            'canOverride' => $this->canOverride(),
            'mayVerify' => (bool) request()->user()?->can('scores.verify'),
        ]);
    }

    public function saveGrid(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('scores.enter');

        if (! $exam->isEditable()) {
            return back()->with('error', 'This examination is locked and no longer accepts score changes.');
        }

        $validated = $request->validate([
            'scores' => ['nullable', 'array'],
        ]);

        $result = $this->entry->save(
            $exam,
            $validated['scores'] ?? [],
            $request->user(),
            $this->canOverride(),
        );

        if ($result['errors'] !== []) {
            return back()->withErrors($result['errors'])->withInput();
        }

        return back()->with('status', $result['saved'] > 0
            ? $result['saved'] . ' mark(s) saved.'
            : 'No changes to save.');
    }

    /**
     * The verification desk: every mark on the examination that nobody has signed
     * off yet, grouped by paper.
     *
     * This exists because a machine-read sheet is a guess until a person agrees
     * with it. A spreadsheet somebody keyed by hand is a person's work already,
     * so it never lands here.
     */
    public function verify(Exam $exam): View
    {
        $this->authorize('scores.verify');

        $exam->load(['level', 'academicSession']);

        $awaiting = $this->entry->awaitingVerification($exam);
        $awaitingBySubject = $awaiting->groupBy('exam_subject_id');

        $verifiedBySubject = Score::query()
            ->where('exam_id', $exam->id)
            ->whereNotNull('verified_at')
            ->with(['applicant', 'verifiedBy'])
            ->get()
            ->groupBy('exam_subject_id');

        return view('admin.scores.verify', [
            'exam' => $exam,
            'awaiting' => $awaiting,
            'awaitingBySubject' => $awaitingBySubject,
            'verifiedBySubject' => $verifiedBySubject,
            // A paper gets a panel if it has anything to show. Restricting this to
            // papers that are still waiting would mean a signature that turns out
            // to be wrong could never be taken back once the queue had drained.
            'papers' => $exam->examSubjects()->with('subject')->get()
                ->filter(fn ($paper) => $awaitingBySubject->has($paper->id) || $verifiedBySubject->has($paper->id))
                ->keyBy('id'),
            'gradeScale' => GradeScale::query()->orderByDesc('min_score')->get(),
            'editable' => $exam->isEditable(),
            'totalScores' => Score::query()->where('exam_id', $exam->id)->count(),
        ]);
    }

    public function saveVerify(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('scores.verify');

        if (! $exam->isEditable()) {
            return back()->with('error', 'This examination is locked and no longer accepts score changes.');
        }

        $validated = $request->validate([
            'scores' => ['nullable', 'array'],
            'verify' => ['nullable', 'array'],
            'unverify' => ['nullable', 'array'],
            'exam_subject_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();

        // Corrections first: what gets signed off must be the corrected number,
        // never the machine's read and the officer's correction both counted.
        $result = $this->entry->save($exam, $validated['scores'] ?? [], $user, $this->canOverride());

        if ($result['errors'] !== []) {
            return back()->withErrors($result['errors'])->withInput();
        }

        // "Verify all on this paper" is the common case once an officer trusts a
        // whole sheet, so it is one click rather than one tick per candidate.
        $ids = array_keys($validated['verify'] ?? []);

        if ($request->boolean('verify_all') && ! empty($validated['exam_subject_id'])) {
            $ids = array_merge($ids, $this->entry->awaitingVerification($exam)
                ->where('exam_subject_id', $validated['exam_subject_id'])
                ->pluck('id')
                ->all());
        }

        // A correction typed by somebody who can verify is verified as it is saved,
        // so it is a sign-off too.
        $verified = $this->entry->verify($exam, $ids, $user) + $result['saved'];
        $released = $this->entry->unverify($exam, array_keys($validated['unverify'] ?? []));

        if ($verified === 0 && $released === 0) {
            return back()->with('status', 'Nothing was selected to verify.');
        }

        $parts = [];

        if ($verified > 0) {
            $parts[] = $verified . ' mark(s) verified';
        }

        if ($released > 0) {
            $parts[] = $released . ' sent back for another look';
        }

        return back()->with('status', implode(' and ', $parts) . '.');
    }

    public function store(Request $request, Exam $exam, ExamSubject $examSubject): RedirectResponse
    {
        $this->authorize('scores.enter');

        abort_unless($examSubject->exam_id === $exam->id, 404);

        if (! $exam->isEditable()) {
            return back()->with('error', 'This examination is locked and no longer accepts score changes.');
        }

        $validated = $request->validate([
            'scores' => ['nullable', 'array'],
            'absent' => ['nullable', 'array'],
        ]);

        // Fold the absent checkboxes into the same one-value-per-cell shape the
        // grid uses, so both screens share a single set of rules. A ticked box is
        // the letter A.
        $cells = $validated['scores'] ?? [];

        foreach (array_keys($validated['absent'] ?? []) as $scoreId) {
            $cells[$scoreId] = 'A';
        }

        $result = $this->entry->save($exam, $cells, $request->user(), $this->canOverride());

        if ($result['errors'] !== []) {
            return back()->withErrors($result['errors'])->withInput();
        }

        return back()->with('status', $result['saved'] > 0
            ? $result['saved'] . ' score(s) saved for ' . $examSubject->subject?->name . '.'
            : 'No changes to save.');
    }

    /** Correcting a signed-off mark is a different job from typing it in. */
    protected function canOverride(): bool
    {
        return (bool) request()->user()?->can('scores.override');
    }
}
