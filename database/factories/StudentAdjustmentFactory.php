<?php

namespace Database\Factories;

use App\Models\AcademicSession;
use App\Models\StudentAdjustment;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentAdjustment>
 */
class StudentAdjustmentFactory extends Factory
{
    /**
     * Money taken off, which is the ordinary case at a school counter.
     *
     * The session and the term are the current ones rather than "the first row", because a
     * fixture with a past year in it would otherwise file this year's discount under the
     * year that has ended — and every screen that filters by session would show nothing.
     *
     * **`student_id` has to be passed in.** A child is not something a factory can invent:
     * `Student` has no factory of its own, and one would be a fixture pretending to be a
     * person with a level and a class and an admission number. Pass `student_id`, which the
     * tests have anyway because they enrol the child first — the way the register does.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => null,
            'academic_session_id' => AcademicSession::current()?->id,
            'term_id' => Term::current()?->id,
            'amount' => -1 * $this->faker->numberBetween(2, 20) * 500,
            'description' => $this->faker->randomElement(['Sibling discount', 'Staff child', 'Paid up front']),
            'created_by' => null,
        ];
    }

    /** Money added rather than taken off. */
    public function charge(): static
    {
        return $this->state(fn (): array => [
            'amount' => $this->faker->numberBetween(2, 20) * 500,
            'description' => 'Additional charge',
        ]);
    }
}
