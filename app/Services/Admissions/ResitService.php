<?php

namespace App\Services\Admissions;

use App\Enums\ApplicantStatus;
use App\Enums\ExamStatus;
use App\Enums\ScoreSource;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Score;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resit handling.
 *
 * A resit is modelled as a brand new `Exam` row pointing back at the sitting it
 * repeats (`resit_of_exam_id`). That means re-marking, re-computing the merit
 * list and re-applying a cutoff all reuse the pipeline that already exists,
 * instead of a parallel "batch" concept that would need its own screens.
 *
 * Only the papers a candidate actually failed are copied across, so nobody
 * re-sits Mathematics because they fumbled English.
 */
class ResitService
{
    /** Admins can switch resits off entirely for a session. */
    public function isEnabled(): bool
    {
        return (bool) Setting::get('resit_enabled', true);
    }

    /**
     * The papers an applicant failed in a given sitting.
     *
     * A paper counts as failed when the candidate was absent, was never marked,
     * or scored below that subject's effective pass mark.
     *
     * @return Collection<int,ExamSubject>
     */
    public function failedSubjects(Exam $exam, Applicant $applicant): Collection
    {
        $scores = Score::query()
            ->where('exam_id', $exam->id)
            ->where('applicant_id', $applicant->id)
            ->with('examSubject.subject')
            ->get();

        return $scores
            ->filter(function (Score $score): bool {
                $subject = $score->examSubject;

                if (! $subject) {
                    return false;
                }

                if ($score->is_absent || $score->score === null) {
                    return true;
                }

                $percentage = $subject->toPercentage($score->score);

                return $percentage === null || $percentage < $subject->effectivePassMark();
            })
            ->map(fn (Score $score) => $score->examSubject)
            ->filter()
            ->values();
    }

    /**
     * Everybody still eligible to re-sit — they sat the paper but were never
     * admitted/enrolled.
     *
     * @return Collection<int,Applicant>
     */
    public function candidates(Exam $exam): Collection
    {
        $sat = Score::query()
            ->where('exam_id', $exam->id)
            ->select('applicant_id');

        return Applicant::query()
            ->whereIn('id', $sat)
            // Enrolled applicants are checked too: `status` alone would let an
            // applicant who already has a student record back into the pool.
            ->whereNotIn('status', [ApplicantStatus::Admitted->value])
            ->whereDoesntHave('student')
            ->orderBy('registration_number')
            ->get();
    }

