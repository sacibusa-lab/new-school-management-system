<?php

namespace App\Services\Results;

use App\Enums\ResultStatus;
use App\Models\AcademicSession;
use App\Models\Assessment;
use App\Models\AssessmentScore;
use App\Models\GradeScale;
use App\Models\ResultPublication;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermResult;
use App\Models\TermResultItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rolls continuous assessment and exam scores up into a term report card,
 * including subject positions and the student's position in the class.
 */
class ResultComputationService
{
    /**
     * @return array{students:int,subjects:int}
     */
    public function computeForClass(int $classId, int $sessionId, int $termId, ?User $actor = null): array
    {
        $assessments = Assessment::query()
            ->where('school_class_id', $classId)
            ->where('academic_session_id', $sessionId)
            ->where('term_id', $termId)
            ->get();

        if ($assessments->isEmpty()) {
            return ['students' => 0, 'subjects' => 0];
        }

        $students = Student::query()
            ->where('school_class_id', $classId)
            ->where('status', \App\Enums\StudentStatus::Active->value)
            ->get();

        if ($students->isEmpty()) {
            return ['students' => 0, 'subjects' => 0];
        }

        $scoresByStudent = AssessmentScore::query()
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->get()
            ->groupBy(['student_id', 'assessment_id']);

        return DB::transaction(function () use ($students, $assessments, $scoresByStudent, $classId, $sessionId, $termId) {
            $subjectAverages = [];

            $computed = $students->map(function (Student $student) use ($assessments, $scoresByStudent, $classId, $sessionId, $termId, &$subjectAverages) {
                $subjects = $assessments->groupBy('subject_id');

                $termResult = TermResult::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'academic_session_id' => $sessionId,
                        'term_id' => $termId,
                    ],
                    [
                        'school_class_id' => $classId,
                        'status' => ResultStatus::Computed,
                    ],
                );

                $termResult->items()->delete();

                $total = 0.0;
                $subjectCount = 0;

                foreach ($subjects as $subjectId => $subjectAssessments) {
                    $caScore = 0.0;
                    $examScore = 0.0;
                    $subjectTotal = 0.0;
                    $isAbsent = false;
                    $missing = false;

                    foreach ($subjectAssessments as $assessment) {
                        $score = $scoresByStudent->get($student->id)?->get($assessment->id)?->first();

                        if (! $score) {
                            $missing = true;

                            continue;
                        }

                        if ($score->is_absent) {
                            $isAbsent = true;

                            continue;
                        }

                        $value = (float) ($score->score ?? 0);

                        if ($assessment->type === 'exam') {
                            $examScore += $value;
                        } else {
                            $caScore += $value;
                        }

                        $subjectTotal += $value;
                    }

                    $percentage = $this->percentageFor($subjectAssessments, $subjectTotal);

                    $termResult->items()->create([
                        'subject_id' => $subjectId,
                        'ca_score' => round($caScore, 2),
                        'exam_score' => round($examScore, 2),
                        'total_score' => round($subjectTotal, 2),
                        'grade' => $isAbsent ? null : GradeScale::gradeLetterFor($percentage),
                        'remark' => $isAbsent ? 'Absent' : GradeScale::gradeFor($percentage)?->remark,
                        'is_absent' => $isAbsent,
                    ]);

                    if (! $isAbsent) {
                        $total += $percentage;
                        $subjectCount++;
                    }

                    $subjectAverages[$subjectId][] = $percentage;

                    unset($missing);
                }

                $average = $subjectCount > 0 ? round($total / $subjectCount, 2) : 0.0;

                $termResult->update([
                    'total_score' => round($total, 2),
                    'average' => $average,
                    'subjects_count' => $subjectCount,
                    'class_size' => $students->count(),
                    'grade' => GradeScale::gradeLetterFor($average),
                ]);

                return $termResult;
            });

            // Subject statistics + per-subject positions.
            foreach ($subjectAverages as $subjectId => $values) {
                $values = collect($values)->filter(fn ($v) => $v !== null);

                if ($values->isEmpty()) {
                    continue;
                }

                TermResultItem::query()
                    ->whereIn('term_result_id', $computed->pluck('id'))
                    ->where('subject_id', $subjectId)
                    ->update([
                        'subject_average' => round($values->avg(), 2),
                        'highest_in_class' => round($values->max(), 2),
                        'lowest_in_class' => round($values->min(), 2),
                    ]);

                $this->rankSubject($computed, $subjectId);
            }

            $this->rankStudents($computed);

            return [
                'students' => $computed->count(),
                'subjects' => $assessments->pluck('subject_id')->unique()->count(),
            ];
        });
    }

    /**
     * @param  Collection<int,Assessment>  $assessments
     */
    protected function percentageFor(Collection $assessments, float $obtained): float
    {
        $max = (float) $assessments->sum('max_score');

        return $max > 0 ? round(($obtained / $max) * 100, 2) : 0.0;
    }

    /**
     * @param  Collection<int,TermResult>  $termResults
     */
    protected function rankSubject(Collection $termResults, int $subjectId): void
    {
        $items = TermResultItem::query()
            ->whereIn('term_result_id', $termResults->pluck('id'))
            ->where('subject_id', $subjectId)
            ->orderByDesc('total_score')
            ->get();

        $position = 0;
        $previous = null;
        $rank = 0;

        foreach ($items as $item) {
            $rank++;

            if ($previous === null || (float) $item->total_score < (float) $previous) {
                $position = $rank;
                $previous = $item->total_score;
            }

            $item->update(['subject_position' => $position]);
        }
    }

    /**
     * @param  Collection<int,TermResult>  $termResults
     */
    protected function rankStudents(Collection $termResults): void
    {
        $ordered = $termResults->sortByDesc('average')->values();

        $position = 0;
        $previous = null;
        $rank = 0;

        foreach ($ordered as $termResult) {
            $rank++;

            if ($previous === null || (float) $termResult->average < (float) $previous) {
                $position = $rank;
                $previous = $termResult->average;
            }

            $termResult->update(['position' => $position]);
        }
    }

    /**
     * Make a class/term's results visible on the public checker.
     */
    public function publish(int $classId, int $sessionId, int $termId, User $actor): int
    {
        $published = TermResult::query()
            ->where('school_class_id', $classId)
            ->where('academic_session_id', $sessionId)
            ->where('term_id', $termId)
            ->update([
                'status' => ResultStatus::Published->value,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);

        ResultPublication::updateOrCreate(
            [
                'academic_session_id' => $sessionId,
                'term_id' => $termId,
                'school_class_id' => $classId,
            ],
            [
                'is_published' => true,
                'published_at' => now(),
                'published_by' => $actor->id,
            ],
        );

        return $published;
    }

    public function unpublish(int $classId, int $sessionId, int $termId): int
    {
        ResultPublication::query()
            ->where('academic_session_id', $sessionId)
            ->where('term_id', $termId)
            ->where('school_class_id', $classId)
            ->update(['is_published' => false, 'published_at' => null]);

        return TermResult::query()
            ->where('school_class_id', $classId)
            ->where('academic_session_id', $sessionId)
            ->where('term_id', $termId)
            ->update(['status' => ResultStatus::Approved->value, 'published_at' => null]);
    }
}
