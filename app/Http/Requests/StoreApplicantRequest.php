<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Models\SchoolLevel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApplicantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],

            'gender' => ['required', Rule::enum(Gender::class)],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1990-01-01'],
            'nationality' => ['nullable', 'string', 'max:60'],

            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:30'],

            'address' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['required', 'string', 'max:80'],
            'lga' => ['nullable', 'string', 'max:80'],

            'previous_school' => ['nullable', 'string', 'max:150'],

            'level_applied_for_id' => [
                'required',
                Rule::exists('school_levels', 'id')->where('is_active', true),
            ],

            'guardian_name' => ['required', 'string', 'max:120'],
            'guardian_relationship' => ['required', 'string', 'max:60'],
            'guardian_phone' => ['required', 'string', 'max:30'],
            'guardian_email' => ['nullable', 'email', 'max:150'],
            'guardian_address' => ['nullable', 'string', 'max:255'],

            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'documents' => ['nullable', 'array', 'max:5'],
            'documents.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:4096'],

            'declaration' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'level_applied_for_id.required' => 'Please choose the class you are applying for.',
            'declaration.accepted' => 'Please confirm that the information provided is correct.',
            'date_of_birth.after' => 'Please enter a valid date of birth.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'first_name' => $this->clean($this->input('first_name')),
            'middle_name' => $this->clean($this->input('middle_name')),
            'last_name' => $this->clean($this->input('last_name')),
            'email' => $this->cleanLower($this->input('email')),
            'guardian_email' => $this->cleanLower($this->input('guardian_email')),
            'nationality' => $this->clean($this->input('nationality')) ?: 'Nigerian',
        ]);
    }

    protected function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : null;

        return $value === '' ? null : $value;
    }

    protected function cleanLower(mixed $value): ?string
    {
        return ($clean = $this->clean($value)) ? strtolower($clean) : null;
    }
}
