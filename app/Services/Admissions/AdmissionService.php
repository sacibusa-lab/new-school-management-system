<?php

namespace App\Services\Admissions;

use App\Enums\AdmissionDecisionStatus;
use App\Enums\ApplicantStatus;
use App\Models\ActivityLog;
use App\Models\AdmissionDecision;
use App\Models\AdmissionSetting;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\PipelineEvent;
use App\Models\Score;
use App\Models\User;
use App\Services\Sms\SmsNotifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stage 2.5 — turning raw scores into a merit list, then applying the
 * admin/exam-officer's cutoff mark to decide who gets in.
 *
 * Marks are compared as percentages of each subject's total, so an exam made of
 * 50-mark and 100-mark papers produces a fair average.
 */
class AdmissionService
{
    public function __construct(
        private readonly SmsNotifier $sms,
    ) {
    }
    /* ------------------------------------------------------------------ */
    /* Compute the merit list                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Recalculate every applicant's merit position for an exam.
     *
     * @return int number of applicants computed
     */
    public function compute(Exam $exam, ?User $actor = null): int
    {
        $exam->loadMissing(['examSubjects.subject', 'academicSession']);

        $examSubjects = $exam->examSubjects->keyBy('id');

        if ($examSubjects->isEmpty()) {
            return 0;
        }

        $cutoff = $this->cutoffFor($exam);

        $scores = Score::query()
            ->with('applicant')
            ->where('exam_id', $exam->id)
            ->get()
            ->groupBy('applicant_id');

        if ($scores->isEmpty()) {
            return 0;
        }

        $rows = $scores->map(fn (Collection $applicantScores, $applicantId) => $this->summarise(
            $applicantScores,
            $examSubjects,
            (int) $applicantId,
        ))->values();

        // Rank: highest average first, then fewest subjects failed, then name.
        $ranked = $rows
            ->sortBy([
                fn ($a, $b) => $b['average'] <=> $a['average'],
                fn ($a, $b) => $a['subjects_failed'] <=> $b['subjects_failed'],
                fn ($a, $b) => strcmp($a['name'], $b['name']),
            ])
            ->values();

        return DB::transaction(function () use ($ranked, $exam, $cutoff, $actor) {
            foreach ($ranked as $index => $row) {
                AdmissionDecision::updateOrCreate(
                    [
                        'applicant_id' => $row['applicant_id'],
                        'exam_id' => $exam->id,
                    ],
                    [
                        'total_score' => $row['total'],
                        'average_score' => $row['average'],
                        'highest_score' => $row['highest'],
                        'subjects_offered' => $row['subjects_offered'],
                        'subjects_passed' => $row['subjects_passed'],
                        'subjects_failed' => $row['subjects_failed'],
                        'has_absent' => $row['has_absent'],
                        'cutoff_mark' => $cutoff,
                        'position' => $index + 1,
                        'position_in_level' => $index + 1,
                        // The verdict itself is set by applyCutoff().
                        'decision' => AdmissionDecisionStatus::Pending,
                    ],
                );
            }

            // Everyone with a computed merit row has now sat the paper.
            Applicant::query()
                ->whereIn('id', $ranked->pluck('applicant_id'))
                ->whereIn('status', [
                    ApplicantStatus::Registered->value,
                    ApplicantStatus::ExamScheduled->value,
                ])
                ->update(['status' => ApplicantStatus::ExamCompleted->value]);

            $count = $ranked->count();

            PipelineEvent::record('admission.merit_computed', $exam, [
                'candidates' => $count,
                'cutoff' => $cutoff,
                'by' => $actor?->name,
            ]);

            return $count;
        });
    }

