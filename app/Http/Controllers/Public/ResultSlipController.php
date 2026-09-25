<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\TermResult;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable report card. Requires the term results to be published, so a
 * guessed URL cannot expose an unpublished result.
 */
class ResultSlipController extends Controller
{
    public function __invoke(Request $request, TermResult $termResult): View
    {
        $termResult->load(['student.level', 'student.schoolClass', 'term.academicSession', 'items.subject', 'schoolClass']);

        $student = $termResult->student;

        abort_unless($student, 404);

        // Public access needs the student number plus the surname.
        $provided = strtoupper(str_replace(' ', '', (string) $request->query('student_number')));
        $expected = strtoupper(str_replace(' ', '', (string) $student->student_number));

        abort_unless(
            $termResult->isPublished() && $provided !== '' && $provided === $expected,
            404,
            'This result is not available for printing.',
        );

        return view('public.result-slip', [
            'result' => $termResult,
            'student' => $student,
            'currency' => \App\Models\Setting::get('currency_symbol', '₦'),
        ]);
    }
}
