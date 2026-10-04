<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Fee;
use App\Models\FeeBeneficiary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeBeneficiary>
 */
class FeeBeneficiaryFactory extends Factory
{
    /**
     * A share of a fee, paying into one of the school's own accounts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fee_id' => Fee::factory(),
            'bank_account_id' => BankAccount::factory(),
            'amount' => $this->faker->numberBetween(1, 50) * 1000,
        ];
    }
}
