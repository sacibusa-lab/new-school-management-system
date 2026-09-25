@extends('layouts.guest')

@section('content')
    <h1 class="font-display text-2xl font-semibold text-slate-900">Welcome back</h1>
    <p class="mt-2 text-sm text-slate-500">
        Sign in to manage admissions, results and fees.
    </p>

    @if (session('status'))
        <div class="mt-6"><x-alert tone="success">{{ session('status') }}</x-alert></div>
    @endif

    <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
        @csrf

        <x-field
            name="email"
            label="Email address"
            type="email"
            required
            autofocus
            autocomplete="username"
            placeholder="you@school.edu.ng" />

        <x-field
            name="password"
            label="Password"
            type="password"
            required
            autocomplete="current-password"
            placeholder="••••••••" />

        <div class="flex items-center justify-between">
            <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1"
                       class="h-4 w-4 rounded border-slate-300 text-brand-700 focus:ring-brand-500">
                Remember me
            </label>
        </div>

        <button type="submit" class="btn-primary w-full">Sign in</button>
    </form>

    <div class="mt-8 rounded-xl bg-slate-50 p-4 text-xs text-slate-600 ring-1 ring-slate-200">
        <p class="font-semibold text-slate-700">Are you a student or parent?</p>
        <p class="mt-1">
            You do not need an account to check your admission status.
            <a href="{{ route('public.status') }}" class="font-medium text-brand-700 underline decoration-brand-300 underline-offset-2">Check your status here</a>.
        </p>
    </div>
@endsection
