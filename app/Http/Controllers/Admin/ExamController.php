<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Score;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ExamController extends Controller
{
    /**
     * A scoresheet for this examination, ready to be filled in and uploaded back.
     *
     * One row per registered candidate and one column per paper, headed with the
     * paper's own name — the same shape as the score entry grid, and the shape the
     * importer reads back. The candidates are pre-filled so nobody has to key the
     * numbers in twice, and a blank cell is read as "not marked yet" rather than as
     * an absence.
     */
    public function scoresheetTemplate(Exam $exam): Response
    {
        $this->authorize('scores.import');

        $exam->load('academicSession');

        $papers = $exam->examSubjects()->with('subject')->get();

        $candidates = Applicant::query()
            ->whereIn('id', Score::query()->where('exam_id', $exam->id)->select('applicant_id'))
            ->orderBy('registration_number')
            ->get();

        $handle = fopen('php://temp', 'r+');

        // A UTF-8 byte-order mark, or Excel mangles accented names.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, array_merge(
            ['Registration number', 'Name'],
            $papers->map(fn (ExamSubject $paper) => $paper->subject?->name)?->all() ?? [],
        ));

        foreach ($candidates as $candidate) {
            fputcsv($handle, array_merge(
                [$candidate->registration_number, $candidate->full_name],
                array_fill(0, $papers->count(), ''),
            ));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="scoresheet-' . Str::slug($exam->title) . '.csv"',
        ]);
    }

    public function index(): View
    {
        $this->authorize('viewAny', Exam::class);

        return view('admin.exams.index', [
            'exams' => Exam::query()
                ->with(['level', 'academicSession'])
                ->withCount(['examSubjects', 'scores'])
                ->orderByDesc('exam_date')
                ->orderByDesc('id')
                ->paginate(15),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Exam::class);

        $standardPapers = $this->standardPaperIds();

        return view('admin.exams.create', [
            'levels' => SchoolLevel::query()->active()->get(),
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(),
            'subjects' => $this->subjectsForPicking($standardPapers),
            'standardPapers' => $standardPapers,
            'statuses' => ExamStatus::options(),
        ]);
    }

    /**
     * The entrance papers configured by the school (Maths, English, General
     * Paper by default), resolved to subject IDs.
     *
     * @return array<int,int>
     */
    protected function standardPaperIds(): array
    {
        $codes = Setting::get('entrance_exam_subjects', ['MTH', 'ENG', 'GPR']);

        if (is_string($codes)) {
            $codes = json_decode($codes, true) ?: [];
        }

        $codes = array_filter(array_map('strval', (array) $codes));

        if ($codes === []) {
            return [];
        }

        // Keep the order the school listed them in rather than the DB order.
        $ids = Subject::query()->whereIn('code', $codes)->pluck('id', 'code');

        return collect($codes)
            ->map(fn (string $code) => $ids[$code] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Subject list for the picker: the standard papers first, then the rest.
     *
     * @param  array<int,int>  $standardPapers
     */
    protected function subjectsForPicking(array $standardPapers)
    {
        $subjects = Subject::query()->active()->get();

        if ($standardPapers === []) {
            return $subjects;
        }

        return $subjects
            ->sortBy(fn (Subject $subject) => [
                array_search($subject->id, $standardPapers, true) === false ? 1 : 0,
                array_search($subject->id, $standardPapers, true) === false
                    ? $subject->name
                    : (string) array_search($subject->id, $standardPapers, true),
            ])
            ->values();
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Exam::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'level_id' => ['required', 'exists:school_levels,id'],
            'exam_date' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'venue' => ['nullable', 'string', 'max:120'],
            'cutoff_mark' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'total_marks' => ['required', 'numeric', 'min:1', 'max:1000'],
            'pass_mark' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'status' => ['required', Rule::enum(ExamStatus::class)],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'subjects' => ['required', 'array', 'min:1'],
            'subjects.*' => ['integer', 'exists:subjects,id'],
        ]);

        $exam = DB::transaction(function () use ($validated, $request) {
            $exam = Exam::create([
                'title' => $validated['title'],
                'academic_session_id' => $validated['academic_session_id'],
                'level_id' => $validated['level_id'],
                'exam_date' => $validated['exam_date'] ?? null,
                'starts_at' => $validated['starts_at'] ?? null,
                'venue' => $validated['venue'] ?? null,
                'cutoff_mark' => $validated['cutoff_mark'] ?? null,
                'status' => $validated['status'],
                'instructions' => $validated['instructions'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['subjects'] as $index => $subjectId) {
                $exam->examSubjects()->create([
                    'subject_id' => $subjectId,
                    'total_marks' => $validated['total_marks'],
                    'pass_mark' => $validated['pass_mark'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            return $exam;
        });

        return redirect()
            ->route('admin.exams.show', $exam)
            ->with('status', 'Examination created. Register the candidates to start capturing scores.');
    }

    public function show(Exam $exam): View
    {
        $this->authorize('view', $exam);

        $exam->load(['level', 'academicSession', 'creator']);

        $examSubjects = $exam->examSubjects()->with('subject')->get();

        $progress = $examSubjects->map(function (ExamSubject $examSubject) {
            $total = $examSubject->scores()->count();
            $captured = $examSubject->scores()->whereNotNull('score')->count();

            return [
                'examSubject' => $examSubject,
                'candidates' => $total,
                'captured' => $captured,
                'percent' => $total > 0 ? (int) round(($captured / $total) * 100) : 0,
            ];
        });

        $candidateCount = $exam->scores()->distinct('applicant_id')->count('applicant_id');

        return view('admin.exams.show', [
            'exam' => $exam,
            'examSubjects' => $examSubjects,
            'progress' => $progress,
            'candidateCount' => $candidateCount,
            'candidates' => $this->candidateList($exam),
            'availableSubjects' => Subject::query()
                ->active()
                ->whereNotIn('id', $examSubjects->pluck('subject_id'))
                ->get(),
            'imports' => $exam->imports()->with(['examSubject.subject', 'uploader'])->latest()->limit(8)->get(),
        ]);
    }

    /**
     * Who is sitting this examination, and how much of their marking is in.
     *
     * Two queries rather than one per candidate: the marks for the whole sitting
     * are fetched once and grouped, so a paper with 500 candidates does not fire
     * 500 lookups.
     *
     * @return \Illuminate\Support\Collection<int,array{applicant:Applicant,marked:int,total:int,percent:int}>
     */
    protected function candidateList(Exam $exam)
    {
        $scoresByApplicant = Score::query()
            ->where('exam_id', $exam->id)
            ->get(['applicant_id', 'score'])
            ->groupBy('applicant_id');

        if ($scoresByApplicant->isEmpty()) {
            return collect();
        }

        return Applicant::query()
            ->whereIn('id', $scoresByApplicant->keys())
            ->orderBy('registration_number')
            ->get()
            ->map(function (Applicant $applicant) use ($scoresByApplicant) {
                $rows = $scoresByApplicant[$applicant->id];
                $total = $rows->count();
                $marked = $rows->whereNotNull('score')->count();

                return [
                    'applicant' => $applicant,
                    'marked' => $marked,
                    'total' => $total,
                    'percent' => $total > 0 ? (int) round(($marked / $total) * 100) : 0,
                ];
            });
    }

    public function edit(Exam $exam): View
    {
        $this->authorize('update', $exam);

        return view('admin.exams.edit', [
            'exam' => $exam,
            'levels' => SchoolLevel::query()->active()->get(),
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(),
            'statuses' => ExamStatus::options(),
        ]);
    }

    public function update(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('update', $exam);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'level_id' => ['required', 'exists:school_levels,id'],
            'exam_date' => ['nullable', 'date'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'venue' => ['nullable', 'string', 'max:120'],
            'cutoff_mark' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'status' => ['required', Rule::enum(ExamStatus::class)],
            'instructions' => ['nullable', 'string', 'max:3000'],
            'results_locked' => ['nullable', 'boolean'],
        ]);

        $exam->update($validated + [
            'results_locked' => $request->boolean('results_locked'),
            'published_at' => $validated['status'] === ExamStatus::Published->value
                ? ($exam->published_at ?? now())
                : $exam->published_at,
        ]);

        return redirect()
            ->route('admin.exams.show', $exam)
            ->with('status', 'Examination updated.');
    }

    public function destroy(Exam $exam): RedirectResponse
    {
        $this->authorize('delete', $exam);

        if ($exam->decisions()->exists()) {
            return back()->with('error', 'This examination already has admission decisions attached and cannot be deleted.');
        }

        $exam->delete();

        return redirect()
            ->route('admin.exams.index')
            ->with('status', 'Examination deleted.');
    }

    /* ------------------------------------------------------------------ */
    /* Subjects                                                            */
    /* ------------------------------------------------------------------ */

    public function storeSubject(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('update', $exam);

        $validated = $request->validate([
            'subject_id' => [
                'required',
                Rule::exists('subjects', 'id'),
                Rule::unique('exam_subjects', 'subject_id')->where('exam_id', $exam->id),
            ],
            'total_marks' => ['required', 'numeric', 'min:1', 'max:1000'],
            'pass_mark' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $exam->examSubjects()->create($validated + [
            'sort_order' => $exam->examSubjects()->max('sort_order') + 1,
        ]);

        // Give every already-registered candidate a blank row for the new subject.
        $this->backfillScoresForSubject($exam, $exam->examSubjects()->latest('id')->first());

        return back()->with('status', 'Subject added to the examination.');
    }

    public function updateSubject(Request $request, Exam $exam, ExamSubject $examSubject): RedirectResponse
    {
        $this->authorize('update', $exam);

        abort_unless($examSubject->exam_id === $exam->id, 404);

        $validated = $request->validate([
            'total_marks' => ['required', 'numeric', 'min:1', 'max:1000'],
            'pass_mark' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);

        $examSubject->update($validated);

        return back()->with('status', 'Subject updated.');
    }

    public function destroySubject(Exam $exam, ExamSubject $examSubject): RedirectResponse
    {
        $this->authorize('update', $exam);

        abort_unless($examSubject->exam_id === $exam->id, 404);

        if ($examSubject->scores()->whereNotNull('score')->exists()) {
            return back()->with('error', 'Scores already exist for this subject. Clear them before removing it.');
        }

        $examSubject->delete();

        return back()->with('status', 'Subject removed from the examination.');
    }

    /* ------------------------------------------------------------------ */
    /* Candidates                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Create a blank score row for every applicant sitting this exam, across
     * every subject. This is what makes the entry grid and imports possible.
     */
    public function syncCandidates(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('update', $exam);

        $exam->load('examSubjects');

        if ($exam->examSubjects->isEmpty()) {
            return back()->with('error', 'Add at least one subject before registering candidates.');
        }

        $applicants = Applicant::query()
            ->where('academic_session_id', $exam->academic_session_id)
            ->whereIn('status', [
                ApplicantStatus::Registered->value,
                ApplicantStatus::ExamScheduled->value,
                ApplicantStatus::ExamCompleted->value,
                ApplicantStatus::Shortlisted->value,
                ApplicantStatus::Admitted->value,
            ])
            ->when($exam->level_id, fn ($q) => $q->where('level_applied_for_id', $exam->level_id))
            ->get();

        if ($applicants->isEmpty()) {
            return back()->with('error', 'No applicants match this examination\'s level and session.');
        }

        $created = DB::transaction(function () use ($exam, $applicants) {
            $created = 0;

            foreach ($exam->examSubjects as $examSubject) {
                foreach ($applicants as $applicant) {
                    $score = Score::firstOrCreate(
                        [
                            'exam_subject_id' => $examSubject->id,
                            'applicant_id' => $applicant->id,
                        ],
                        [
                            'exam_id' => $exam->id,
                            'source' => \App\Enums\ScoreSource::Manual,
                        ],
                    );

                    if ($score->wasRecentlyCreated) {
                        $created++;
                    }
                }
            }

            // Mark everyone as expected to sit the exam.
            Applicant::query()
                ->whereIn('id', $applicants->pluck('id'))
                ->where('status', ApplicantStatus::Registered->value)
                ->update(['status' => ApplicantStatus::ExamScheduled->value]);

            return $created;
        });

        return back()->with('status', "{$applicants->count()} candidates registered — {$created} score slots created.");
    }

    protected function backfillScoresForSubject(Exam $exam, ?ExamSubject $examSubject): void
    {
        if (! $examSubject) {
            return;
        }

        $applicantIds = $exam->scores()->distinct()->pluck('applicant_id');

        foreach ($applicantIds as $applicantId) {
            Score::firstOrCreate(
                ['exam_subject_id' => $examSubject->id, 'applicant_id' => $applicantId],
                ['exam_id' => $exam->id],
            );
        }
    }
}
