<?php

return [
    /*
    |----------------------------------------------------------------------
    | Numbering
    |----------------------------------------------------------------------
    */
    'admission_prefix' => env('SCHOOL_REGISTRATION_PREFIX', 'SAC'),
    'student_prefix' => env('SCHOOL_STUDENT_PREFIX', 'SAC'),

    /*
    |----------------------------------------------------------------------
    | Branding (public site)
    |----------------------------------------------------------------------
    */
    'school_name' => env('SCHOOL_NAME', 'Saci Schools'),
    'tagline' => env('SCHOOL_TAGLINE', 'Knowledge, Character, Service'),

    /*
    |----------------------------------------------------------------------
    | AI scoresheet extraction
    |----------------------------------------------------------------------
    | The pipeline is provider-agnostic. Leave `provider` as null to run on
    | spreadsheet/manual entry only; set it to `gemini` or `openai` later and
    | add a key — no code changes required.
    */
    'ai' => [
        'provider' => env('AI_PROVIDER', 'null'),
        'key' => env('AI_API_KEY'),
        'model' => env('AI_MODEL', 'gemini-2.5-flash'),
        'timeout' => (int) env('AI_TIMEOUT', 120),
        'max_file_mb' => (int) env('AI_MAX_FILE_MB', 12),
        'min_confidence' => (float) env('AI_MIN_CONFIDENCE', 0.75),
    ],

    /*
    |----------------------------------------------------------------------
    | Uploads
    |----------------------------------------------------------------------
    */
    'uploads' => [
        'scoresheets' => 'scoresheets',
        'photos' => 'photos',
        'documents' => 'documents',
    ],

    /*
    |----------------------------------------------------------------------
    | Grading
    |----------------------------------------------------------------------
    | Fallback scale, seeded into grade_scales and editable in the admin UI.
    */
    'default_grade_scale' => [
        ['min' => 75, 'max' => 100, 'grade' => 'A1', 'remark' => 'Excellent', 'points' => 9],
        ['min' => 70, 'max' => 74.99, 'grade' => 'B2', 'remark' => 'Very Good', 'points' => 8],
        ['min' => 65, 'max' => 69.99, 'grade' => 'B3', 'remark' => 'Good', 'points' => 7],
        ['min' => 60, 'max' => 64.99, 'grade' => 'C4', 'remark' => 'Credit', 'points' => 6],
        ['min' => 55, 'max' => 59.99, 'grade' => 'C5', 'remark' => 'Credit', 'points' => 5],
        ['min' => 50, 'max' => 54.99, 'grade' => 'C6', 'remark' => 'Credit', 'points' => 4],
        ['min' => 45, 'max' => 49.99, 'grade' => 'D7', 'remark' => 'Pass', 'points' => 3],
        ['min' => 40, 'max' => 44.99, 'grade' => 'E8', 'remark' => 'Pass', 'points' => 2],
        ['min' => 0, 'max' => 39.99, 'grade' => 'F9', 'remark' => 'Fail', 'points' => 1],
    ],
];
