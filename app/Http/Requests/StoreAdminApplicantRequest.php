<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * The admissions officer typing a paper form in from the office.
 *
 * Deliberately much looser than the public request: there is no applicant
 * standing there to sign a declaration, and a bulk upload rarely carries a full
 * address. Only the name and the class are truly needed to open a record — the
 * rest can be filled in later from the applicant's page.
 */
class StoreAdminApplicantRequest extends StoreApplicantRequest
{
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],

            'gender' => ['nullable', 'string', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1990-01-01'],
            'nationality' => ['nullable', 'string', 'max:60'],

            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],

            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'lga' => ['nullable', 'string', 'max:80'],

            'previous_school' => ['nullable', 'string', 'max:150'],

            'level_applied_for_id' => [
                'required',
                Rule::exists('school_levels', 'id')->where('is_active', true),
            ],

            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_relationship' => ['nullable', 'string', 'max:60'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:150'],
            'guardian_address' => ['nullable', 'string', 'max:255'],

            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            // Scanned birth certificate, previous result, and so on. Accepted here
            // too, because with registration moved into the office the public form
            // may never be used.
            'documents' => ['nullable', 'array', 'max:5'],
            'documents.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],

            // No declaration: the applicant is not present to sign it.
        ];
    }

    public function messages(): array
    {
        return [
            'level_applied_for_id.required' => 'Choose the class the applicant is applying for.',
            'first_name.required' => 'Enter the applicant\'s first name.',
            'last_name.required' => 'Enter the applicant\'s surname.',
            'date_of_birth.after' => 'Please enter a valid date of birth.',
        ];
    }
}
