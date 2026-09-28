<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminApplicantRequest;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\SchoolLevel;
use App\Models\Score;
use App\Services\AdmissionLetterService;
use App\Services\Admissions\ApplicantImportService;
use App\Services\ApplicantRegistrationService;
use App\Services\NumberSequenceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ApplicantController extends Controller
{
    /** Where the parsed-but-not-yet-committed import lives between steps. */
    private const IMPORT_SESSION_KEY = 'applicant_import';

    public function __construct(
        private readonly ApplicantRegistrationService $registration,
        private readonly ApplicantImportService $imports,
    ) {
    }

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Applicant::class);

        $applicants = $this->filtered($request)
            ->with(['levelAppliedFor', 'academicSession'])
            ->withCount('scores')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.applicants.index', [
            'applicants' => $applicants,
            'levels' => SchoolLevel::query()->active()->get(),
            'sessions' => AcademicSession::query()->orderByDesc('starts_on')->get(),
            'statuses' => ApplicantStatus::options(),
            'counts' => Applicant::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status'),
        ]);
    }

    /**
     * The filters the applicant list and its export both apply.
     *
     * Shared deliberately: if the office filters the list and then exports it,
     * the file must contain exactly what they were looking at.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Applicant>
     */
    protected function filtered(Request $request)
    {
        return Applicant::query()
            ->search($request->string('q')->toString())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('level'), fn ($q) => $q->where('level_applied_for_id', $request->integer('level')))
            ->when($request->filled('session'), fn ($q) => $q->where('academic_session_id', $request->integer('session')));
    }

    /**
     * The filtered list as a CSV for Excel.
     */
    public function export(Request $request): Response
    {
        $this->authorize('admissions.export');

        $applicants = $this->filtered($request)
            ->with(['levelAppliedFor', 'academicSession'])
            ->orderBy('registration_number')
            ->get();

        $handle = fopen('php://temp', 'r+');

        // A UTF-8 byte-order mark, or Excel mangles accented names.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'Registration number', 'Surname', 'First name', 'Middle name', 'Gender',
            'Date of birth', 'Class applied for', 'Session', 'Phone', 'Email',
            'Address', 'State', 'LGA', 'Previous school',
            'Parent / guardian', 'Relationship', 'Guardian phone', 'Guardian email',
            'Status', 'Registered on',
        ]);

        foreach ($applicants as $applicant) {
            fputcsv($handle, [
                $applicant->registration_number,
                $applicant->last_name,
                $applicant->first_name,
                $applicant->middle_name,
                $applicant->gender?->label(),
                $applicant->date_of_birth?->toDateString(),
                $applicant->levelAppliedFor?->name,
                $applicant->academicSession?->name,
                $applicant->phone,
                $applicant->email,
                $applicant->address,
                $applicant->state,
                $applicant->lga,
                $applicant->previous_school,
                $applicant->guardian_name,
                $applicant->guardian_relationship,
                $applicant->guardian_phone,
                $applicant->guardian_email,
                $applicant->status->label(),
                $applicant->created_at?->toDateString(),
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        $filename = 'applicants-' . now()->format('Y-m-d-His') . '.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function show(Applicant $applicant): View
    {
        $this->authorize('view', $applicant);

        $applicant->load([
            'levelAppliedFor',
            'academicSession',
            'student.schoolClass',
            'decisions.exam',
            'scores.examSubject.subject',
            'scores.enteredBy',
            'scores.verifiedBy',
        ]);

        return view('admin.applicants.show', [
            'applicant' => $applicant,
            'decision' => $applicant->decisions->sortByDesc('id')->first(),
        ]);
    }

    /**
     * The slip the office prints and hands to the parent.
     *
     * Carries the registration number they must quote from now on, plus the
     * entrance examination date and venue once the candidate has been entered
     * for one — so the parent leaves knowing when to bring the child back.
     */
    public function slip(Applicant $applicant, AdmissionLetterService $letters): View
    {
        $this->authorize('view', $applicant);

        // A score row exists as soon as a candidate is registered for a sitting
        // (it starts blank), so this means "entered", not "already marked".
        $exam = Exam::query()
            ->whereIn('id', Score::query()
                ->where('applicant_id', $applicant->id)
                ->select('exam_id'))
            ->orderByDesc('exam_date')
            ->orderByDesc('id')
            ->first();

        return view('admin.applicants.slip', [
            'slip' => $letters->slipData($applicant),
            'applicant' => $applicant,
            'exam' => $exam,
        ]);
    }

    public function edit(Applicant $applicant): View
    {
        $this->authorize('update', $applicant);

        return view('admin.applicants.edit', [
            'applicant' => $applicant,
            'levels' => SchoolLevel::query()->active()->get(),
            'statuses' => ApplicantStatus::options(),
        ]);
    }

    public function update(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('update', $applicant);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'gender' => ['nullable', 'string', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:80'],
            'lga' => ['nullable', 'string', 'max:80'],
            'previous_school' => ['nullable', 'string', 'max:150'],
            'level_applied_for_id' => ['nullable', 'exists:school_levels,id'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_relationship' => ['nullable', 'string', 'max:60'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:150'],
            'status' => ['required', 'string', 'in:' . implode(',', ApplicantStatus::values())],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $applicant->update($validated);

        return redirect()
            ->route('admin.applicants.show', $applicant)
            ->with('status', 'Applicant record updated.');
    }

    public function destroy(Applicant $applicant): RedirectResponse
    {
        $this->authorize('delete', $applicant);

        if ($applicant->student()->exists()) {
            return back()->with('error', 'This applicant has already been admitted and enrolled. Withdraw the student instead of deleting the record.');
        }

        $applicant->delete();

        return redirect()
            ->route('admin.applicants.index')
            ->with('status', 'Applicant deleted.');
    }

    /* ------------------------------------------------------------------ */
    /* Registration — done by the office, not the applicant                */
    /* ------------------------------------------------------------------ */

    /** The form the officer fills in from a paper application. */
    public function create(NumberSequenceService $sequences): View
    {
        $this->authorize('admissions.create');

        return view('admin.applicants.create', [
            'levels' => SchoolLevel::query()->where('is_active', true)->orderBy('order')->get(),
            'states' => \App\Support\NigerianStates::options(),
            'nextNumber' => $sequences->preview(\App\Enums\SequenceType::AdmissionRegistration),
            'session' => AcademicSession::query()->where('is_admission_open', true)->first()
                ?? AcademicSession::current(),
        ]);
    }

    public function store(StoreAdminApplicantRequest $request): RedirectResponse
    {
        $this->authorize('admissions.create');

        $applicant = $this->registration->register(
            $request->validated(),
            ['photo' => $request->file('photo')],
        );

        return redirect()
            ->route('admin.applicants.slip', $applicant)
            ->with('status', sprintf(
                '%s registered as %s. Print this slip for the parent, then add them to the examination candidate list.',
                $applicant->full_name,
                $applicant->registration_number,
            ));
    }

    /* ------------------------------------------------------------------ */
    /* Bulk registration                                                   */
    /* ------------------------------------------------------------------ */

    public function import(Request $request): View
    {
        $this->authorize('admissions.create');

        return view('admin.applicants.import', [
            'columns' => $this->imports->allColumns(),
            'staged' => $request->session()->get(self::IMPORT_SESSION_KEY),
        ]);
    }

    /** Parse the uploaded sheet and show exactly what would be registered. */
    public function previewImport(Request $request): RedirectResponse
    {
        $this->authorize('admissions.create');

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:8192'],
        ], [
            'file.required' => 'Choose the spreadsheet of candidates.',
            'file.mimes' => 'Upload a CSV or Excel file (.csv, .xlsx or .xls).',
            'file.max' => 'That file is larger than 8 MB. Split it into smaller batches.',
        ]);

        try {
            $parsed = $this->imports->parse($request->file('file'));
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        if ($parsed['rows'] === []) {
            return back()->withErrors([
                'file' => 'No candidate rows were found in that file. Rows need at least a surname, a first name, a class, and the parent\'s phone and email.',
            ]);
        }

        $request->session()->put(self::IMPORT_SESSION_KEY, $parsed + [
            'filename' => $request->file('file')->getClientOriginalName(),
            'staged_at' => now()->toIso8601String(),
        ]);

        $message = sprintf(
            '%d row(s) read — %d ready, %d need fixing.',
            $parsed['summary']['total'],
            $parsed['summary']['ok'],
            $parsed['summary']['errors'],
        );

        if ($parsed['truncated']) {
            $message .= sprintf(
                ' Only the first %d rows were read; split the rest into another file.',
                ApplicantImportService::MAX_ROWS,
            );
        }

        return redirect()
            ->route('admin.applicants.import')
            ->with($parsed['summary']['ok'] > 0 ? 'status' : 'error', $message);
    }

    /** Create the rows the officer left ticked. */
    public function commitImport(Request $request): RedirectResponse
    {
        $this->authorize('admissions.create');

        $staged = $request->session()->get(self::IMPORT_SESSION_KEY);

        if (! $staged) {
            return redirect()
                ->route('admin.applicants.import')
                ->with('error', 'That upload has expired. Please choose the file again.');
        }

        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['integer'],
        ], [
            'lines.required' => 'Tick at least one candidate to register.',
            'lines.min' => 'Tick at least one candidate to register.',
        ]);

        $result = $this->imports->commit($staged['rows'], $validated['lines'], $request->user());

        $request->session()->forget(self::IMPORT_SESSION_KEY);

        if ($result['imported'] === 0) {
            return redirect()
                ->route('admin.applicants.import')
                ->with('error', 'Nothing was registered. ' . collect($result['failed'])->pluck('reason')->unique()->implode(' '));
        }

        $message = sprintf(
            '%d applicant(s) registered — numbers %s to %s.',
            $result['imported'],
            $result['numbers'][0],
            end($result['numbers']),
        );

        if ($result['failed'] !== []) {
            $message .= ' ' . count($result['failed']) . ' row(s) could not be registered and were skipped.';
        }

        return redirect()
            ->route('admin.applicants.index')
            ->with('status', $message . ' They now appear in the applicants list and can be added to an examination.');
    }

    /**
     * A ready-made CSV so the office does not have to guess the columns.
     *
     * Only the columns the office registration form insists on, so the sheet is
     * as quick to fill in as the form is to type: a name, a class, and the
     * parent's phone and email.
     */
    public function downloadTemplate(): Response
    {
        $this->authorize('admissions.create');

        $rows = [
            $this->imports->templateHeaders(),
            ['Okafor', 'Chidera', 'JSS1', '08031234567', 'ngozi@example.com'],
        ];

        $handle = fopen('php://temp', 'r+');

        // A UTF-8 byte-order mark, or Excel mangles accented names.
        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="applicant-registration-template.csv"',
        ]);
    }

    /**
     * Printable admission letter.
     *
     * Refused unless the applicant was genuinely admitted — issuing a letter of
     * admission to somebody who failed is the one mistake this screen must make
     * impossible.
     */
    public function letter(Applicant $applicant, AdmissionLetterService $letters): View
    {
        $this->authorize('admissions.letters');
        $this->authorize('view', $applicant);

        abort_unless(
            $applicant->isAdmitted(),
            404,
            'This applicant has not been admitted, so no letter can be issued.',
        );

        return view('admin.applicants.letter', $letters->render($applicant));
    }

    /** The same letter as an A4 PDF, for printing or emailing. */
    public function letterPdf(Applicant $applicant, AdmissionLetterService $letters): Response
    {
        $this->authorize('admissions.letters');

        abort_unless(
            $applicant->isAdmitted(),
            404,
            'This applicant has not been admitted, so no letter can be issued.',
        );

        $pdf = Pdf::loadView('admin.applicants.letter-pdf', $letters->render($applicant))
            ->setPaper('a4');

        return $pdf->download('admission-letter-' . Str::slug((string) $applicant->registration_number) . '.pdf');
    }
}
