<?php

namespace App\Http\Requests\Auth;

use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Still called `email` because that is what the form posts and what the
            // error bag is read by, but it holds either one: the office signs in with
            // an address, a teacher with the phone number on their record. Which of
            // the two it is gets worked out in authenticate().
            'email' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Attempt to authenticate, throttling repeated failures.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        [$column, $value] = $this->identifier();

        if ($value === null || ! Auth::attempt([$column => $value, 'password' => $this->input('password')], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Which column the typed identifier belongs to, and the value to look up.
     *
     * A teacher types the phone number on their record, written however they write
     * it — with the country code, without it, with spaces in it. It is folded to the
     * stored shape before it is looked up, so `+2348031234567`, `2348031234567` and
     * `08031234567` all reach the same account.
     *
     * @return array{0:string,1:string|null}
     */
    protected function identifier(): array
    {
        $identifier = trim((string) $this->input('email'));

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return ['email', $identifier];
        }

        return ['phone', PhoneNumber::normalize($identifier)];
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->input('email')).'|'.$this->ip());
    }
}
