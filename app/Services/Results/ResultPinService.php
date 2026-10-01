<?php

namespace App\Services\Results;

use App\Models\AcademicSession;
use App\Models\ActivityLog;
use App\Models\ResultPin;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Term;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * The PINs a parent buys to check a result.
 *
 * One to a student for one term, so this is deliberately dull: work out who is in the
 * class, make a PIN for everyone who has not got one for this term, and leave the rest
 * alone. Pressing Generate again after a child joins the class is the normal way to
 * use it, and must never sell a family a second card for a result they can already
 * check.
 */
class ResultPinService
{
    /** Digits. Easy to read off a card and to type on a phone. */
    public const PIN_LENGTH = 12;

    /**
     * The students a class's PINs are for, in the order they are printed.
     *
     * One rule, used by both the screen and the generator: a list that differed
     * between the two would show a gap the second press then refused to fill.
     *
     * @return Collection<int,Student>
     */
    public function studentsFor(SchoolClass $class): Collection
    {
        return Student::query()
            ->active()
            ->where('school_class_id', $class->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Make a PIN for everyone in the class who has not got one for this term.
     *
     * @return array{students:int,created:int,already:int}
     */
    public function generateFor(
        SchoolClass $class,
        AcademicSession $session,
        Term $term,
        ?User $actor = null,
    ): array {
        $students = $this->studentsFor($class);

        if ($students->isEmpty()) {
            throw new RuntimeException("There are no students in {$class->name} yet.");
        }

        $already = ResultPin::query()
            ->where('academic_session_id', $session->id)
            ->where('term_id', $term->id)
            ->whereIn('student_id', $students->pluck('id'))
            ->pluck('student_id');

        $created = 0;

        foreach ($students->whereNotIn('id', $already) as $student) {
            ResultPin::create([
                'student_id' => $student->id,
                'academic_session_id' => $session->id,
                'term_id' => $term->id,
                'pin' => $this->unusedPin(),
                'generated_by' => $actor?->id,
            ]);

            $created++;
        }

        if ($created > 0) {
            ActivityLog::record(
                'results.pins-generated',
                null,
                "Generated {$created} result PIN(s) for {$class->name}, {$term->name}",
                ['module' => 'results', 'count' => $created],
            );
        }

        return [
            'students' => $students->count(),
            'created' => $created,
            'already' => $students->count() - $created,
        ];
    }

    /** This term's PINs for a class, keyed by student, so the screen can show the gaps. */
    public function forClass(SchoolClass $class, AcademicSession $session, Term $term): Collection
    {
        return ResultPin::query()
            ->where('academic_session_id', $session->id)
            ->where('term_id', $term->id)
            ->whereIn('student_id', $this->studentsFor($class)->pluck('id'))
            ->get()
            ->keyBy('student_id');
    }

    private function unusedPin(): string
    {
        do {
            $pin = (string) random_int(
                (int) (10 ** (self::PIN_LENGTH - 1)),
                (int) (10 ** self::PIN_LENGTH) - 1,
            );
        } while (ResultPin::query()->where('pin', $pin)->exists());

        return $pin;
    }
}
