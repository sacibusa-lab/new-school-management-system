<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StaffUserSeeder extends Seeder
{
    /**
     * Creates the single Super Admin account. Every other account (teachers,
     * students, parents) is created through the admin UI, never seeded.
     */
    public function run(): void
    {
        $email = 'admin@saci.test';

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Super Administrator',
                'password' => Hash::make('password'),
                'phone' => '08000000000',
                'is_active' => true,
                'must_change_password' => true,
                'email_verified_at' => now(),
            ],
        );

        $admin->syncRoles(['Super Admin']);
    }
}
