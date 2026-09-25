<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Enums\ScoreSource;
use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\GradeScale;
use App\Models\Score;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Hand-typing scores from a marked paper. Bulk-saves a whole subject at once so
 * a teacher can work down the class list without a page reload per student.
 */
class ScoreEntryController extends Controller
{
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
        ]);
    }

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
        ]);
    }

    public function store(Request $request, Exam $exam, ExamSubject $examSubject): RedirectResponse
    {
        $this->authorize('scores.enter');

        abort_unless($examSubject->exam_id === $exam->id, 404);

        if (! $exam->isEditable()) {
            return back()->with('error', 'This examination is locked and can no longer be edited.');
        }

        $validated = $request->validate([
            'scores' => ['nullable', 'array'],
            'scores.*' => ['nullable', 'numeric', 'min:0', 'max:' . (float) $examSubject->total_marks],
            'absent' => ['nullable', 'array'],
            'absent.*' => ['nullable', 'boolean'],
        ], [
            'scores.*.max' => 'A score cannot be higher than the subject total of :max.',
            'scores.*.min' => 'A score cannot be negative.',
        ]);

        $max = (float) $examSubject->total_marks;
        $saved = 0;

        DB::transaction(function () use ($validated, $examSubject, $exam, $request, $max, &$saved) {
            $rows = Score::query()
                ->where('exam_subject_id', $examSubject->id)
                ->whereIn('id', array_keys($validated['scores'] ?? []))
                ->get()
                ->keyBy('id');

            foreach ($validated['scores'] ?? [] as $scoreId => $value) {
                $score = $rows->get((int) $scoreId);

                if (! $score) {
                    continue;
                }

                $isAbsent = (bool) ($validated['absent'][$scoreId] ?? false);
                $numeric = ($value === null || $value === '') ? null : (float) $value;

                // Nothing changed — leave the audit columns alone.
                if (! $isAbsent && $numeric === null && $score->score === null && ! $score->is_absent) {
                    continue;
                }

                $score->forceFill([
                    'score' => $isAbsent ? null : $numeric,
                    'is_absent' => $isAbsent,
                    'grade' => $isAbsent || $numeric === null
                        ? null
                        : GradeScale::gradeLetterFor(round(($numeric / $max) * 100, 2)),
                    'source' => ScoreSource::Manual,
                    'entered_by' => $request->user()->id,
                    // Hand-typed scores are trusted, so they are verified on save.
                    'verified_by' => $request->user()->id,
                    'verified_at' => now(),
                ])->save();

                $saved++;
            }

            // Anyone with a score recorded has now sat the paper.
            $applicantIds = $examSubject->scores()->whereNotNull('score')->pluck('applicant_id');

            \App\Models\Applicant::query()
                ->whereIn('id', $applicantIds)
                ->whereIn('status', [
                    ApplicantStatus::Registered->value,
                    ApplicantStatus::ExamScheduled->value,
                ])
                ->update(['status' => ApplicantStatus::ExamCompleted->value]);

            if ($exam->status === ExamStatus::Scheduled || $exam->status === ExamStatus::Ongoing) {
                $exam->update(['status' => ExamStatus::Marking]);
            }
        });

        return back()->with('status', $saved > 0
            ? "{$saved} score(s) saved for {$examSubject->subject?->name}."
            : 'No changes to save.');
    }
}
