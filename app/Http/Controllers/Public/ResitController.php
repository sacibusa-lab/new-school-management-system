<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Setting;
use App\Services\Admissions\ResitService;
use App\Services\Sms\SmsNotifier;
use App\Support\Concerns\FindsRecordsByNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Public self-service resit registration.
 *
 * A candidate who did not make the cutoff can book a resit from the same page
 * where they read their result, without telephoning the office.
 *
 * The registration number alone is enough here — and that is deliberate. It is
 * already needed to read the result, and a resit is not sensitive: it only ever
 * adds blank mark rows for the person who owns that number. Nobody can use this
 * to read or change another family's data.
 */
class ResitController extends Controller
{
    use FindsRecordsByNumber;

    public function __construct(
        private readonly ResitService $resits,
        private readonly SmsNotifier $sms,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'registration_number' => ['required', 'string', 'max:40'],
        ], [
            'registration_number.required' => 'Enter the registration number to book a resit.',
        ]);

        if (! $this->resits->isEnabled()) {
            return back()->with('error', 'Resit applications are closed at the moment. Please contact the school office.');
        }

        $applicant = $this->findByNumber(
            Applicant::query(),
            'registration_number',
            $validated['registration_number'],
            (int) (Setting::get('admission_number_padding') ?: 5),
        )->first();

        if (! $applicant) {
            return back()
                ->withErrors(['registration_number' => 'We could not find that registration number. Check it on your registration slip and try again.'])
                ->withInput();
        }

        if ($applicant->isAdmitted()) {
            return back()->with(
                'error',
                "{$applicant->full_name} has already been offered admission, so a resit is not needed.",
            );
        }

        $result = $this->resits->selfRegister($applicant, $request->user());

        if ($result === null) {
            return back()->with(
                'error',
                'There is no examination record for that number yet, so there is nothing to re-sit. Please check back after the results are published.',
            );
        }

        // Best-effort confirmation — a dead gateway must not undo the booking.
        $this->sms->resitRegistered($applicant, $result['exam']);

        $papers = $result['created'];

        if ($result['already']) {
            return redirect()
                ->route('public.status', ['registration_number' => $applicant->registration_number])
                ->with('status', 'You are already registered for the resit examination. Please check back here for the date.');
        }

        $message = $papers === 1
            ? 'You are registered for one resit paper.'
            : "You are registered for {$papers} resit papers.";

        if ($result['exam']->exam_date) {
            $message .= ' Date: ' . $result['exam']->exam_date->format('j F, Y') . '.';
        } else {
            $message .= ' The school will publish the date shortly.';
        }

        return redirect()
            ->route('public.status', ['registration_number' => $applicant->registration_number])
            ->with('status', $message);
    }
}
