<?php

namespace Database\Factories;

use App\Models\Fee;
use App\Models\FeeClassOverride;
use App\Models\SchoolLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeClassOverride>
 */
class FeeClassOverrideFactory extends Factory
{
    /**
     * A year group charged something other than the fee's default.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fee_id' => Fee::factory(),
            'level_id' => SchoolLevel::factory(),
            'amount' => $this->faker->numberBetween(5, 200) * 1000,
            'status' => 'active',
        ];
    }

    /** A price set up in advance, not being charged yet. */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => 'inactive']);
    }
}
