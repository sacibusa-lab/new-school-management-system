<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicantStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResultStatus;
use App\Enums\ScoreImportStatus;
use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AdmissionDecision;
use App\Models\Applicant;
use App\Models\Assessment;
use App\Models\Exam;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SchoolClass;
use App\Models\ScoreImport;
use App\Models\Student;
use App\Models\TermResult;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $session = AcademicSession::current();
        $sessionId = $session?->id;
        $term = $session?->currentTerm();

        $activeExam = $this->activeExam($sessionId);

        return view('admin.dashboard', [
            'session' => $session,
            'term' => $term,

            // The three sections, in order.
            'admissions' => $this->admissionsSummary($sessionId),
            'students' => $this->studentsSummary($sessionId, $term?->id),
            'fees' => $this->feesSummary($sessionId),

            'activeExam' => $activeExam,
            'scoreProgress' => $this->scoreProgress($activeExam),

            'recentApplicants' => Applicant::query()
                ->when($sessionId, fn ($q) => $q->where('academic_session_id', $sessionId))
                ->latest()
                ->limit(6)
                ->get(),

            'pendingImports' => ScoreImport::query()
                ->with(['exam', 'examSubject.subject', 'uploader'])
                ->whereIn('status', [ScoreImportStatus::NeedsReview->value, ScoreImportStatus::Pending->value])
                ->latest()
                ->limit(5)
                ->get(),

            'recentDecisions' => AdmissionDecision::query()
                ->with(['applicant.levelAppliedFor', 'exam'])
                ->whereNotNull('decided_at')
                ->latest('decided_at')
                ->limit(5)
                ->get(),

            'topDebtors' => Invoice::query()
                ->with(['student.level', 'student.schoolClass'])
                ->when($sessionId, fn ($q) => $q->where('academic_session_id', $sessionId))
                ->whereIn('status', [
                    InvoiceStatus::Unpaid->value,
                    InvoiceStatus::Partial->value,
                    InvoiceStatus::Overdue->value,
                ])
                ->where('balance', '>', 0)
                ->orderByDesc('balance')
                ->limit(5)
                ->get(),

            'recentPayments' => Payment::query()
                ->with('student')
                ->where('status', PaymentStatus::Successful->value)
                ->latest('paid_at')
                ->limit(5)
                ->get(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* 1. Admissions                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,int>
     */
    protected function admissionsSummary(?int $sessionId): array
    {
        $applicants = Applicant::query()->when($sessionId, fn ($q) => $q->where('academic_session_id', $sessionId));

        return [
            'total' => (clone $applicants)->count(),
            'today' => (clone $applicants)->whereDate('created_at', Carbon::today())->count(),
            'awaiting_decision' => (clone $applicants)->whereIn('status', [
                ApplicantStatus::ExamCompleted->value,
                ApplicantStatus::Shortlisted->value,
            ])->count(),
            'admitted' => (clone $applicants)->where('status', ApplicantStatus::Admitted->value)->count(),
            'not_admitted' => (clone $applicants)->where('status', ApplicantStatus::Rejected->value)->count(),
            'open_imports' => ScoreImport::query()->whereIn('status', [
                ScoreImportStatus::NeedsReview->value,
                ScoreImportStatus::Pending->value,
            ])->count(),
            'exams' => Exam::query()
                ->when($sessionId, fn ($q) => $q->where('academic_session_id', $sessionId))
                ->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* 2. Students & results                                               */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,int|float>
     */
    protected function studentsSummary(?int $sessionId, ?int $termId): array
    {
        $students = Student::query()->when($sessionId, fn ($q) => $q->where('academic_session_id', $sessionId));

        $total = (clone $students)->count();

        // A student counts as "computed" once they have a report card this term.
        $computed = $termId
            ? TermResult::query()->where('term_id', $termId)->distinct()->count('student_id')
            : 0;

        $published = $termId
            ? TermResult::query()
                ->where('term_id', $termId)
                ->where('status', ResultStatus::Published->value)
                ->count()
            : 0;

        return [
            'total' => $total,
            'active' => (clone $students)->where('status', StudentStatus::Active->value)->count(),
            'without_class' => (clone $students)->whereNull('school_class_id')->count(),
            'results_computed' => $computed,
            'results_awaiting' => max($total - $computed, 0),
            'results_published' => $published,
            'assessments' => Assessment::query()
                ->when($sessionId, fn ($q) => $q->where('academic_session_id', $sessionId))
                ->count(),
            'classes' => SchoolClass::query()->where('is_active', true)->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* 3. Fees collections                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string,int|float>
     */
    protected function feesSummary(?int $sessionId): array
    {
        $invoices = Invoice::query()
            ->when($sessionId, fn ($q) => $q->where('academic_session_id', $sessionId))
            ->where('status', '!=', InvoiceStatus::Cancelled->value);

        $billed = (float) (clone $invoices)->sum('total');
        $collected = (float) (clone $invoices)->sum('amount_paid');

        return [
            'billed' => $billed,
            'collected' => $collected,
            'outstanding' => round($billed - $collected, 2),
            'rate' => $billed > 0 ? round(($collected / $billed) * 100, 1) : 0.0,
            'invoice_count' => (clone $invoices)->count(),
            'unpaid_count' => (clone $invoices)->whereIn('status', [
                InvoiceStatus::Unpaid->value,
                InvoiceStatus::Partial->value,
                InvoiceStatus::Overdue->value,
            ])->count(),
            'fully_paid_count' => (clone $invoices)->where('status', InvoiceStatus::Paid->value)->count(),
            'collected_this_month' => (float) Payment::query()
                ->where('status', PaymentStatus::Successful->value)
                ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('amount'),
        ];
    }

    /* ------------------------------------------------------------------ */

    protected function activeExam(?int $sessionId): ?Exam
    {
        return Exam::query()
            ->with(['level', 'academicSession'])
            ->when($sessionId, fn ($q) => $q->where('academic_session_id', $sessionId))
            ->orderByDesc('exam_date')
            ->first();
    }

    /**
     * How many scores have been captured for the most recent exam, so the exam
     * officer can see at a glance whether marking is finished.
     *
     * @return array{expected:int,captured:int,verified:int,percent:int}|null
     */
    protected function scoreProgress(?Exam $exam): ?array
    {
        if (! $exam) {
            return null;
        }

        $subjectCount = $exam->examSubjects()->count();
        $candidateCount = $exam->scores()->distinct('applicant_id')->count('applicant_id');

        $expected = $subjectCount * $candidateCount;

        if ($expected === 0) {
            return null;
        }

        $captured = $exam->scores()->whereNotNull('score')->count();

        return [
            'expected' => $expected,
            'captured' => $captured,
            'verified' => $exam->scores()->whereNotNull('verified_at')->count(),
            'percent' => (int) round(($captured / $expected) * 100),
        ];
    }
}