    /**
     * @param  Collection<int,Score>  $scores
     * @param  Collection<int,ExamSubject>  $examSubjects
     * @return array<string,mixed>
     */
    protected function summarise(Collection $scores, Collection $examSubjects, int $applicantId): array
    {
        $total = 0.0;
        $percentages = [];
        $offered = 0;
        $passed = 0;
        $failed = 0;
        $hasAbsent = false;
        $name = '';
        $registration = '';

        foreach ($scores as $score) {
            $examSubject = $examSubjects->get($score->exam_subject_id);

            if (! $examSubject) {
                continue;
            }

            $applicant = $score->applicant;
            $name = $applicant?->full_name ?? $name;
            $registration = $applicant?->registration_number ?? $registration;

            if ($score->is_absent) {
                $hasAbsent = true;
                $offered++;
                $failed++;

                continue;
            }

            if ($score->score === null) {
                continue;
            }

            $percentage = (float) $examSubject->toPercentage($score->score);
            $percentages[] = $percentage;
            $total += $percentage;
            $offered++;

            if ($percentage >= $examSubject->effectivePassMark()) {
                $passed++;
            } else {
                $failed++;
            }
        }

        return [
            'applicant_id' => $applicantId,
            'name' => $name,
            'registration_number' => $registration,
            'total' => round($total, 2),
            'average' => $percentages === [] ? 0.0 : round(array_sum($percentages) / count($percentages), 2),
            'highest' => $percentages === [] ? 0.0 : round(max($percentages), 2),
            'subjects_offered' => $offered,
            'subjects_passed' => $passed,
            'subjects_failed' => $failed,
            'has_absent' => $hasAbsent,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Apply the cutoff                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Mark everyone above the cutoff as admitted, subject to available slots.
     *
     * @return array{admitted:int,rejected:int,cutoff:float,slots:?int,waiting:int}
     */
    public function applyCutoff(Exam $exam, ?User $actor = null, bool $respectSlots = true): array
    {
        $exam->loadMissing('level');

        $setting = $this->settingFor($exam);
        $cutoff = $setting?->cutoff_mark !== null
            ? (float) $setting->cutoff_mark
            : (float) ($exam->cutoff_mark ?? 50);

        $slots = $respectSlots ? $setting?->available_slots : null;
        $requireAll = (bool) ($setting?->require_all_subjects ?? false);

        $decisions = AdmissionDecision::query()
            ->with('applicant')
            ->where('exam_id', $exam->id)
            ->orderByDesc('average_score')
            ->get();

        if ($decisions->isEmpty()) {
            return ['admitted' => 0, 'rejected' => 0, 'cutoff' => $cutoff, 'slots' => $slots, 'waiting' => 0];
        }

        $admitted = 0;
        $rejected = 0;
        $waiting = 0;

        // Collected inside the transaction, sent once it has committed.
        $notify = [];

        DB::transaction(function () use ($decisions, $cutoff, $slots, $requireAll, $exam, $actor, &$admitted, &$rejected, &$waiting, &$notify) {
            foreach ($decisions as $decision) {
                $eligible = (float) $decision->average_score >= $cutoff
                    && $decision->subjects_offered > 0;

                if ($eligible && $requireAll && $decision->subjects_failed > 0) {
                    $eligible = false;
                    $decision->remarks = 'Did not pass every subject (policy requires all subjects passed).';
                }

                if ($eligible && $slots !== null && $admitted >= $slots) {
                    // Above the line but out of places.
                    $eligible = false;
                    $decision->remarks = "Passed the cutoff of {$cutoff}% but all {$slots} places are filled.";
                    $waiting++;

                    $decision->forceFill([
                        'decision' => AdmissionDecisionStatus::Deferred,
                        'cutoff_mark' => $cutoff,
                        'is_auto' => true,
                        'decided_by' => $actor?->id,
                        'decided_at' => now(),
                    ])->save();

                    $decision->applicant?->forceFill(['status' => ApplicantStatus::Shortlisted])->save();

                    continue;
                }

                $status = $eligible ? AdmissionDecisionStatus::Admitted : AdmissionDecisionStatus::Rejected;

                $decision->forceFill([
                    'decision' => $status,
                    'cutoff_mark' => $cutoff,
                    'is_auto' => true,
                    'remarks' => $decision->remarks ?: ($eligible
                        ? sprintf('Above the cutoff of %s%% by %s points.', $cutoff, $decision->margin())
                        : sprintf('Below the cutoff of %s%% (%s points short).', $cutoff, abs($decision->margin()))),
                    'decided_by' => $actor?->id,
                    'decided_at' => now(),
                ])->save();

                $decision->applicant?->forceFill([
                    'status' => $eligible ? ApplicantStatus::Admitted : ApplicantStatus::Rejected,
                ])->save();

                if ($decision->applicant) {
                    $notify[] = ['applicant' => $decision->applicant, 'decision' => $decision];
                }

                $eligible ? $admitted++ : $rejected++;
            }

            PipelineEvent::record('admission.cutoff_applied', $exam, [
                'cutoff' => $cutoff,
                'slots' => $slots,
                'admitted' => $admitted,
                'rejected' => $rejected,
                'deferred' => $waiting,
                'by' => $actor?->name,
            ]);

            ActivityLog::record(
                'admission.cutoff_applied',
                $exam,
                "Applied a cutoff of {$cutoff}% — {$admitted} admitted, {$rejected} not admitted",
                ['module' => 'admissions'],
            );
        });

        // After commit — a dead gateway must never roll back an admission run.
        foreach ($notify as $item) {
            /** @var \App\Models\Applicant $applicant */
            $applicant = $item['applicant'];
            /** @var \App\Models\AdmissionDecision $decision */
            $decision = $item['decision'];

            if ($decision->decision === AdmissionDecisionStatus::Admitted) {
                $this->sms->applicantAdmitted($applicant, $decision);
            } else {
                $this->sms->applicantRejected($applicant, $decision);
            }
        }

        return [
            'admitted' => $admitted,
            'rejected' => $rejected,
            'cutoff' => $cutoff,
            'slots' => $slots,
            'waiting' => $waiting,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Manual override                                                     */
    /* ------------------------------------------------------------------ */

    public function override(
        AdmissionDecision $decision,
        AdmissionDecisionStatus $status,
        ?string $remarks,
        User $actor,
    ): AdmissionDecision {
        return DB::transaction(function () use ($decision, $status, $remarks, $actor) {
            $decision->forceFill([
                'decision' => $status,
                'is_auto' => false,
                'remarks' => $remarks ?: $decision->remarks,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();

            if ($status === AdmissionDecisionStatus::Admitted) {
                $decision->applicant?->forceFill(['status' => ApplicantStatus::Admitted])->save();
            } elseif ($status === AdmissionDecisionStatus::Rejected) {
                $decision->applicant?->forceFill(['status' => ApplicantStatus::Rejected])->save();
            } elseif ($status === AdmissionDecisionStatus::Deferred) {
                $decision->applicant?->forceFill(['status' => ApplicantStatus::Shortlisted])->save();
            }

            ActivityLog::record(
                'admission.decided',
                $decision,
                "Decision for {$decision->applicant?->registration_number} set to {$status->label()}",
                ['module' => 'admissions'],
            );

            return $decision;
        });
    }

    /* ------------------------------------------------------------------ */

    protected function settingFor(Exam $exam): ?AdmissionSetting
    {
        if (! $exam->level_id) {
            return null;
        }

        return AdmissionSetting::for($exam->academic_session_id, $exam->level_id);
    }

    public function cutoffFor(Exam $exam): float
    {
        $setting = $this->settingFor($exam);

        return (float) ($setting?->cutoff_mark ?? $exam->cutoff_mark ?? 50);
    }

    /**
     * Live statistics for the decisions screen.
     *
     * @return array<string,mixed>
     */
    public function statistics(Exam $exam): array
    {
        $decisions = AdmissionDecision::query()->where('exam_id', $exam->id);

        return [
            'total' => (clone $decisions)->count(),
            'admitted' => (clone $decisions)->where('decision', AdmissionDecisionStatus::Admitted->value)->count(),
            'rejected' => (clone $decisions)->where('decision', AdmissionDecisionStatus::Rejected->value)->count(),
            'deferred' => (clone $decisions)->where('decision', AdmissionDecisionStatus::Deferred->value)->count(),
            'pending' => (clone $decisions)->where('decision', AdmissionDecisionStatus::Pending->value)->count(),
            'highest' => (float) (clone $decisions)->max('average_score'),
            'lowest' => (float) (clone $decisions)->min('average_score'),
            'average' => round((float) (clone $decisions)->avg('average_score'), 2),
            'cutoff' => $this->cutoffFor($exam),
        ];
    }
}
