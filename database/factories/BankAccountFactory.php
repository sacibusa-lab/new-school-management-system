<?php

namespace Database\Factories;

use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * One of the school's own accounts. Not primary by default: there is exactly one of
     * those and it is set deliberately.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank_name' => 'Wema Bank',
            'bank_code' => '035',
            'account_number' => (string) $this->faker->unique()->numerify('##########'),
            'account_name' => 'SACI SCHOOLS LTD',
            'is_primary' => false,
            'is_active' => true,
        ];
    }

    public function primary(): static
    {
        return $this->state(fn (): array => ['is_primary' => true]);
    }
}
