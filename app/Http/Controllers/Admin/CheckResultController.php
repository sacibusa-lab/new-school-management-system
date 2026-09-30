<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermResult;
use App\Support\Concerns\FindsRecordsByNumber;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read any student's result for any session and term.
 *
 * The public checker asks a parent for a surname and shows only published terms.
 * This page asks for neither, which is the point of it: when a parent rings the
 * office to say they cannot see their child's result, the office needs to see the
 * result and, when there is nothing to see, why. So an unpublished result is shown
 * here and labelled as unpublished — a page that hid it would be unable to answer
 * the only question it exists to answer.
 *
 * That is also why it stays with the Super Admin. No surname stands between this
 * page and a child's record, so it is not a tool to hand round the staff room.
 *
 * The session and term are asked for rather than assumed, because the office is
 * often ringing about a term that has ended — the point of the universal terms is
 * that First Term 2026/2027 is still reachable after the school has moved on.
 */
class CheckResultController extends Controller
{
    use FindsRecordsByNumber;

    public function __invoke(Request $request): View
    {
        $this->authorize('results.check');

        $validated = $request->validate([
            'academic_session_id' => ['nullable', 'integer', 'exists:academic_sessions,id'],
            'term_id' => ['nullable', 'integer', 'exists:terms,id'],
            'admission_number' => ['nullable', 'string', 'max:40'],
        ]);

        $sessions = AcademicSession::query()->orderByDesc('starts_on')->orderByDesc('id')->get();

        $session = AcademicSession::query()->find($validated['academic_session_id'] ?? null)
            ?? AcademicSession::current();

        $term = Term::query()->find($validated['term_id'] ?? null)
            ?? Term::current()
            ?? Term::query()->orderBy('position')->first();

        $number = trim((string) ($validated['admission_number'] ?? ''));

        $student = null;
        $result = null;

        if ($number !== '') {
            $student = $this->findStudent($number);

            if ($student !== null && $session !== null && $term !== null) {
                $result = TermResult::query()
                    ->with(['items.subject', 'schoolClass', 'term', 'academicSession'])
                    ->where('student_id', $student->id)
                    ->where('academic_session_id', $session->id)
                    ->where('term_id', $term->id)
                    ->first();
            }
        }

        return view('admin.students-results.check-result', [
            'sessions' => $sessions,
            'terms' => Term::query()->orderBy('position')->get(),
            'session' => $session,
            'term' => $term,
            'number' => $number,
            'searched' => $number !== '',
            'student' => $student,
            'result' => $result,
            'published' => $result?->isPublished() ?? false,
        ]);
    }

    /**
     * Find the child behind a typed number.
     *
     * The field asks for an admission number — SAC/2026/001 — but the number a
     * parent reads off the slip in their hand is often the registration number they
     * applied with, SAC-00001, and both are stored. Rather than make the office ask
     * which one the caller meant, both are tried, admission number first.
     *
     * The spellings ("sac 2", "SAC-00002", "SAC/2026/2") are the same ones the
     * public checker accepts, because they come from the same place.
     */
    protected function findStudent(string $number): ?Student
    {
        $byAdmissionNumber = $this->findByNumber(
            Student::query()->with(['level', 'schoolClass', 'academicSession']),
            'student_number',
            $number,
            (int) (Setting::get('student_number_padding') ?: 3),
        )->first();

        return $byAdmissionNumber ?? $this->findByNumber(
            Student::query()->with(['level', 'schoolClass', 'academicSession']),
            'admission_number',
            $number,
            (int) (Setting::get('admission_number_padding') ?: 5),
        )->first();
    }
}
