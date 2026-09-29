<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\TermResult;
use App\Support\Surname;
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

        // The surname really is checked. It used to be said and not done, which
        // left the slip reachable with a student number alone — and those run
        // SAC/2026/001, 002, 003 …, so they can be counted through.
        abort_unless(
            $termResult->isPublished()
                && $provided !== ''
                && $provided === $expected
                && Surname::matches($student->last_name, $request->query('surname')),
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
