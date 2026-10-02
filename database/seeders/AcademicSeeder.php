<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\GradeScale;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Section;
use App\Models\SessionTerm;
use App\Models\Subject;
use App\Models\Term;
use Illuminate\Database\Seeder;

class AcademicSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------
        // Academic session + terms
        // ---------------------------------------------------------------
        $session = AcademicSession::updateOrCreate(
            ['name' => '2026/2027'],
            [
                'starts_on' => '2026-09-01',
                'ends_on' => '2027-07-31',
                'is_current' => true,
                'is_admission_open' => true,
            ],
        );

        // Only one session may be current.
        AcademicSession::where('id', '!=', $session->id)->update(['is_current' => false]);

        foreach ([
            ['name' => 'First Term', 'position' => 1, 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-18', 'is_current' => true],
            ['name' => 'Second Term', 'position' => 2, 'starts_on' => '2027-01-11', 'ends_on' => '2027-04-02', 'is_current' => false],
            ['name' => 'Third Term', 'position' => 3, 'starts_on' => '2027-04-26', 'ends_on' => '2027-07-31', 'is_current' => false],
        ] as $term) {
            // A term belongs to every session, so it is created once by position —
            // not once per session — and never rewritten once it exists. A school
            // that renamed a term has said something.
            $row = Term::firstOrCreate(
                ['position' => $term['position']],
                ['name' => $term['name'], 'is_current' => $term['is_current']],
            );

            // The dates are the part that is per session, and are only laid down if
            // nobody has set them. Next year's dates will not overwrite this year's.
            SessionTerm::firstOrCreate(
                ['academic_session_id' => $session->id, 'term_id' => $row->id],
                ['starts_on' => $term['starts_on'], 'ends_on' => $term['ends_on']],
            );
        }

        // ---------------------------------------------------------------
        // Sections, levels and the classes they make
        // ---------------------------------------------------------------
        // Sections first, because a class is named from one: JSS1 on its own is not
        // a class anybody sits in until it has a section. The school runs all six
        // years of a secondary school, so all six are seeded.
        //
        // A year the school is not running — one it has not opened yet, or one whose
        // children have left — is switched off on the Classes & Sections page rather
        // than removed from here, and that is why `is_active` is written only when a
        // year or a class is first created. After that it belongs to the office: a
        // seeder run must not quietly open a year they have withdrawn.
        $sections = ['A', 'B', 'C', 'D'];

        foreach ($sections as $index => $section) {
            Section::updateOrCreate(
                ['name' => $section],
                ['order' => $index + 1],
            );
        }

        $levels = [
            ['name' => 'JSS1', 'order' => 1, 'sections' => ['A', 'B', 'C', 'D']],
            ['name' => 'JSS2', 'order' => 2, 'sections' => ['A', 'B', 'C', 'D']],
            ['name' => 'JSS3', 'order' => 3, 'sections' => ['A', 'B', 'C', 'D']],
            ['name' => 'SS1', 'order' => 4, 'sections' => ['A', 'B', 'C', 'D']],
            ['name' => 'SS2', 'order' => 5, 'sections' => ['A', 'B', 'C']],
            ['name' => 'SS3', 'order' => 6, 'sections' => ['A', 'B', 'C']],
        ];

        foreach ($levels as $levelData) {
            $level = SchoolLevel::firstOrNew(['name' => $levelData['name']]);

            if (! $level->exists) {
                $level->is_active = true;
            }

            $level->order = $levelData['order'];
            $level->save();

            foreach ($levelData['sections'] as $sectionName) {
                $section = Section::query()->where('name', $sectionName)->sole();

                $class = SchoolClass::firstOrNew([
                    'level_id' => $level->id,
                    'section_id' => $section->id,
                ]);

                if (! $class->exists) {
                    $class->is_active = true;
                }

                $class->name = $level->name.$section->name;
                $class->capacity = 40;
                $class->save();
            }
        }

        // ---------------------------------------------------------------
        // Subjects
        // ---------------------------------------------------------------
        $subjects = [
            ['name' => 'English Language', 'code' => 'ENG'],
            ['name' => 'Mathematics', 'code' => 'MTH'],
            ['name' => 'General Paper', 'code' => 'GPR'],
            ['name' => 'Basic Science', 'code' => 'BSC'],
            ['name' => 'Basic Technology', 'code' => 'BTC'],
            ['name' => 'Social Studies', 'code' => 'SOS'],
            ['name' => 'Civic Education', 'code' => 'CVE'],
            ['name' => 'Business Studies', 'code' => 'BUS'],
            ['name' => 'Computer Studies', 'code' => 'CMP'],
            ['name' => 'Agricultural Science', 'code' => 'AGR'],
            ['name' => 'Home Economics', 'code' => 'HEC'],
            ['name' => 'Christian Religious Studies', 'code' => 'CRS'],
            ['name' => 'Islamic Religious Studies', 'code' => 'IRS'],
            ['name' => 'Yoruba Language', 'code' => 'YOR'],
            ['name' => 'Igbo Language', 'code' => 'IGB'],
            ['name' => 'Hausa Language', 'code' => 'HAU'],
            ['name' => 'French Language', 'code' => 'FRE'],
            ['name' => 'Fine Arts', 'code' => 'FAR'],
            ['name' => 'Music', 'code' => 'MUS'],
            ['name' => 'Physical & Health Education', 'code' => 'PHE'],
            ['name' => 'Physics', 'code' => 'PHY'],
            ['name' => 'Chemistry', 'code' => 'CHM'],
            ['name' => 'Biology', 'code' => 'BIO'],
            ['name' => 'Further Mathematics', 'code' => 'FMT'],
            ['name' => 'Economics', 'code' => 'ECO'],
            ['name' => 'Government', 'code' => 'GOV'],
            ['name' => 'Literature in English', 'code' => 'LIT'],
            ['name' => 'Geography', 'code' => 'GEO'],
            ['name' => 'Book Keeping', 'code' => 'BKP'],
            ['name' => 'Financial Accounting', 'code' => 'ACC'],
            ['name' => 'Commerce', 'code' => 'COM'],
            ['name' => 'Technical Drawing', 'code' => 'TDR'],
            ['name' => 'Food & Nutrition', 'code' => 'FNT'],
        ];

        foreach ($subjects as $subject) {
            Subject::updateOrCreate(['code' => $subject['code']], $subject + ['is_active' => true]);
        }

        // ---------------------------------------------------------------
        // Grade scale
        // ---------------------------------------------------------------
        foreach (config('saci.default_grade_scale') as $row) {
            GradeScale::updateOrCreate(
                ['name' => 'Default', 'grade' => $row['grade']],
                [
                    'min_score' => $row['min'],
                    'max_score' => $row['max'],
                    'remark' => $row['remark'],
                    'points' => $row['points'],
                ],
            );
        }
    }
}
