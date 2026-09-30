<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApplicantStatus;
use App\Enums\SequenceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdminApplicantRequest;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Exam;
use App\Models\SchoolLevel;
use App\Models\Score;
use App\Services\AdmissionLetterService;
use App\Services\Admissions\ApplicantDocumentService;
use App\Services\Admissions\ApplicantImportService;
use App\Services\Admissions\ApplicantPhotoService;
use App\Services\Admissions\DuplicateApplicantService;
use App\Services\ApplicantRegistrationService;
use App\Services\NumberSequenceService;
use App\Support\NigerianStates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ApplicantController extends Controller
{
    /** Where the parsed-but-not-yet-committed import lives between steps. */
    private const IMPORT_SESSION_KEY = 'applicant_import';

    public function __construct(
        private readonly ApplicantRegistrationService $registration,
        private readonly ApplicantImportService $imports,
        private readonly ApplicantPhotoService $photos,
        private readonly ApplicantDocumentService $documents,
        private readonly DuplicateApplicantService $duplicates,
    ) {}

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
            // The papers the office prints from the applicant list: an admissions
            // officer holds the letters permission but cannot open the cutoff desk,
            // so without these the merit list and the letters would be unreachable.
            'exams' => Exam::query()
                ->with('level')
                ->when($session = AcademicSession::current(), fn ($q) => $q->where('academic_session_id', $session->id))
                ->orderByDesc('exam_date')
                ->orderByDesc('id')
                ->get(),
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
     * @return Builder<Applicant>
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

        $filename = 'applicants-'.now()->format('Y-m-d-His').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
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
            'status' => ['required', 'string', 'in:'.implode(',', ApplicantStatus::values())],
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
    /* Registration — done by the office, not the applicant */
    /* ------------------------------------------------------------------ */

    /** The form the officer fills in from a paper application. */
    public function create(NumberSequenceService $sequences): View
    {
        $this->authorize('admissions.create');

        return view('admin.applicants.create', [
            'levels' => SchoolLevel::query()->where('is_active', true)->orderBy('order')->get(),
            'states' => NigerianStates::options(),
            'nextNumber' => $sequences->preview(SequenceType::AdmissionRegistration),
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

        $this->warnAboutDuplicates([$applicant]);

        return redirect()
            ->route('admin.applicants.slip', $applicant)
            ->with('status', sprintf(
                '%s registered as %s. Print this slip for the parent, then add them to the examination candidate list.',
                $applicant->full_name,
                $applicant->registration_number,
            ));
    }

    /**
     * Say something when the same child looks like they are already on file.
     *
     * A warning, never a refusal: the person at the desk can see whether these are
     * two children or one, and the system cannot. The duplicate is still registered
     * — blocking a real twin would be worse than a second record somebody can
     * delete after looking.
     *
     * @param  array<int,Applicant>  $applicants
     */
    protected function warnAboutDuplicates(array $applicants): void
    {
        $found = [];

        foreach ($applicants as $applicant) {
            foreach ($this->duplicates->find(
                $applicant->first_name,
                $applicant->last_name,
                $applicant->guardian_phone,
                $applicant->id,
            ) as $match) {
                $found[] = sprintf(
                    '%s looks like %s, who is already on file with the same name and parent phone number.',
                    $applicant->registration_number,
                    $this->duplicates->describe($match),
                );
            }
        }

        if ($found === []) {
            return;
        }

        session()->flash('warning', 'Possible duplicate — '.implode(' ', array_unique($found)).' Check it is not the same child, and delete one of the two if it is.');
    }

    /* ------------------------------------------------------------------ */
    /* Bulk registration */
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

        // Mark the rows that look like somebody already on file. A note, not an
        // error: the officer can see the list and decides, and a sheet of siblings
        // must not read as a sheet of mistakes.
        $parsed['rows'] = array_map(function (array $row): array {
            if ($row['errors'] === []) {
                $row['duplicates'] = $this->duplicates->find(
                    $row['data']['first_name'] ?? null,
                    $row['data']['last_name'] ?? null,
                    $row['data']['guardian_phone'] ?? null,
                )->map(fn ($match) => $this->duplicates->describe($match))->all();
            }

            return $row;
        }, $parsed['rows']);

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
                ->with('error', 'Nothing was registered. '.collect($result['failed'])->pluck('reason')->unique()->implode(' '));
        }

        $message = sprintf(
            '%d applicant(s) registered — numbers %s to %s.',
            $result['imported'],
            $result['numbers'][0],
            end($result['numbers']),
        );

        if ($result['failed'] !== []) {
            $message .= ' '.count($result['failed']).' row(s) could not be registered and were skipped.';
        }

        return redirect()
            ->route('admin.applicants.index')
            ->with('status', $message.' They now appear in the applicants list and can be added to an examination.');
    }

    /* ------------------------------------------------------------------ */
    /* Photographs */
    /* ------------------------------------------------------------------ */

    /**
     * Add photographs in bulk, matched to candidates by the number in each file
     * name.
     *
     * Names arrive in a spreadsheet; photographs do not. This is the other half
     * of a bulk registration: drop in "SAC-00001.jpg", "SAC-00002.jpg" and so on,
     * see whose each one was worked out to be, and only then attach them.
     */
    public function photos(): View
    {
        $this->authorize('admissions.update');

        $staged = session(ApplicantPhotoService::SESSION_KEY);

        return view('admin.applicants.photos', [
            'staged' => $staged['rows'] ?? null,
            'filename' => $staged['batch'] ?? null,
            'withoutPhoto' => Applicant::query()->whereNull('photo_path')->count(),
            'withPhoto' => Applicant::query()->whereNotNull('photo_path')->count(),
            'maxFiles' => ApplicantPhotoService::MAX_FILES,
            'maxKb' => ApplicantPhotoService::MAX_KB,
            'extensions' => ApplicantPhotoService::EXTENSIONS,
        ]);
    }

    public function previewPhotos(Request $request): RedirectResponse
    {
        $this->authorize('admissions.update');

        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.ApplicantPhotoService::MAX_FILES],
            'photos.*' => [
                'file',
                'mimes:'.ApplicantPhotoService::EXTENSIONS,
                'max:'.ApplicantPhotoService::MAX_KB,
            ],
        ], [
            'photos.required' => 'Choose the photographs to upload.',
            'photos.max' => 'That is more than '.ApplicantPhotoService::MAX_FILES.' photographs. Split them into batches.',
            'photos.*.mimes' => 'Every file has to be a photograph — JPG, PNG or WEBP.',
            'photos.*.max' => 'Each photograph has to be under '.(int) (ApplicantPhotoService::MAX_KB / 1024).' MB.',
        ]);

        // A fresh upload replaces the previous batch, including its parked files.
        $this->clearStagedBatch();

        $batch = (string) Str::uuid();

        $rows = $this->photos->stage($request->file('photos') ?? [], $batch);

        $request->session()->put(ApplicantPhotoService::SESSION_KEY, [
            'batch' => $batch,
            'rows' => $rows,
        ]);

        $matched = collect($rows)->whereNull('problem')->count();
        $unmatched = count($rows) - $matched;

        return redirect()
            ->route('admin.applicants.photos')
            ->with($matched > 0 ? 'status' : 'error', sprintf(
                '%d photograph(s) read — %d matched to a candidate, %d could not be placed.',
                count($rows),
                $matched,
                $unmatched,
            ));
    }

    public function commitPhotos(Request $request): RedirectResponse
    {
        $this->authorize('admissions.update');

        $staged = $request->session()->get(ApplicantPhotoService::SESSION_KEY);

        if (! $staged) {
            return redirect()
                ->route('admin.applicants.photos')
                ->with('error', 'That upload has expired. Please choose the photographs again.');
        }

        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['integer'],
        ], [
            'rows.required' => 'Tick at least one photograph to attach.',
        ]);

        $result = $this->photos->commit($staged['rows'], $validated['rows'], $request->user());

        $request->session()->forget(ApplicantPhotoService::SESSION_KEY);

        if ($result['attached'] === 0) {
            return redirect()
                ->route('admin.applicants.photos')
                ->with('error', 'Nothing was attached. '.implode(' ', $result['failed']));
        }

        $message = $result['attached'].' photograph(s) attached to their candidates.';

        if ($result['skipped'] > 0) {
            $message .= ' '.$result['skipped'].' file(s) were left alone.';
        }

        return redirect()
            ->route('admin.applicants.index')
            ->with('status', $message);
    }

    /** The thumbnail for one staged photograph, read from the parked batch. */
    public function stagedPhoto(int $index): Response
    {
        $this->authorize('admissions.update');

        $staged = session(ApplicantPhotoService::SESSION_KEY);

        abort_if(! $staged, 404);

        $contents = $this->photos->stagedContents($staged['rows'], $index);

        abort_if($contents === null, 404);

        return response($contents)
            ->header('Content-Type', $this->imageType($staged['rows'][$index]['name']))
            ->header('Cache-Control', 'no-store');
    }

    /** Add or replace one applicant's photograph, from their own page. */
    public function updatePhoto(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('admissions.update');

        $validated = $request->validate([
            'photo' => ['required', 'file', 'mimes:'.ApplicantPhotoService::EXTENSIONS, 'max:'.ApplicantPhotoService::MAX_KB],
        ], [
            'photo.mimes' => 'That has to be a photograph — JPG, PNG or WEBP.',
            'photo.max' => 'That photograph is over '.(int) (ApplicantPhotoService::MAX_KB / 1024).' MB.',
        ]);

        $this->photos->store($applicant, $validated['photo']);

        return back()->with('status', 'Photograph saved for '.$applicant->full_name.'.');
    }

    public function destroyPhoto(Applicant $applicant): RedirectResponse
    {
        $this->authorize('admissions.update');

        $this->photos->remove($applicant);

        return back()->with('status', 'Photograph removed from '.$applicant->full_name.'.');
    }

    /**
     * Attach the papers a family brings in — birth certificate, testimonial, and
     * the like — to this applicant's record.
     */
    public function storeDocuments(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('admissions.update');

        $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:'.ApplicantDocumentService::MAX_PER_UPLOAD],
            'documents.*' => [
                'file',
                'mimes:'.ApplicantDocumentService::EXTENSIONS,
                'max:'.ApplicantDocumentService::MAX_KB,
            ],
        ], [
            'documents.required' => 'Choose the file(s) to attach.',
            'documents.*.mimes' => 'Documents have to be a PDF, JPG, PNG or WEBP.',
            'documents.*.max' => 'A document may not be over '.(int) (ApplicantDocumentService::MAX_KB / 1024).' MB.',
            'documents.max' => 'Attach at most '.ApplicantDocumentService::MAX_PER_UPLOAD.' files at a time.',
        ]);

        $count = 0;

        try {
            foreach ($request->file('documents') as $file) {
                $this->documents->store($applicant, $file, $request->user());
                $count++;
            }
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($count === 0) {
            return back()->with('error', 'No file was attached. Choose the document and try again.');
        }

        return back()->with('status', sprintf(
            '%d document(s) attached to %s.',
            $count,
            $applicant->full_name,
        ));
    }

    /** Take one document off this applicant. */
    public function destroyDocument(Request $request, Applicant $applicant): RedirectResponse
    {
        $this->authorize('admissions.update');

        $validated = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $removed = $this->documents->remove($applicant, $validated['path'], $request->user());

        if (! $removed) {
            return back()->with('error', 'That document is not held against this applicant.');
        }

        return back()->with('status', 'Document removed from '.$applicant->full_name.'.');
    }

    /** Drop the previous batch's parked files so nothing is left behind. */
    protected function clearStagedBatch(): void
    {
        $previous = session(ApplicantPhotoService::SESSION_KEY);

        foreach ($previous['rows'] ?? [] as $row) {
            if (isset($row['path']) && Storage::disk('local')->exists($row['path'])) {
                Storage::disk('local')->delete($row['path']);
            }
        }
    }

    protected function imageType(string $fileName): string
    {
        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
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

        $pdf = Pdf::loadView('admin.applicants.letter-pdf', $letters->render($applicant) + [
            'signatureData' => $letters->signatureDataUri(),
            'letterheadData' => $letters->letterheadForPdf(),
        ])->setPaper('a4');

        return $pdf->download('admission-letter-'.Str::slug((string) $applicant->registration_number).'.pdf');
    }
}
