<?php

namespace App\Services\Admissions;

use App\Enums\AdmissionDecisionStatus;
use App\Enums\ApplicantStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicSession;
use App\Models\ActivityLog;
use App\Models\AdmissionDecision;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\PipelineEvent;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Student;
use App\Models\User;
use App\Services\Fees\InvoiceGenerationService;
use App\Services\NumberSequenceService;
use App\Services\Sms\SmsNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Stage 3 of the pipeline.
 *
 * When an applicant is admitted they are transferred into BOTH of the other two
 * portals at once:
 *
 *   - the result portal  (a student record + portal login, results enabled)
 *   - the fees portal    (an invoice raised from the level's fee structure)
 *
 * and their number changes from `SAC-00001` to `SAC/2026/001`.
 */
class StudentEnrolmentService
{
    public function __construct(
        private readonly NumberSequenceService $sequences,
        private readonly InvoiceGenerationService $invoices,
        private readonly SmsNotifier $sms,
    ) {}

    /**
     * Enrol an admitted applicant. Safe to call repeatedly — a student is only
     * ever created once per applicant.
     */
    public function enrol(Applicant $applicant, ?AdmissionDecision $decision = null, ?User $actor = null): Student
    {
        if ($existing = $applicant->student()->first()) {
            return $existing;
        }

        $student = DB::transaction(function () use ($applicant, $decision, $actor) {
            $applicant->loadMissing(['levelAppliedFor', 'academicSession']);

            $session = $applicant->academicSession ?? AcademicSession::current();
            $level = $applicant->levelAppliedFor;

            $student = Student::create([
                // SAC/2026/001 — restarts each academic year.
                'student_number' => $this->sequences->nextStudentNumber($session?->startYear() ?? now()->year),
                // Keep the original SAC-00001 as the permanent admission reference.
                'admission_number' => $applicant->registration_number,
                'applicant_id' => $applicant->id,
                'first_name' => $applicant->first_name,
                'middle_name' => $applicant->middle_name,
                'last_name' => $applicant->last_name,
                'gender' => $applicant->gender,
                'date_of_birth' => $applicant->date_of_birth,
                'email' => $applicant->email,
                'phone' => $applicant->phone,
                'address' => $applicant->address,
                'photo_path' => $applicant->photo_path,
                'level_id' => $level?->id,
                'school_class_id' => $this->assignClass($level, $session?->id)?->id,
                'academic_session_id' => $session?->id ?? AcademicSession::current()?->id,
                'guardian_name' => $applicant->guardian_name,
                'guardian_phone' => $applicant->guardian_phone,
                'guardian_email' => $applicant->guardian_email,
                'status' => StudentStatus::Active,
                // Both destination portals switched on.
                'results_portal_enabled' => true,
                'fees_portal_enabled' => true,
                'admitted_at' => $decision?->decided_at ?? now(),
                'admission_average' => $decision?->average_score,
            ]);

            $account = $this->provisionPortalAccount($student);

            // Fees portal: raise the first invoice straight away.
            $invoice = $this->invoices->generateForStudent($student, null, null, $actor);

            $applicant->forceFill([
                'status' => ApplicantStatus::Admitted,
                'admitted_at' => $student->admitted_at,
            ])->save();

            PipelineEvent::record('applicant.admitted', $applicant, [
                'student_number' => $student->student_number,
                'admission_number' => $student->admission_number,
                'invoice' => $invoice?->invoice_number,
                'portal_account' => $account?->email,
                'average' => $decision?->average_score,
                'cutoff' => $decision?->cutoff_mark,
            ]);

            ActivityLog::record(
                'student.enrolled',
                $student,
                "Enrolled {$student->first_name} {$student->last_name} as {$student->student_number}",
                [
                    'module' => 'admissions',
                    'invoice' => $invoice?->invoice_number,
                ],
            );

            return $student;
        });

        // After commit: the admission number is final now, and a gateway
        // failure must not roll back a student's enrolment.
        $this->sms->applicantAdmitted($applicant->refresh(), $decision);

        return $student;
    }

    /**
     * Give the admitted student a portal login so they can see results and fees.
     * The password is their admission number, and they are forced to change it.
     */
    protected function provisionPortalAccount(Student $student): ?User
    {
        if (! $student->academic_session_id) {
            return null;
        }

        if ($student->user_id) {
            return User::find($student->user_id);
        }

        $email = $student->email;
        $generated = false;

        if (! $email || User::query()->where('email', $email)->exists()) {
            $email = $this->buildStudentEmail($student);
            $generated = true;
        }

        $user = User::create([
            'name' => $student->first_name.' '.$student->last_name,
            'email' => $email,
            'phone' => $student->phone,
            'password' => Hash::make($student->student_number),
            'is_active' => true,
        ]);

        $user->assignRole('Student');

        $student->forceFill(['user_id' => $user->id])->save();

        // Remember how the address was derived so the enrolment screen can tell
        // the office what the student's first-time credentials are.
        $user->setAttribute('credentials_are_generated', $generated);
        $user->setAttribute('initial_password', $student->student_number);

        return $user;
    }

    protected function buildStudentEmail(Student $student): string
    {
        $slug = Str::slug($student->student_number, '.');

        $candidate = "{$slug}@student.saci.test";
        $suffix = 1;

        while (User::query()->where('email', $candidate)->exists()) {
            $candidate = "{$slug}.{$suffix}@student.saci.test";
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Put the new student in the least-full active class arm for their level.
     * Returns null when the level has no classes defined yet — the office can
     * assign one later from the student's profile.
     */
    public function assignClass(?SchoolLevel $level, ?int $sessionId): ?SchoolClass
    {
        if (! $level) {
            return null;
        }

        $classes = SchoolClass::query()
            ->where('level_id', $level->id)
            ->where('is_active', true)
            ->withCount(['students' => fn ($q) => $q->where('academic_session_id', $sessionId)])
            ->get();

        if ($classes->isEmpty()) {
            return null;
        }

        return $classes
            ->sortBy(fn (SchoolClass $class) => [
                $class->capacity !== null && $class->students_count >= $class->capacity ? 1 : 0,
                $class->students_count,
                $class->name,
            ])
            ->first();
    }

    /**
     * Enrol every admitted decision on an exam.
     *
     * @return array{enrolled:int,skipped:int,students:array<int,Student>,withoutInvoice:array<int,string>}
     */
    public function enrolExam(Exam $exam, ?User $actor = null): array
    {
        $decisions = AdmissionDecision::query()
            ->with(['applicant.levelAppliedFor', 'applicant.academicSession'])
            ->where('exam_id', $exam->id)
            ->where('decision', AdmissionDecisionStatus::Admitted->value)
            ->get();

        $enrolled = [];
        $skipped = 0;
        $withoutInvoice = [];

        foreach ($decisions as $decision) {
            $applicant = $decision->applicant;

            if (! $applicant) {
                $skipped++;

                continue;
            }

            if ($applicant->student()->exists()) {
                $skipped++;

                continue;
            }

            $student = $this->enrol($applicant, $decision, $actor);
            $enrolled[] = $student;

            if (! $student->invoices()->exists()) {
                $withoutInvoice[] = $student->student_number;
            }
        }

        return [
            'enrolled' => count($enrolled),
            'skipped' => $skipped,
            'students' => $enrolled,
            'withoutInvoice' => $withoutInvoice,
        ];
    }
}
