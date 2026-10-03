<?php

namespace App\Services\Payment;

use App\Models\Student;
use App\Models\StudentVirtualAccount;
use RuntimeException;

/**
 * The bank account number a child's fees are paid into.
 *
 * Paystack calls it a dedicated virtual account: created once against the student,
 * and every transfer that lands in it is theirs — no reference to quote, nothing for
 * the office to reconcile by hand.
 *
 * The issuing flow is the school's own fees site's, kept as it was because it works.
 * Two things differ. The key comes from Settings → API rather than the environment,
 * and the payer's email is the guardian's, because a student here deliberately has
 * none of their own.
 */
class VirtualAccountService
{
    public function __construct(
        private readonly PaystackProvider $paystack,
    ) {}

    public function isConfigured(): bool
    {
        return $this->paystack->isConfigured();
    }

    /** The account this child already has, if they have one. */
    public function forStudent(Student $student): ?StudentVirtualAccount
    {
        return StudentVirtualAccount::query()
            ->where('student_id', $student->id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Open the child's account with Paystack, or hand back the one they already have.
     *
     * Idempotent for the same reason billing is: the office will press the button
     * twice, and a child must not end up with two account numbers — a parent who
     * saved the first one would have their money land somewhere the school is not
     * watching.
     *
     * @throws RuntimeException when Paystack will not issue one, with its own words.
     */
    public function issueFor(Student $student): StudentVirtualAccount
    {
        if ($existing = $this->forStudent($student)) {
            return $existing;
        }

        if (! $this->isConfigured()) {
            throw new RuntimeException('Paystack is not set up yet. Add the keys in Settings under API.');
        }

        $customer = $this->paystack->createCustomer([
            'email' => $this->emailFor($student),
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'phone' => $student->guardian_phone,
        ]);

        if (empty($customer['status']) || empty($customer['customer_code'])) {
            throw new RuntimeException($customer['message'] ?? 'Paystack would not create the payer.');
        }

        $account = $this->paystack->createDedicatedAccount($customer['customer_code']);

        if (empty($account['status']) || empty($account['account_number'])) {
            throw new RuntimeException($account['message'] ?? 'Paystack would not issue an account number.');
        }

        return StudentVirtualAccount::create([
            'student_id' => $student->id,
            'customer_code' => $customer['customer_code'],
            'bank_name' => $account['bank_name'] ?? 'Paystack',
            'account_number' => $account['account_number'],
            'account_name' => $account['account_name'] ?? $student->full_name,
            'account_slug' => $account['account_slug'] ?? null,
            'provider' => 'paystack',
            'is_active' => true,
        ]);
    }

    /**
     * Paystack wants an email for every payer, and a student here has none — the
     * parent's is the one the school keeps. Where even that is missing, the address
     * is synthesised on the same domain the portal logins use, so that every child
     * can be given an account number and no parent's inbox has to matter.
     */
    protected function emailFor(Student $student): string
    {
        if (filled($student->guardian_email)) {
            return (string) $student->guardian_email;
        }

        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '.', (string) $student->student_number));
        $slug = trim($slug, '.');

        return ($slug !== '' ? $slug : 'student'.$student->id).'@student.saci.test';
    }
}
