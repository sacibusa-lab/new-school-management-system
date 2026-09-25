<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Build the baseline the whole platform depends on:
     * permissions -> settings -> academic structure -> fee templates -> admin.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            SettingsSeeder::class,
            SmsTemplateSeeder::class,
            AcademicSeeder::class,
            FeeSeeder::class,
            StaffUserSeeder::class,
        ]);
    }
}
