<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\Payment\VirtualAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Giving a child the account number their fees are paid into.
 *
 * One action at a time, or a few at once from the students hub. Paystack does the
 * issuing; this only has to be honest about what it says back when Paystack will not.
 */
class VirtualAccountController extends Controller
{
    /** How many accounts one press may open. */
    private const MAX_PER_RUN = 50;

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

    /**
     * The same thing for the names ticked in the students hub.
     *
     * A September intake is two hundred children and nobody is going to press a
     * button two hundred times, so the hub can do a class at once. The two guards are
     * the ones the single button would have had anyway, because they are about the
     * gateway rather than about how many names were ticked: a child who already has an
     * account keeps the number their parent saved, and a run is capped, because each
     * account is two calls to Paystack and a request that opens two hundred of them
     * will time out halfway with no record of which half.
     */
    public function storeMany(Request $request): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'students' => ['required', 'array', 'min:1'],
            'students.*' => ['integer'],
        ], [
            'students.required' => 'Tick at least one student first.',
            'students.min' => 'Tick at least one student first.',
        ]);

        if (! $this->accounts->isConfigured()) {
            return back()->with('error', 'Paystack is not set up yet. Add the keys in Settings under API.');
        }

        // Re-read rather than trusted, so a tick that was tampered with can only ever
        // name a student who exists.
        $ticked = Student::query()
            ->whereIn('id', $validated['students'])
            ->with('virtualAccount')
            ->get();

        // Skipped rather than reissued: a parent who has saved the number must keep
        // the number they saved.
        $waiting = $ticked->reject(fn (Student $student) => $student->virtualAccount !== null);

        if ($waiting->isEmpty()) {
            return back()->with('status', 'Everyone you ticked already has an account number.');
        }

        // Checked before a single account is opened, so a run is never half-done.
        if ($waiting->count() > self::MAX_PER_RUN) {
            return back()->with('error', sprintf(
                'That is %d students in one go, and the limit is %d. Tick fewer at a time.',
                $waiting->count(),
                self::MAX_PER_RUN,
            ));
        }

        $opened = 0;
        $failed = [];

        foreach ($waiting as $student) {
            try {
                $this->accounts->issueFor($student);
                $opened++;
            } catch (RuntimeException $e) {
                // Kept per student: "40 of 42 opened" is only useful with the two that
                // did not, and the gateway's own reason for each.
                $failed[] = $student->full_name.' — '.$e->getMessage();
            }
        }

        if ($failed !== []) {
            return back()->with('error', sprintf(
                '%d opened, %d did not: %s',
                $opened,
                count($failed),
                implode(' · ', array_slice($failed, 0, 3)),
            ));
        }

        return back()->with('status', "{$opened} account number(s) opened.");
    }
}
