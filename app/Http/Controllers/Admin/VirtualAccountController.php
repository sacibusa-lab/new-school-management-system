<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\Payment\VirtualAccountService;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Giving a child the account number their fees are paid into.
 *
 * One action, because that is all it is: press it and the child has an account at a
 * bank. Paystack does the issuing; this only has to be honest about what it says
 * back when Paystack will not.
 */
class VirtualAccountController extends Controller
{
    public function __construct(
        private readonly VirtualAccountService $accounts,
    ) {}

    public function store(Student $student): RedirectResponse
    {
        $this->authorize('fees.manage');

        try {
            $account = $this->accounts->issueFor($student);
        } catch (RuntimeException $e) {
            // Paystack's own words. "Not set up yet" is the office's to fix in
            // Settings → API; anything else is the provider's and is worth reading
            // rather than having the page flash a shrug.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $account->label().' is now open for '.$student->full_name.'.');
    }
}
