<?php

namespace Database\Factories;

use App\Enums\PromotionAction;
use App\Models\AcademicSession;
use App\Models\Student;
use App\Models\StudentPromotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentPromotion>
 */
class StudentPromotionFactory extends Factory
{
    /**
     * A decision about a student, with the student made for it.
     *
     * A student needs a session, so one is made once and shared — every promotion
     * in a test belongs to the same academic year unless a test says otherwise.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => fn (): int => $this->student()->id,
            'from_academic_session_id' => fn (): int => $this->session()->id,
            'to_academic_session_id' => null,
            'from_school_class_id' => null,
            'to_school_class_id' => null,
            'action' => PromotionAction::Promoted,
            'decided_by' => null,
            'decided_at' => now(),
        ];
    }

    private function session(): AcademicSession
    {
        return AcademicSession::firstOrCreate(
            ['name' => '2026/2027'],
            ['is_current' => true],
        );
    }

    private function student(): Student
    {
        return Student::create([
            'student_number' => 'SAC/'.fake()->unique()->numberBetween(100000, 999999),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'academic_session_id' => $this->session()->id,
        ]);
    }
}
