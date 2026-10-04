<?php

namespace App\Services\Fees;

use App\Enums\InvoiceStatus;
use App\Models\AcademicSession;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\NumberSequenceService;
use Illuminate\Support\Facades\DB;

/**
 * Creates a student's bill from the fee structure that applies to their
 * session, term and level.
 */
class InvoiceGenerationService
{
    public function __construct(
        private readonly NumberSequenceService $sequences,
    ) {}

    /**
     * Raise the term invoice for a student.
     *
     * Idempotent: calling it twice for the same student/session/term returns the
     * existing invoice instead of double-billing them.
     */
    public function generateForStudent(
        Student $student,
        ?FeeStructure $structure = null,
        ?int $termId = null,
        ?User $user = null,
    ): ?Invoice {
        $session = $student->academicSession ?? AcademicSession::current();

        if (! $session) {
            return null;
        }

        $termId ??= $session->currentTerm()?->id;

        // The bill they already have comes first; the structure is only needed to raise a new
        // one. Asked the other way round this handed back nothing for a child who had a real,
        // unpaid bill but no published structure — which is the state a school is in the term
        // after it stops reissuing structures, and exactly when the office is settling up.
        $existing = Invoice::query()
            ->where('student_id', $student->id)
            ->where('academic_session_id', $session->id)
            ->where('term_id', $termId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $structure ??= FeeStructure::defaultFor($session->id, $student->level_id, $termId);

        if (! $structure) {
            // Nothing to bill against yet — the student is still enrolled, they
            // simply have no invoice until the finance office publishes a structure.
            return null;
        }

        return DB::transaction(function () use ($student, $structure, $session, $termId, $user) {
            $invoice = Invoice::create([
                'invoice_number' => $this->sequences->nextInvoiceNumber($session->startYear()),
                'student_id' => $student->id,
                'academic_session_id' => $session->id,
                'term_id' => $termId,
                'fee_structure_id' => $structure->id,
                'discount' => 0,
                'status' => InvoiceStatus::Unpaid,
                'due_date' => now()->addDays((int) ($structure->due_days ?: Setting::get('invoice_due_days', 30))),
                'issued_at' => now(),
                'is_auto_generated' => true,
                'notes' => 'Raised automatically when the student was admitted.',
                'created_by' => $user?->id,
            ]);

            foreach ($structure->items as $item) {
                $invoice->items()->create([
                    'fee_category_id' => $item->fee_category_id,
                    'description' => $item->label(),
                    'amount' => $item->amount,
                    'is_compulsory' => $item->is_compulsory,
                ]);
            }

            return $invoice->recalculate();
        });
    }

    /**
     * Raise invoices for a whole set of students at once (e.g. a new term).
     *
     * @param  iterable<Student>  $students
     * @return array{created:int,skipped:int}
     */
    public function generateBulk(iterable $students, ?int $termId = null, ?User $user = null): array
    {
        $created = 0;
        $skipped = 0;

        foreach ($students as $student) {
            $invoice = $this->generateForStudent($student, null, $termId, $user);

            if (! $invoice) {
                $skipped++;

                continue;
            }

            $invoice->wasRecentlyCreated ? $created++ : $skipped++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
