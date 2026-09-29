<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdmissionDecisionStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\AdmissionDecision;
use App\Models\AdmissionSetting;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\SchoolLevel;
use App\Services\Admissions\AdmissionService;
use App\Services\Admissions\ResitService;
use App\Services\Admissions\StudentEnrolmentService;
use App\Services\Sms\SmsNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The cutoff desk: set the pass mark, compute the merit list, apply the cutoff,
 * then transfer the successful applicants into the results and fees portals.
 *
 * Failed candidates can be given a resit from the same screen.
 */
class AdmissionController extends Controller
{
    public function __construct(
        private readonly AdmissionService $admissions,
        private readonly StudentEnrolmentService $enrolment,
        private readonly ResitService $resits,
        private readonly SmsNotifier $sms,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('admissions.decide');

        $session = AcademicSession::current();

        $exams = Exam::query()
            ->with(['level', 'academicSession'])
            ->when($session, fn ($q) => $q->where('academic_session_id', $session->id))
            ->orderByDesc('exam_date')
            ->orderByDesc('id')
            ->get();

        $exam = $request->filled('exam')
            ? $exams->firstWhere('id', $request->integer('exam'))
            : $exams->first();

        $decisions = $exam
            ? AdmissionDecision::query()
                ->with(['applicant.levelAppliedFor', 'applicant.student', 'decider'])
                ->where('exam_id', $exam->id)
                ->when($request->filled('decision'), fn ($q) => $q->where('decision', $request->string('decision')))
                ->orderBy('position')
                ->paginate(50)
                ->withQueryString()
            : null;

        return view('admin.admissions.index', [
            'exams' => $exams,
            'exam' => $exam,
            'decisions' => $decisions,
            'statistics' => $exam ? $this->admissions->statistics($exam) : null,
            'settings' => $this->settingsFor($session, $exams),
            'levels' => SchoolLevel::query()->active()->get(),
            'session' => $session,
            'decisionOptions' => AdmissionDecisionStatus::options(),
            // Everyone who sat this paper and was not admitted — the resit pool.
            'resitCandidates' => $exam ? $this->resits->candidates($exam) : collect(),
            'resitEnabled' => $this->resits->isEnabled(),
            'existingResits' => $exam ? $exam->resits()->withCount('examSubjects')->get() : collect(),
        ]);
    }

