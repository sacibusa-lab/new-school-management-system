<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Setting;
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
        // A term has no session of its own — terms are shared by every session, and
        // the pair of them is what a result belongs to. Asking for `term.academicSession`
        // here was a relationship that does not exist, which took the whole slip down
        // with it: the printable report card answered 500 to everybody who asked for
        // one. The session the slip prints is the result's own.
        $termResult->load([
            'student.level',
            'student.schoolClass',
            'term',
            'academicSession',
            'items.subject',
            'schoolClass',
        ]);

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
            'currency' => Setting::get('currency_symbol', '₦'),
            // The same signature the admission letter carries: it is the Principal's
            // either way, and it is the office that uploads it once.
            'signature' => Setting::get('signature_image'),
        ]);
    }
}
