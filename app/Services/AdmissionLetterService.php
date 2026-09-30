<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Gathers everything the admission letter and registration slip need, so the
 * Blade views stay dumb and the wording lives in one place.
 */
class AdmissionLetterService
{
    /**
     * @return array<string,mixed>
     */
    public function data(Applicant $applicant): array
    {
        $applicant->loadMissing(['levelAppliedFor', 'academicSession', 'student', 'decisions.exam']);

        $decision = $applicant->decisions->sortByDesc('id')->first();
        $session = $applicant->academicSession ?? AcademicSession::current();

        return [
            'applicant' => $applicant,
            // Deliberately NOT called `school`: a global view composer shares a
            // `$school` object with every view and would overwrite this array.
            'letterhead' => [
                'name' => Setting::get('school_name', config('saci.school_name')),
                'address' => Setting::get('contact_address'),
                'phone' => Setting::get('contact_phone'),
                'email' => Setting::get('contact_email'),
            ],
            'level' => $applicant->levelAppliedFor?->name,
            'session' => $session?->name,
            'student' => $applicant->student,
            'decision' => $decision,
            'average' => $decision?->average_score,
            'cutoff' => $decision?->cutoff_mark,
            'position' => $decision?->position,
            'note' => Setting::get(
                'admission_letter_note',
                'Please report to the school office with this letter and your registration slip.',
            ),
            'issuedOn' => now(),
            'reference' => $applicant->registration_number,
            'studentNumber' => $applicant->student?->student_number,
            // The signature the school signs with, wherever it signs: the path on the
            // public disk, or nothing at all — a letter with no signature on it is a
            // letter the office can print and sign by hand.
            'signature' => Setting::get('signature_image'),
        ];
    }

    /**
     * The signature as the PDF renderer can draw it.
     *
     * A data URI, because DomPDF does not fetch images over HTTP: an <img> pointing
     * at the site comes out as a broken icon in the middle of a letter. Only the PDF
     * needs this — the pages use the URL, and inlining a scanned signature into a
     * sheet of forty letters would be a megabyte of base64 nobody asked for.
     */
    public function signatureDataUri(): ?string
    {
        $path = Setting::get('signature_image');

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return 'data:'.($disk->mimeType($path) ?: 'image/png').';base64,'
            .base64_encode((string) $disk->get($path));
    }

    /**
     * Details required on the registration slip shown right after applying.
     *
     * @return array<string,mixed>
     */
    public function slipData(Applicant $applicant): array
    {
        $applicant->loadMissing(['levelAppliedFor', 'academicSession']);

        return [
            'applicant' => $applicant,
            'school' => [
                'name' => Setting::get('school_name', config('saci.school_name')),
                'address' => Setting::get('contact_address'),
                'phone' => Setting::get('contact_phone'),
                'email' => Setting::get('contact_email'),
            ],
            'level' => $applicant->levelAppliedFor?->name,
            'session' => $applicant->academicSession?->name,
            'applicationFee' => (float) Setting::get('application_fee', 0),
            'currency' => Setting::get('currency_symbol', '₦'),
            'issuedOn' => $applicant->submitted_at ?? $applicant->created_at,
        ];
    }

    /**
     * The full, ready-to-print letter: the admin's wording from Settings with
     * every placeholder swapped for a real value.
     *
     * @return array<string,mixed>
     */
    public function render(Applicant $applicant): array
    {
        $data = $this->data($applicant);
        $values = $this->placeholders($data);

        $data['letter'] = [
            'title' => $this->fill(
                (string) Setting::get('admission_letter_title', 'Letter of Admission'),
                $values,
            ),
            'body' => $this->fill(
                (string) Setting::get('admission_letter_body') ?: $this->defaultBody(),
                $values,
            ),
            'signatory' => $this->fill((string) Setting::get('admission_letter_signatory'), $values),
            'signatoryTitle' => $this->fill(
                (string) Setting::get('admission_letter_signatory_title', 'Admissions Officer'),
                $values,
            ),
        ];

        $data['letterPlaceholders'] = $values;

        return $data;
    }

    /**
     * Replace `{key}`, `{{key}}` and `{{ key }}` — admins type all three.
     *
     * Unknown placeholders are left exactly as typed (rather than blanked) so a
     * typo is visible on the preview instead of silently vanishing.
     *
     * @param  array<string,string>  $values
     */
    public function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}|\{([a-z0-9_]+)\}/i',
            function (array $matches) use ($values): string {
                $key = strtolower($matches[1] !== '' ? $matches[1] : $matches[2]);

                return $values[$key] ?? $matches[0];
            },
            $text,
        );
    }

    /**
     * Every value an admin may drop into the letter.
     *
     * The names deliberately mirror the SMS templates (`full_name`, `class`,
     * `guardian_name`) so the same wording works in a letter and a text message.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,string>
     */
    public function placeholders(array $data): array
    {
        /** @var Applicant $applicant */
        $applicant = $data['applicant'];
        $currency = Setting::get('currency_symbol', '₦');
        $level = (string) ($data['level'] ?? '—');

        return [
            'school_name' => (string) ($data['letterhead']['name'] ?? ''),
            'school_address' => (string) ($data['letterhead']['address'] ?? ''),
            'school_phone' => (string) ($data['letterhead']['phone'] ?? ''),
            'school_email' => (string) ($data['letterhead']['email'] ?? ''),

            'guardian_name' => $applicant->guardian_name ?: 'Parent/Guardian',
            'full_name' => $applicant->full_name,
            // Kept as an alias: the first version of the settings used this name.
            'applicant_name' => $applicant->full_name,
            'first_name' => (string) $applicant->first_name,
            'surname' => (string) $applicant->last_name,

            'registration_number' => (string) $applicant->registration_number,
            'admission_number' => (string) ($data['studentNumber'] ?? '—'),

            'class' => $level,
            'level' => $level,
            'session' => (string) ($data['session'] ?? '—'),

            'average' => $data['average'] !== null ? number_format((float) $data['average'], 2).'%' : '—',
            'cutoff' => $data['cutoff'] !== null ? number_format((float) $data['cutoff'], 2).'%' : '—',
            'position' => $data['position'] !== null ? (string) $data['position'] : '—',

            'application_fee' => $currency.number_format((float) Setting::get('application_fee', 0), 2),
            'date' => now()->format('j F, Y'),
        ];
    }

    /** Used until an admin writes their own wording in Settings. */
    private function defaultBody(): string
    {
        return <<<'BODY'
        Dear {guardian_name},

        Following your performance in the entrance examination, we are pleased to offer {full_name}
        admission into {class} for the {session} academic session.

        Registration number: {registration_number}
        Admission number: {admission_number}

        Kindly report to the school office with this letter and your registration slip to complete
        enrolment and collect the fee schedule.

        Congratulations.
        BODY;
    }
}
