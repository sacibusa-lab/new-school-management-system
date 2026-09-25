<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\FeeCategory;
use App\Models\FeeStructure;
use App\Models\SchoolLevel;
use App\Models\Term;
use Illuminate\Database\Seeder;

class FeeSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Tuition Fee', 'code' => 'TUITION', 'type' => 'tuition'],
            ['name' => 'Development Levy', 'code' => 'DEVLEVY', 'type' => 'development'],
            ['name' => 'Registration Fee', 'code' => 'REGFEE', 'type' => 'levy'],
            ['name' => 'Examination Fee', 'code' => 'EXAMFEE', 'type' => 'levy'],
            ['name' => 'Books & Stationery', 'code' => 'BOOKS', 'type' => 'books'],
            ['name' => 'Uniform', 'code' => 'UNIFORM', 'type' => 'uniform'],
            ['name' => 'Transport', 'code' => 'TRANSPORT', 'type' => 'transport'],
            ['name' => 'Boarding / Hostel', 'code' => 'HOSTEL', 'type' => 'hostel'],
            ['name' => 'PTA Levy', 'code' => 'PTA', 'type' => 'levy'],
            ['name' => 'Medical Levy', 'code' => 'MEDICAL', 'type' => 'levy'],
            ['name' => 'ICT Levy', 'code' => 'ICT', 'type' => 'levy'],
            ['name' => 'Sports Levy', 'code' => 'SPORTS', 'type' => 'levy'],
        ];

        foreach ($categories as $category) {
            FeeCategory::updateOrCreate(['code' => $category['code']], $category + ['is_active' => true]);
        }

        // ---------------------------------------------------------------
        // A default billable structure per level, for the current session.
        // Real amounts are meant to be edited in the admin Fee Structure screen.
        // ---------------------------------------------------------------
        $session = AcademicSession::current();
        $term = $session?->currentTerm();

        if (! $session || ! $term) {
            return;
        }

        // JSS3 and SS3 are not offered by this school, so they carry no fees.
        $amounts = [
            'JSS1' => ['TUITION' => 85000, 'DEVLEVY' => 15000, 'REGFEE' => 10000, 'BOOKS' => 25000, 'UNIFORM' => 20000, 'PTA' => 5000, 'ICT' => 5000],
            'JSS2' => ['TUITION' => 85000, 'DEVLEVY' => 10000, 'BOOKS' => 20000, 'PTA' => 5000, 'ICT' => 5000],
            'SS1' => ['TUITION' => 105000, 'DEVLEVY' => 15000, 'REGFEE' => 10000, 'BOOKS' => 30000, 'PTA' => 5000, 'ICT' => 5000],
            'SS2' => ['TUITION' => 105000, 'DEVLEVY' => 10000, 'BOOKS' => 25000, 'PTA' => 5000, 'ICT' => 5000],
        ];

        foreach ($amounts as $levelName => $lines) {
            $level = SchoolLevel::where('name', $levelName)->first();

            if (! $level) {
                continue;
            }

            $structure = FeeStructure::updateOrCreate(
                [
                    'academic_session_id' => $session->id,
                    'term_id' => $term->id,
                    'level_id' => $level->id,
                    'name' => "{$levelName} — {$term->name} {$session->name}",
                ],
                [
                    'is_active' => true,
                    'is_default_for_new_students' => true,
                    'due_days' => 30,
                    'notes' => 'Seeded template — adjust the amounts to match school policy.',
                ],
            );

            foreach ($lines as $code => $amount) {
                $category = FeeCategory::where('code', $code)->first();

                if (! $category) {
                    continue;
                }

                $structure->items()->updateOrCreate(
                    ['fee_category_id' => $category->id],
                    [
                        'description' => $category->name,
                        'amount' => $amount,
                        'is_compulsory' => in_array($code, ['TUITION', 'DEVLEVY', 'PTA'], true),
                    ],
                );
            }
        }
    }
}
