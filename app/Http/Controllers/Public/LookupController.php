<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\ResultPublication;
use App\Models\Setting;
use App\Models\TermResult;
use App\Services\Admissions\ResitService;
use App\Support\Concerns\FindsRecordsByNumber;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public lookup screens. Access is by number + a second identifier (surname,
 * date of birth or phone) so a registration number alone is never enough to
 * read somebody else's record.
 */
class LookupController extends Controller
{
    use FindsRecordsByNumber;

    public function __construct(
        private readonly ResitService $resits,
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
        ]);

        if ($request->filled('registration_number')) {
            $searched = true;

            $applicant = $this->findByNumber(
                Applicant::query()->with(['levelAppliedFor', 'academicSession', 'decisions.exam', 'student']),
                'registration_number',
                $validated['registration_number'],
                (int) (Setting::get('admission_number_padding') ?: 5),
            )->first();
        }

        return view('public.status', [
            'applicant' => $applicant,
            'searched' => $searched,
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
            'surname' => ['nullable', 'string', 'max:80'],
        ]);

        if ($request->filled('student_number')) {
            $searched = true;

            $student = $this->findByNumber(
                \App\Models\Student::query()
                    ->with(['schoolClass.level', 'level'])
                    ->when($request->filled('surname'), fn ($q) => $q->where('last_name', 'like', trim($validated['surname']))),
                'student_number',
                $validated['student_number'],
                (int) (Setting::get('student_number_padding') ?: 3),
            )->first();

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
            'surname' => ['nullable', 'string', 'max:80'],
        ]);

        if ($request->filled('student_number')) {
            $searched = true;

            $student = $this->findByNumber(
                \App\Models\Student::query()
                    ->with(['schoolClass', 'level'])
                    ->when($request->filled('surname'), fn ($q) => $q->where('last_name', 'like', trim($validated['surname']))),
                'student_number',
                $validated['student_number'],
                (int) (Setting::get('student_number_padding') ?: 3),
            )->first();

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
