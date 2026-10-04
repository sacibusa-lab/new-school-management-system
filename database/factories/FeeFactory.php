<?php

namespace Database\Factories;

use App\Models\AcademicSession;
use App\Models\Fee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fee>
 */
class FeeFactory extends Factory
{
    /**
     * A termly fee against the current session, which is what the office creates most of.
     *
     * The session has to be the current one. A bare `value('id')` was here first and took
     * whichever row the database happened to offer — the lowest id — so a school with a past
     * year on file had its new fee filed under the year that had ended, and every screen that
     * filters fees by session then quietly showed nothing.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => ucfirst($this->faker->unique()->words(2, true)).' Fee',
            'description' => null,
            'cycle' => 'termly',
            'academic_session_id' => AcademicSession::current()?->id,
            'amount' => $this->faker->numberBetween(5, 200) * 1000,
            'first_term_active' => true,
            'second_term_active' => true,
            'third_term_active' => true,
            'is_active' => true,
        ];
    }

    /** A fee that applies to every session rather than one. */
    public function forEverySession(): static
    {
        return $this->state(fn (): array => ['academic_session_id' => null]);
    }

    /** A fee the school is no longer charging. */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /** A fee that comes round once a year rather than each term. */
    public function annual(): static
    {
        return $this->state(fn (): array => ['cycle' => 'annually']);
    }
}
