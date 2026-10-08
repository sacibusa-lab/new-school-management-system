<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Fee;
use App\Models\FeeSplit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeSplit>
 */
class FeeSplitFactory extends Factory
{
    /**
     * One account's share of a fee.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fee_id' => Fee::factory(),
            'bank_account_id' => BankAccount::factory(),
            'amount' => $this->faker->numberBetween(1, 50) * 1000,
            'position' => 0,
        ];
    }
}
