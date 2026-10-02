@extends('layouts.guest')

@section('content')
    <h1 class="font-display text-2xl font-semibold text-ink">Welcome back</h1>
    <p class="mt-2 text-sm text-muted">
        Sign in to manage admissions, results and fees.
    </p>

    @if (session('status'))
        <div class="mt-6"><x-alert tone="success">{{ session('status') }}</x-alert></div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
        @csrf

        <x-field
            name="email"
            label="Phone number or email"
            type="text"
            required
            autofocus
            autocomplete="username"
            placeholder="080 1234 5678" />

        <x-field
            name="password"
            label="Password"
            type="password"
            required
            autocomplete="current-password"
            placeholder="••••••••" />

        <div class="flex items-center justify-between">
            <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                <input type="checkbox" name="remember" value="1"
                       class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                Remember me
            </label>
        </div>

        <button type="submit" class="btn-primary w-full">Sign in</button>
    </form>

    <div class="mt-8 rounded-xl bg-surface-2 p-4 text-xs text-ink-soft ring-1 ring-line">
        <p class="font-semibold text-ink-soft">Are you a student or parent?</p>
        <p class="mt-1">
            You do not need an account to check your admission status.
            <a href="{{ route('public.status') }}" class="font-medium text-brand-700 dark:text-brand-200 underline decoration-brand-300 underline-offset-2">Check your status here</a>.
        </p>
    </div>
@endsection
