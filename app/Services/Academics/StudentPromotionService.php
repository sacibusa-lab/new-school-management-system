<?php

namespace App\Services\Academics;

use App\Enums\PromotionAction;
use App\Models\AcademicSession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moving a class on at the end of a session.
 *
 * One class is saved in one transaction: either the whole class moves or none of
 * it does. A student who carries on is moved in place — their class, their level
 * and their session — while their marks, results and bills stay exactly where they
 * were earned, because those rows name the session they belong to rather than
 * following the student around.
 *
 * Saving the same class again corrects the year rather than taking a second
 * decision about it: the row for that student and that session is edited, so a
 * mistake is fixed instead of duplicated. That is why `from_school_class_id` is
 * read from the existing row first — by then the student has already moved, and
 * their current class is no longer the one they were promoted out of.
 */
class StudentPromotionService
{
    /**
     * @param  array<int|string, array{action: string, class_id?: int|string|null}>  $decisions  keyed by student id
     * @return int how many students were moved on or closed off
     */
    public function apply(
        SchoolClass $schoolClass,
        AcademicSession $fromSession,
        AcademicSession $toSession,
        array $decisions,
        ?User $actor = null,
    ): int {
        $students = Student::query()
            ->whereIn('id', array_map('intval', array_keys($decisions)))
            ->get()
            ->keyBy('id');

        return DB::transaction(function () use ($students, $decisions, $schoolClass, $fromSession, $toSession, $actor): int {
            $applied = 0;

            foreach ($decisions as $studentId => $decision) {
                $student = $students->get((int) $studentId);

                if ($student === null) {
                    continue;
                }

                $action = PromotionAction::from($decision['action']);

                $existing = StudentPromotion::query()
                    ->where('student_id', $student->id)
                    ->where('from_academic_session_id', $fromSession->id)
                    ->first();

                $fromClassId = $existing?->from_school_class_id
                    ?? $student->school_class_id
                    ?? $schoolClass->id;

                $toClassId = match ($action) {
                    PromotionAction::Promoted => (int) ($decision['class_id'] ?? 0) ?: null,
                    // Repeating is sitting the same class again, not staying put.
                    PromotionAction::Repeated => $fromClassId,
                    default => null,
                };

                if ($action === PromotionAction::Promoted && $toClassId === null) {
                    throw ValidationException::withMessages([
                        'decisions' => "Choose the class to promote {$student->full_name} into.",
                    ]);
                }

                StudentPromotion::query()->updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'from_academic_session_id' => $fromSession->id,
                    ],
                    [
                        'to_academic_session_id' => $action->continues() ? $toSession->id : null,
                        'from_school_class_id' => $fromClassId,
                        'to_school_class_id' => $action->continues() ? $toClassId : null,
                        'action' => $action,
                        'decided_by' => $actor?->id,
                        'decided_at' => now(),
                    ],
                );

                if (! $action->continues()) {
                    // They keep the class and session they were in, so a student who
                    // has left does not turn up in next year's register.
                    $student->update(['status' => $action->endingStatus()]);

                    $applied++;

                    continue;
                }

                $target = SchoolClass::query()->find($toClassId);

                if ($target === null) {
                    throw ValidationException::withMessages([
                        'decisions' => 'That class no longer exists.',
                    ]);
                }

                $student->update([
                    'school_class_id' => $target->id,
                    'level_id' => $target->level_id,
                    'academic_session_id' => $toSession->id,
                ]);

                $applied++;
            }

            return $applied;
        });
    }
}
