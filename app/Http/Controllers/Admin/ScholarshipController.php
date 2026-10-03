<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Scholarship;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Term;
use App\Services\Fees\ScholarshipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * What students are let off, and who said so.
 *
 * An award here is meant to end up on a bill, so the page shows the state of each
 * one — pending, approved, rejected — rather than only a list: the question the
 * office has is "has this been applied yet", and a list of amounts does not answer it.
 */
class ScholarshipController extends Controller
{
    public function __construct(
        private readonly ScholarshipService $awards,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('fees.manage');

        $session = AcademicSession::current();

        return view('admin.fees.scholarships', [
            'awards' => Scholarship::query()
                ->with(['student.level', 'student.schoolClass', 'academicSession', 'term', 'approver'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('q'), function ($q) use ($request) {
                    $term = '%'.trim($request->string('q')->toString()).'%';

                    $q->whereHas('student', fn ($s) => $s
                        ->where('student_number', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('first_name', 'like', $term));
                })
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'students' => Student::query()
                ->when($session, fn ($q) => $q->where('academic_session_id', $session->id))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(),
            'terms' => Term::query()->orderBy('position')->get(),
            'types' => Scholarship::TYPES,
            'totals' => [
                'approved' => (float) Scholarship::query()->approved()->sum('amount'),
                'pending' => (float) Scholarship::query()->where('status', 'pending')->sum('amount'),
            ],
            'currency' => Setting::get('currency_symbol', '₦'),
            'session' => $session,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('fees.manage');

        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'academic_session_id' => ['nullable', 'exists:academic_sessions,id'],
            'term_id' => ['nullable', 'exists:terms,id'],
            'type' => ['required', 'string', 'max:30'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $award = Scholarship::create($validated + ['status' => 'pending']);

        // Approved on the way out of the form, because that is how the office actually
        // works: the decision has been taken, and the person entering it is the person
        // who took it.
        if ($request->boolean('approve')) {
            $result = $this->awards->approve($award, $request->user());

            return back()->with('status', sprintf(
                'Award approved: %s off %d invoice(s).',
                Setting::get('currency_symbol', '₦').number_format($result['discounted'], 2),
                $result['invoices'],
            ));
        }

        return back()->with('status', 'Award recorded and waiting for approval.');
    }

    public function approve(Request $request, Scholarship $scholarship): RedirectResponse
    {
        $this->authorize('fees.manage');

        if ($scholarship->isApproved()) {
            return back()->with('error', 'That award has already been approved.');
        }

        $result = $this->awards->approve($scholarship, $request->user());

        if ($result['invoices'] === 0) {
            return back()->with('error', 'Approved, but there was no unpaid bill to take it off yet.');
        }

        return back()->with('status', sprintf(
            '%s taken off %d invoice(s) for %s.',
            Setting::get('currency_symbol', '₦').number_format($result['discounted'], 2),
            $result['invoices'],
            $scholarship->student?->full_name,
        ));
    }

    public function reject(Scholarship $scholarship): RedirectResponse
    {
        $this->authorize('fees.manage');

        $this->awards->withdraw($scholarship);

        return back()->with('status', 'Award rejected and any discount it carried taken back off the bill.');
    }
}
