<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * The admissions officer typing a paper form in from the office.
 *
 * Looser than the public request — there is no applicant present to sign a
 * declaration, and a record can be opened from just a name and a class.
 *
 * The parent's phone and email ARE required, unlike everything else here: they
 * are the school's point of contact and the details the fee account is opened
 * in, so a record without them cannot be taken any further.
 *
 * The applicant's own phone and email are deliberately not collected. The parent
 * is the account holder.
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

            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'lga' => ['nullable', 'string', 'max:80'],

            'level_applied_for_id' => [
                'required',
                Rule::exists('school_levels', 'id')->where('is_active', true),
            ],

            'guardian_name' => ['nullable', 'string', 'max:120'],
            'guardian_relationship' => ['nullable', 'string', 'max:60'],
            'guardian_phone' => ['required', 'string', 'max:30'],
            'guardian_email' => ['required', 'email', 'max:150'],

            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            // No declaration: the applicant is not present to sign it.
            // No documents: the office form does not collect them.
        ];
    }

    public function messages(): array
    {
        return [
            'level_applied_for_id.required' => 'Choose the class the applicant is applying for.',
            'first_name.required' => 'Enter the applicant\'s first name.',
            'last_name.required' => 'Enter the applicant\'s surname.',
            'date_of_birth.after' => 'Please enter a valid date of birth.',
            'guardian_phone.required' => 'Enter the parent\'s phone number — it is needed to open the fee account.',
            'guardian_email.required' => 'Enter the parent\'s email address — it is needed to open the fee account.',
            'guardian_email.email' => 'That email address does not look right.',
        ];
    }
}