    /**
     * The last sitting a candidate actually has marks for — resit or original.
     *
     * Unmarked rows are ignored on purpose: a resit that has been created but not
     * yet sat is not a paper the candidate has failed.
     */
    public function latestSitting(Applicant $applicant): ?Exam
    {
        $marked = Score::query()
            ->where('applicant_id', $applicant->id)
            ->whereNotNull('score')
            ->select('exam_id');

        return Exam::query()
            ->whereIn('id', $marked)
            ->orderByDesc('exam_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * An open resit this applicant is already entered for.
     *
     * Without this the public button could be pressed twice and quietly open a
     * second sitting in the candidate's name.
     */
    public function openResitFor(Applicant $applicant): ?Exam
    {
        $entered = Score::query()
            ->where('applicant_id', $applicant->id)
            ->where('is_resit', true)
            ->select('exam_id');

        return Exam::query()
            ->where('is_resit', true)
            ->whereIn('id', $entered)
            ->whereNotIn('status', [ExamStatus::Completed, ExamStatus::Published])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Create the next sitting of a resit, copying only the papers at least one
     * selected candidate needs to repeat.
     *
     * @param  array<int,int>  $applicantIds
     * @param  array<string,mixed>  $overrides
     */
    public function createResit(Exam $exam, array $applicantIds, ?User $actor = null, array $overrides = []): Exam
    {
        $applicants = Applicant::query()->whereIn('id', $applicantIds)->get();

        if ($applicants->isEmpty()) {
            throw new \RuntimeException('Select at least one candidate for the resit.');
        }

        return DB::transaction(function () use ($exam, $applicants, $actor, $overrides) {
            $subjects = $this->subjectsFor($exam, $applicants);

            if ($subjects->isEmpty()) {
                throw new \RuntimeException('This sitting has no papers recorded, so there is nothing to re-sit.');
            }

            // `resit_round` may not be loaded on the in-memory model (it is a DB
            // default), so treat a missing value as round 1. Never emit round 1
            // for a resit — that would collide with the original sitting.
            $round = max(2, (int) ($exam->resit_round ?: 1) + 1);

            /** @var Exam $resit */
            $resit = Exam::create([
                'title' => $overrides['title'] ?? $exam->title,
                'academic_session_id' => $overrides['academic_session_id'] ?? $exam->academic_session_id,
                'level_id' => $overrides['level_id'] ?? $exam->level_id,
                'exam_date' => $overrides['exam_date'] ?? null,
                'starts_at' => $overrides['starts_at'] ?? null,
                'venue' => $overrides['venue'] ?? $exam->venue,
                'cutoff_mark' => $overrides['cutoff_mark'] ?? $exam->cutoff_mark,
                'status' => ExamStatus::Draft,
                'instructions' => $overrides['instructions'] ?? $exam->instructions,
                'created_by' => $actor?->id,
                'resit_of_exam_id' => $exam->id,
                'is_resit' => true,
                'resit_round' => $round,
            ]);

            // Copy the paper set-up verbatim so totals and pass marks match.
            $map = [];

            foreach ($subjects as $index => $subject) {
                $copy = ExamSubject::create([
                    'exam_id' => $resit->id,
                    'subject_id' => $subject->subject_id,
                    'total_marks' => $subject->total_marks,
                    'pass_mark' => $subject->pass_mark,
                    'weight' => $subject->weight,
                    'sort_order' => $index,
                    'is_compulsory' => $subject->is_compulsory,
                ]);

                $map[$subject->id] = $copy->id;
            }

            foreach ($applicants as $applicant) {
                $this->enrol($resit, $applicant, $exam, $map);
            }

            return $resit->load('examSubjects.subject');
        });
    }

    /**
     * Put one candidate into a resit: blank score rows for the papers they must
     * repeat, so the marks desk and the importer pick them up like any other
     * sitting.
     *
     * @param  array<int,int>  $subjectMap  original ExamSubject id => resit ExamSubject id
     * @return int  number of blank rows created
     */
    public function enrol(Exam $resit, Applicant $applicant, Exam $original, array $subjectMap): int
    {
        $needed = $this->failedSubjects($original, $applicant);

        // Failed nothing individually but still below the aggregate cutoff, or
        // was never marked at all → repeat the whole paper.
        if ($needed->isEmpty()) {
            $needed = $original->examSubjects()->with('subject')->get();
        }

        $previousAttempt = (int) Score::query()
            ->where('exam_id', $original->id)
            ->where('applicant_id', $applicant->id)
            ->max('attempt');

        $created = 0;

        foreach ($needed as $subject) {
            $resitSubjectId = $subjectMap[$subject->id] ?? null;

            if (! $resitSubjectId) {
                continue;
            }

            $exists = Score::query()
                ->where('exam_id', $resit->id)
                ->where('exam_subject_id', $resitSubjectId)
                ->where('applicant_id', $applicant->id)
                ->exists();

            if ($exists) {
                continue;
            }

            Score::create([
                'exam_id' => $resit->id,
                'exam_subject_id' => $resitSubjectId,
                'applicant_id' => $applicant->id,
                'score' => null,
                'source' => ScoreSource::Manual,
                'is_resit' => true,
                'attempt' => max(2, $previousAttempt + 1),
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Public self-service: a rejected candidate registers himself for the next
     * resit of the last paper he sat.
     *
     * Reuses an open resit if one already exists, so fifty candidates clicking
     * the link produce one exam — not fifty.
     *
     * @return array{exam:Exam,created:int}|null  null when there is nothing to re-sit
     */
    public function selfRegister(Applicant $applicant, ?User $actor = null): ?array
    {
        if ($existing = $this->openResitFor($applicant)) {
            return ['exam' => $existing, 'created' => 0, 'already' => true];
        }

        $original = $this->latestSitting($applicant);

        if (! $original) {
            return null;
        }

        return DB::transaction(function () use ($original, $applicant, $actor) {
            $resit = Exam::query()
                ->where('resit_of_exam_id', $original->id)
                ->whereIn('status', [ExamStatus::Draft, ExamStatus::Scheduled, ExamStatus::Ongoing])
                ->orderByDesc('resit_round')
                ->first();

            if (! $resit) {
                $resit = $this->createResit($original, [$applicant->id], $actor);

                return ['exam' => $resit, 'created' => $resit->examSubjects->count(), 'already' => false];
            }

            $created = $this->enrol($resit, $applicant, $original, $this->subjectMap($original, $resit));

            return ['exam' => $resit, 'created' => $created, 'already' => false];
        });
    }

    /** Map original paper ids onto their counterparts in the resit. */
    public function subjectMap(Exam $original, Exam $resit): array
    {
        $resitBySubject = ExamSubject::query()
            ->where('exam_id', $resit->id)
            ->pluck('id', 'subject_id');

        return ExamSubject::query()
            ->where('exam_id', $original->id)
            ->pluck('id', 'subject_id')
            ->mapWithKeys(fn ($originalId, $subjectId) => [$originalId => $resitBySubject[$subjectId] ?? null])
            ->filter()
            ->all();
    }

    /**
     * Union of the papers any of the selected candidates must repeat, kept in
     * the original paper order.
     *
     * @param  Collection<int,Applicant>  $applicants
     * @return Collection<int,ExamSubject>
     */
    protected function subjectsFor(Exam $exam, Collection $applicants): Collection
    {
        $all = $exam->examSubjects()->with('subject')->get();
        $needed = collect();

        foreach ($applicants as $applicant) {
            $failed = $this->failedSubjects($exam, $applicant);

            $needed = $needed->merge($failed->isEmpty() ? $all : $failed);
        }

        return $all
            ->filter(fn (ExamSubject $subject) => $needed->contains('id', $subject->id))
            ->values();
    }
}
