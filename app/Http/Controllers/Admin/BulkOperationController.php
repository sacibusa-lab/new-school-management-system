<?php

namespace App\Http\Controllers\Admin;

use App\Enums\StudentStatus;
use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\SchoolLevel;
use App\Models\Student;
use App\Services\Payment\VirtualAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Opening account numbers for a whole class at once.
 *
 * One at a time is right for a child who arrives mid-term; a September intake is two
 * hundred of them, and nobody is going to press a button two hundred times. This is
 * the fee-relevant half of the fee site's Bulk Ops — the rest of that screen (bulk
 * graduation, bulk import) already exists here under Students & Results.
 *
 * Two guards, both learned from what goes wrong: students who already have an account
 * are skipped rather than reissued, because a parent who has saved the number must
 * keep the number they saved; and a run is capped, because each account is two calls
 * to Paystack and a request that opens two hundred of them will time out halfway with
 * no way to tell which half.
 */
class BulkOperationController extends Controller
{
    /** How many accounts one press may open. */
    private const MAX_PER_RUN = 50;

    public function __construct(
        private readonly VirtualAccountService $accounts,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('fees.manage');

        $chosen = $request->filled('class') || $request->filled('level');

        $students = $chosen
            ? $this->scope($request)->with(['level', 'schoolClass', 'virtualAccount'])->orderBy('last_name')->get()
            : collect();

        return view('admin.payments.bulk-ops', [
            'levels' => SchoolLevel::query()->active()->orderBy('order')->get(),
            'classes' => SchoolClass::query()->where('is_active', true)->with('level')->orderBy('name')->get(),
            'level' => $request->filled('level') ? $request->integer('level') : null,
            'class' => $request->filled('class') ? $request->integer('class') : null,
            'chosen' => $chosen,
            'students' => $students,
            'without' => $students->reject(fn (Student $student) => $student->virtualAccount)->values(),
            'paystackReady' => $this->accounts->isConfigured(),
            'maxPerRun' => self::MAX_PER_RUN,
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('fees.manage');

        if (! $this->accounts->isConfigured()) {
            return back()->with('error', 'Paystack is not set up yet. Add the keys in Settings under API.');
        }

        $waiting = $this->scope($request)->whereDoesntHave('virtualAccount')->get();

        if ($waiting->isEmpty()) {
            return back()->with('status', 'Every active student in that selection already has an account number.');
        }

        // Checked before a single account is opened, so a run is never half-done.
        if ($waiting->count() > self::MAX_PER_RUN) {
            return back()->with('error', sprintf(
                'That is %d students in one go, and the limit is %d. Choose a class rather than a whole year group.',
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
                // did not, and Paystack's own reason for each.
                $failed[] = $student->student_number.' — '.$e->getMessage();
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

    /**
     * Who a run covers: one class where a class was chosen, otherwise a whole year
     * group. With neither, nobody — the office has to say who they mean.
     *
     * @return Builder<Student>
     */
    protected function scope(Request $request): Builder
    {
        return Student::query()
            ->where('status', StudentStatus::Active->value)
            ->when(
                $request->filled('class'),
                fn (Builder $q) => $q->where('school_class_id', $request->integer('class')),
                fn (Builder $q) => $q->when($request->filled('level'), fn (Builder $inner) => $inner->where('level_id', $request->integer('level'))),
            )
            ->when(
                ! $request->filled('class') && ! $request->filled('level'),
                fn (Builder $q) => $q->whereRaw('1 = 0'),
            );
    }
}
