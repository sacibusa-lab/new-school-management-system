<?php

namespace App\Services\Admissions;

use App\Models\Applicant;
use Illuminate\Support\Collection;

/**
 * Spotting the same child being registered twice.
 *
 * Deliberately matches the name AND the parent's phone number, never the number
 * on its own: brothers and sisters share a parent's number, and a school that
 * cried duplicate at every sibling would teach the office to ignore the warning.
 *
 * It is a warning and never a refusal. Twins exist and names repeat, and the
 * person at the desk can see the two children; the system cannot.
 */
class DuplicateApplicantService
{
    /**
     * Applicants already on file who look like this one.
     *
     * @return Collection<int,Applicant>
     */
    public function find(
        ?string $firstName,
        ?string $lastName,
        ?string $guardianPhone,
        ?int $exceptId = null,
    ): Collection {
        $first = $this->name($firstName);
        $last = $this->name($lastName);
        $phone = $this->phone($guardianPhone);

        // Nothing to compare, so nothing to say.
        if ($first === '' || $last === '' || $phone === '') {
            return collect();
        }

        return Applicant::query()
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))
            // Narrowed by the name, which the database can compare for us ...
            ->whereRaw('LOWER(first_name) = ?', [$first])
            ->whereRaw('LOWER(last_name) = ?', [$last])
            ->inNameOrder()
            ->get()
            // ... and settled by the phone, which it cannot: the same number is
            // stored as 08031234567, +2348031234567 and 0803 123 4567, all with
            // equal honesty, depending on who typed it.
            ->filter(fn (Applicant $applicant) => $this->phone($applicant->guardian_phone) === $phone)
            ->values();
    }

    /** A one-line description for the officer, e.g. "Chidera Okafor (SAC-00001)". */
    public function describe(Applicant $applicant): string
    {
        return "{$applicant->full_name} ({$applicant->registration_number})";
    }

    protected function name(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * The last ten digits.
     *
     * So a number written for a Nigerian network (08031234567) and the same number
     * written in international form (+2348031234567) are one number, not two.
     */
    protected function phone(?string $value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }
}
