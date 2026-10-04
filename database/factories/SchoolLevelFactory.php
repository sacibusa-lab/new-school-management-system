<?php

namespace Database\Factories;

use App\Models\SchoolLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolLevel>
 */
class SchoolLevelFactory extends Factory
{
    /**
     * A year group. Named after the level rather than at random, because the name is what
     * the office reads and "JSS1" says more in a failing test than "Quia".
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $order = $this->faker->unique()->numberBetween(1, 20);

        return [
            'name' => 'Year '.$order,
            'order' => $order,
            'is_active' => true,
        ];
    }
}
