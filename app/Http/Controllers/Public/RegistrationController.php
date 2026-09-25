<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreApplicantRequest;
use App\Models\Applicant;
use App\Models\SchoolLevel;
use App\Models\Setting;
use App\Services\AdmissionLetterService;
use App\Services\ApplicantRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function __construct(
        private readonly ApplicantRegistrationService $registration,
        private readonly AdmissionLetterService $letters,
    ) {
    }

    public function create(): View|RedirectResponse
    {
        if (! $this->registrationIsOpen()) {
            return redirect()
                ->route('public.status')
                ->with('error', 'Admission registration is handled by the school office, not online. '
                    . 'Please contact or visit the school to register your child, then check the application '
                    . 'status here with the registration number you are given.');
        }

        return view('public.register', [
            'levels' => SchoolLevel::query()->active()->get(),
            'states' => \App\Support\NigerianStates::options(),
            'applicationFee' => (float) Setting::get('application_fee', 0),
            'currency' => Setting::get('currency_symbol', '₦'),
        ]);
    }

    public function store(StoreApplicantRequest $request): RedirectResponse
    {
        if (! $this->registrationIsOpen()) {
            return redirect()
                ->route('public.status')
                ->with('error', 'Admission registration is handled by the school office, not online. Please contact the school.');
        }

        $applicant = $this->registration->register(
            $request->validated(),
            [
                'photo' => $request->file('photo'),
                'documents' => $request->file('documents') ?? [],
            ],
        );

        // The slip is only shown immediately after registering.
        $request->session()->put('registered_applicant_id', $applicant->id);

        return redirect()->route('public.register.done');
    }

    /** Show the registration slip straight after submitting. */
    public function done(Request $request): View|RedirectResponse
    {
        $applicantId = $request->session()->get('registered_applicant_id');

        if (! $applicantId) {
            return redirect()->route('public.register');
        }

        $applicant = Applicant::query()
            ->with(['levelAppliedFor', 'academicSession'])
            ->findOrFail($applicantId);

        return view('public.registered', [
            'slip' => $this->letters->slipData($applicant),
            'applicant' => $applicant,
            'justRegistered' => true,
        ]);
    }

    /**
     * Reprint a slip later. Requires the registration number plus the surname,
     * so a number alone never exposes somebody else's record.
     */
    public function slip(Request $request, Applicant $applicant): View
    {
        $surname = strtolower(trim((string) $request->query('surname')));

        abort_unless(
            $surname !== '' && strtolower(trim($applicant->last_name)) === $surname,
            404,
            'That registration number and surname do not match our records.',
        );

        return view('public.registered', [
            'slip' => $this->letters->slipData($applicant),
            'applicant' => $applicant,
            'justRegistered' => false,
        ]);
    }

    /**
     * The one definition of "may the public register themselves".
     *
     * Shared with the public layout through the container, so the form and the
     * copy can never disagree — this used to be a second Setting read with a
     * different default, and it ignored whether the session was even open.
     */
    protected function registrationIsOpen(): bool
    {
        return (bool) app('saci.public_registration_open');
    }
}
