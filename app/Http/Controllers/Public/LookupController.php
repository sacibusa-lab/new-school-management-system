<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\ResultPublication;
use App\Models\Setting;
use App\Models\TermResult;
use App\Services\Admissions\AdmissionService;
use App\Services\Admissions\ResitService;
use App\Support\Concerns\FindsRecordsByNumber;
use App\Support\Surname;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public lookup screens. Access is by number + a second identifier (surname, date
 * of birth or phone) so a registration number alone is never enough to read
 * somebody else's record.
 *
 * The number cannot be the only thing asked for, and on its own it cannot be the
 * only thing checked: SAC-00001, SAC-00002, SAC/2026/001 … are sequential and can
 * be counted through. Where a record is found by number, the surname is verified
 * against it — and a mismatch is reported as "no match", the same as a number that
 * does not exist, so the page never confirms that a number is real.
 */
class LookupController extends Controller
{
    use FindsRecordsByNumber;

    public function __construct(
        private readonly ResitService $resits,
        private readonly AdmissionService $admissions,
    ) {
    }
    /* ------------------------------------------------------------------ */
    /* Admission status                                                    */
    /* ------------------------------------------------------------------ */

    public function status(Request $request): View
    {
        $applicant = null;
        $searched = false;

        $validated = $request->validate([
            'registration_number' => ['nullable', 'string', 'max:40'],
            'surname' => ['nullable', 'string', 'max:80', 'required_with:registration_number'],
        ], [
            'surname.required_with' => 'Please type the surname as well as the registration number.',
        ]);

        if ($request->filled('registration_number')) {
            $searched = true;

            $applicant = $this->findByNumber(
                Applicant::query()->with(['levelAppliedFor', 'academicSession', 'decisions.exam', 'student']),
                'registration_number',
                $validated['registration_number'],
                (int) (Setting::get('admission_number_padding') ?: 5),
            )->first();

            // The number says which record; the surname is what makes it theirs.
            if ($applicant && ! Surname::matches($applicant->last_name, $validated['surname'] ?? null)) {
                $applicant = null;
            }
        }

        $decision = $applicant?->decisions->sortByDesc('id')->first();

        // The papers behind the merit row, so the page can show a parent the
        // marks the total was added up from rather than only the total.
        $papers = $applicant && $decision?->exam
            ? $this->admissions->breakdownFor($applicant, $decision->exam)
            : collect();

        return view('public.status', [
            'applicant' => $applicant,
            'searched' => $searched,
            'decision' => $decision,
            'papers' => $papers,
            'resitEnabled' => $this->resits->isEnabled(),
            'openResit' => $applicant && ! $applicant->isAdmitted()
                ? $this->resits->openResitFor($applicant)
                : null,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Result checking                                                     */
    /* ------------------------------------------------------------------ */

    public function results(Request $request): View
    {
        $results = null;
        $searched = false;
        $student = null;
        $notPublished = false;

        $validated = $request->validate([
            'student_number' => ['nullable', 'string', 'max:40'],
            'surname' => ['nullable', 'string', 'max:80', 'required_with:student_number'],
        ], [
            'surname.required_with' => 'Please type the surname as well as the admission number.',
        ]);

        if ($request->filled('student_number')) {
            $searched = true;

            $student = $this->findByNumber(
                \App\Models\Student::query()->with(['schoolClass.level', 'level']),
                'student_number',
                $validated['student_number'],
                (int) (Setting::get('student_number_padding') ?: 3),
            )->first();

            // The form has always asked for a surname; until now nothing checked it.
            if ($student && ! Surname::matches($student->last_name, $validated['surname'] ?? null)) {
                $student = null;
            }

            if ($student) {
                $results = TermResult::query()
                    ->with(['term.academicSession', 'items.subject', 'schoolClass'])
                    ->where('student_id', $student->id)
                    ->orderByDesc('academic_session_id')
                    ->orderByDesc('term_id')
                    ->get();

                // Only published terms are ever shown.
                $results = $results->filter(
                    fn (TermResult $result) => ResultPublication::isPublishedFor(
                        $result->academic_session_id,
                        $result->term_id,
                        $result->school_class_id,
                    ),
                )->values();

                $notPublished = $results->isEmpty();
            }
        }

        return view('public.results', [
            'student' => $student,
            'results' => $results,
            'searched' => $searched,
            'notPublished' => $notPublished,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Fee status                                                          */
    /* ------------------------------------------------------------------ */

    public function fees(Request $request): View
    {
        $student = null;
        $invoices = collect();
        $payments = collect();
        $searched = false;

        $validated = $request->validate([
            'student_number' => ['nullable', 'string', 'max:40'],
            'surname' => ['nullable', 'string', 'max:80', 'required_with:student_number'],
        ], [
            'surname.required_with' => 'Please type the surname as well as the admission number.',
        ]);

        if ($request->filled('student_number')) {
            $searched = true;

            $student = $this->findByNumber(
                \App\Models\Student::query()->with(['schoolClass', 'level']),
                'student_number',
                $validated['student_number'],
                (int) (Setting::get('student_number_padding') ?: 3),
            )->first();

            if ($student && ! Surname::matches($student->last_name, $validated['surname'] ?? null)) {
                $student = null;
            }

            if ($student) {
                $invoices = $student->invoices()
                    ->with(['items.category', 'term'])
                    ->orderByDesc('issued_at')
                    ->get();

                $payments = $student->payments()
                    ->with('invoice')
                    ->where('status', \App\Enums\PaymentStatus::Successful->value)
                    ->orderByDesc('paid_at')
                    ->limit(20)
                    ->get();
            }
        }

        return view('public.fees', [
            'student' => $student,
            'invoices' => $invoices,
            'payments' => $payments,
            'searched' => $searched,
            'currency' => Setting::get('currency_symbol', '₦'),
        ]);
    }
}