    /** Save the cutoff for one level. */
    public function updateSetting(Request $request, SchoolLevel $level): RedirectResponse
    {
        $this->authorize('admissions.cutoff');

        $validated = $request->validate([
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'cutoff_mark' => ['required', 'numeric', 'min:0', 'max:100'],
            'subject_pass_mark' => ['required', 'numeric', 'min:0', 'max:100'],
            'available_slots' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'require_all_subjects' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        AdmissionSetting::updateOrCreate(
            [
                'academic_session_id' => $validated['academic_session_id'],
                'level_id' => $level->id,
            ],
            [
                'cutoff_mark' => $validated['cutoff_mark'],
                'subject_pass_mark' => $validated['subject_pass_mark'],
                'available_slots' => $validated['available_slots'] ?? null,
                'require_all_subjects' => $request->boolean('require_all_subjects'),
                'is_active' => $request->boolean('is_active', true),
                'set_by' => $request->user()->id,
            ],
        );

        return back()->with('status', "Cutoff for {$level->name} saved.");
    }

    /** Recalculate the merit list from the captured scores. */
    public function compute(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('admissions.decide');

        $count = $this->admissions->compute($exam, $request->user());

        if ($count === 0) {
            return back()->with('error', 'There are no captured scores for this examination yet. Enter or import the scores first.');
        }

        return redirect()
            ->route('admin.admissions.index', ['exam' => $exam->id])
            ->with('status', "Merit list computed for {$count} candidate(s). Review the list, then apply the cutoff.");
    }

    /** Apply the cutoff mark and produce admit / not-admit decisions. */
    public function apply(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('admissions.decide');

        $validated = $request->validate([
            'respect_slots' => ['nullable', 'boolean'],
        ]);

        $result = $this->admissions->applyCutoff($exam, $request->user(), $request->boolean('respect_slots', true));

        if ($result['admitted'] + $result['rejected'] + $result['waiting'] === 0) {
            return back()->with('error', $result['kept'] > 0
                ? 'Nothing to apply — every decision on this examination was made by hand.'
                : 'No computed decisions found. Compute the merit list first.');
        }

        $message = sprintf(
            'Cutoff of %s%% applied — %d admitted, %d not admitted',
            $result['cutoff'],
            $result['admitted'],
            $result['rejected'],
        );

        if ($result['waiting'] > 0) {
            $message .= ", {$result['waiting']} above the line but out of places";
        }

        // Said out loud, so an override that survives the run is not mistaken
        // for one the cutoff just made.
        if ($result['kept'] > 0) {
            $message .= ". {$result['kept']} decision(s) left as set by hand";
        }

        return redirect()
            ->route('admin.admissions.index', ['exam' => $exam->id])
            ->with('status', $message . '. Now transfer the admitted applicants into the result and fees portals.');
    }

    /** Manually flip a single applicant's decision. */
    public function override(Request $request, AdmissionDecision $decision): RedirectResponse
    {
        $this->authorize('admissions.decide');

        $validated = $request->validate([
            'decision' => ['required', 'in:' . implode(',', AdmissionDecisionStatus::values())],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);

        $this->admissions->override(
            $decision,
            AdmissionDecisionStatus::from($validated['decision']),
            $validated['remarks'] ?? null,
            $request->user(),
        );

        return back()->with('status', "Decision updated for {$decision->applicant?->registration_number}.");
    }

    /**
     * The hand-off: every admitted applicant becomes a student with a
     * SAC/YYYY/NNN number, a portal login, and a fee invoice.
     */
    public function enrol(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('admissions.enrol');

        $result = $this->enrolment->enrolExam($exam, $request->user());

        if ($result['enrolled'] === 0) {
            return back()->with('error', 'No new applicants to transfer. Admitted applicants are only transferred once.');
        }

        $message = "{$result['enrolled']} applicant(s) transferred — admission numbers issued, portal logins created and fee invoices raised.";

        if ($result['withoutInvoice'] !== []) {
            $message .= ' No fee structure matched for: ' . implode(', ', $result['withoutInvoice']) . '. Publish a fee structure for those levels and raise the invoices from the Fees screen.';
        }

        return redirect()
            ->route('admin.students.index', ['session' => $exam->academic_session_id])
            ->with('status', $message);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Give the candidates who did not make it another sitting.
     *
     * The resit is a brand new examination pointing back at this one, so the
     * marks desk, the scoresheet importer and the cutoff desk all handle it with
     * no special cases. Only the papers each candidate actually failed are
     * copied, so nobody re-sits a subject they already passed.
     */
    public function resit(Request $request, Exam $exam): RedirectResponse
    {
        $this->authorize('admissions.resit');

        if (! $this->resits->isEnabled()) {
            return back()->with('error', 'Resit applications are switched off in Settings.');
        }

        $validated = $request->validate([
            'applicant_ids' => ['required', 'array', 'min:1'],
            'applicant_ids.*' => ['integer', 'exists:applicants,id'],
            'exam_date' => ['nullable', 'date'],
            'venue' => ['nullable', 'string', 'max:120'],
        ], [
            'applicant_ids.required' => 'Tick at least one candidate to sit the resit.',
            'applicant_ids.min' => 'Tick at least one candidate to sit the resit.',
        ]);

        try {
            $resit = $this->resits->createResit($exam, $validated['applicant_ids'], $request->user(), [
                'exam_date' => $validated['exam_date'] ?? null,
                'venue' => $validated['venue'] ?? null,
            ]);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Tell the families after the sitting exists, never before.
        $applicants = Applicant::query()->whereIn('id', $validated['applicant_ids'])->get();

        foreach ($applicants as $applicant) {
            $this->sms->resitRegistered($applicant, $resit);
        }

        $papers = $resit->examSubjects->count();

        return redirect()
            ->route('admin.exams.show', $resit)
            ->with('status', sprintf(
                'Resit %d created for %d candidate(s) with %d paper(s). Record or import the marks as usual, then compute the merit list again.',
                $resit->resit_round,
                $applicants->count(),
                $papers,
            ));
    }

    /* ------------------------------------------------------------------ */
    protected function settingsFor(?AcademicSession $session, $exams)
    {
        if (! $session) {
            return collect();
        }

        return SchoolLevel::query()
            ->active()
            ->with(['admissionSettings' => fn ($q) => $q->where('academic_session_id', $session->id)])
            ->get();
    }
}
