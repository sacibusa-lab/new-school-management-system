<?php

namespace App\Services\Sms;

use App\Models\AdmissionDecision;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Support\SmsTemplateKey;
use App\Support\SmsTemplateValue;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds the placeholder values for each kind of message, so the callers
 * (registration, admission, payments) stay readable one-liners.
 */
class SmsNotifier
{
    public function __construct(
        private readonly SmsService $sms,
    ) {
    }

    public function applicantRegistered(Applicant $applicant): void
    {
        $this->sms->sendTemplate(
            SmsTemplateKey::APPLICANT_REGISTERED,
            $applicant->guardian_phone ?: $applicant->phone,
            $this->forApplicant($applicant),
            $applicant,
        );
    }

    public function examScheduled(Applicant $applicant, Exam $exam): void
    {
        $this->sms->sendTemplate(
            SmsTemplateKey::EXAM_SCHEDULED,
            $applicant->guardian_phone ?: $applicant->phone,
            $this->forApplicant($applicant) + [
                'exam_date' => $exam->exam_date?->format('D, j M Y') ?? 'to be announced',
                'exam_time' => $exam->starts_at ? substr((string) $exam->starts_at, 0, 5) : 'the time on your slip',
                'venue' => $exam->venue ?? 'the school campus',
            ],
            $applicant,
        );
    }

    public function applicantAdmitted(Applicant $applicant, ?AdmissionDecision $decision = null): void
    {
        $applicant->loadMissing('student');

        $this->sms->sendTemplate(
            SmsTemplateKey::APPLICANT_ADMITTED,
            $applicant->guardian_phone ?: $applicant->phone,
            $this->forApplicant($applicant, $decision) + [
                // Falls back to the registration number if enrolment has not run yet.
                'admission_number' => $applicant->student?->student_number ?? $applicant->registration_number,
            ],
            $applicant,
        );
    }

    public function applicantRejected(Applicant $applicant, ?AdmissionDecision $decision = null): void
    {
        $this->sms->sendTemplate(
            SmsTemplateKey::APPLICANT_REJECTED,
            $applicant->guardian_phone ?: $applicant->phone,
            $this->forApplicant($applicant, $decision),
            $applicant,
        );
    }

    public function resitRegistered(Applicant $applicant, Exam $resit): void
    {
        $this->sms->sendTemplate(
            SmsTemplateKey::RESIT_REGISTERED,
            $applicant->guardian_phone ?: $applicant->phone,
            $this->forApplicant($applicant),
            $applicant,
        );
    }

    public function paymentReceived(Payment $payment): void
    {
        $payment->loadMissing(['student', 'invoice']);

        $student = $payment->student;

        if (! $student) {
            return;
        }

        $currency = Setting::get('currency_symbol', '₦');

        $this->sms->sendTemplate(
            SmsTemplateKey::PAYMENT_RECEIVED,
            $student->guardian_phone ?: $student->phone,
            $this->forStudent($student) + [
                'amount' => $currency . number_format((float) $payment->amount, 2),
                'receipt_number' => $payment->receipt_number,
            ],
            $payment,
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Placeholder values for whichever record a broadcast targets. Used by the
     * bulk SMS screen so a message previewed there sends byte-for-byte the same
     * text as one sent from a single action.
     *
     * @return array<string,string|int|float|null>
     */
    public function valuesFor(Model $subject): array
    {
        return match (true) {
            $subject instanceof Applicant => $this->forApplicant($subject),
            $subject instanceof Student => $this->forStudent($subject),
            default => SmsTemplateValue::clean([
                'school_name' => Setting::get('school_name', config('saci.school_name')),
            ]),
        };
    }

    /**
     * @return array<string,string|int|float|null>
     */
    protected function forStudent(Student $student): array
    {
        $student->loadMissing(['level', 'schoolClass', 'academicSession']);

        return SmsTemplateValue::clean([
            'school_name' => Setting::get('school_name', config('saci.school_name')),
            'guardian_name' => $student->guardian_name ?: 'Parent/Guardian',
            'full_name' => $student->full_name,
            'first_name' => $student->first_name,
            'surname' => $student->last_name,
            // Mirrors the SMS wording of the rest of the app: the public-facing
            // admission number is `student_number`.
            'admission_number' => $student->student_number,
            'registration_number' => $student->admission_number,
            'class' => $student->schoolClass?->name ?? $student->level?->name,
            'session' => $student->academicSession?->name,
            'balance' => Setting::get('currency_symbol', '₦') . number_format($student->outstandingBalance(), 2),
        ] + $this->sharedValues());
    }

    /**
     * Values that make sense for any recipient, so templates that mention the
     * result checker can still be broadcast to a whole class.
     *
     * Memoised: a broadcast calls the value builder once per recipient, and the
     * current term does not change mid-request.
     *
     * @var array<string,string>|null
     */
    private ?array $shared = null;

    /**
     * @return array<string,string>
     */
    protected function sharedValues(): array
    {
        return $this->shared ??= SmsTemplateValue::clean([
            'term' => Term::current()?->name,
            'results_url' => route('public.results'),
        ]);
    }

    /**
     * @return array<string,string|int|float|null>
     */
    protected function forApplicant(Applicant $applicant, ?AdmissionDecision $decision = null): array
    {
        $applicant->loadMissing(['levelAppliedFor', 'academicSession', 'student', 'decisions']);

        $decision ??= $applicant->decisions->sortByDesc('id')->first();

        return SmsTemplateValue::clean([
            'school_name' => Setting::get('school_name', config('saci.school_name')),
            'guardian_name' => $applicant->guardian_name ?: 'Parent/Guardian',
            'full_name' => $applicant->full_name,
            'first_name' => $applicant->first_name,
            'surname' => $applicant->last_name,
            'registration_number' => $applicant->registration_number,
            'class' => $applicant->levelAppliedFor?->name ?? 'the class applied for',
            'session' => $applicant->academicSession?->name,
            'average' => $decision ? $this->formatScore($decision->average_score) : null,
            'cutoff' => $decision ? $this->formatScore($decision->cutoff_mark) : null,
        ] + $this->sharedValues());
    }

    protected function formatScore(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2), '0'), '.') . '%';
    }
}
