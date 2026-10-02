@extends('layouts.admin')

@section('title', 'My profile')
@section('subtitle', $user->primaryRole())

@section('content')
<div class="grid gap-6 lg:grid-cols-2">

    {{-- ================= Details ================= --}}
    <form method="POST" action="{{ route('profile.update') }}" class="card-pad">
        @csrf
        @method('PUT')

        <h2 class="text-base font-semibold text-ink">Your details</h2>
        <p class="mt-1 text-sm text-muted">This is how your name appears throughout the platform.</p>

        <div class="mt-6 space-y-5">
            <x-field name="name" label="Full name" required :value="$user->name" />
            <x-field name="email" label="Email address (optional)" type="email" :value="$user->email" />
            <x-field name="phone" label="Phone number" :value="$user->phone" />
        </div>

        <button type="submit" class="btn-primary mt-6">Save changes</button>
    </form>

    {{-- ================= Password ================= --}}
    <form method="POST" action="{{ route('profile.password') }}" class="card-pad">
        @csrf
        @method('PUT')

        <h2 class="text-base font-semibold text-ink">Change your password</h2>

        <p class="mt-1 text-sm text-muted">Use at least 8 characters.</p>

        <div class="mt-6 space-y-5">
            <x-field name="current_password" label="Current password" type="password" required autocomplete="current-password" />
            <x-field name="password" label="New password" type="password" required autocomplete="new-password" />
            <x-field name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />
        </div>

        <button type="submit" class="btn-primary mt-6">Change password</button>
    </form>
</div>

{{-- ================= Session ================= --}}
<div class="mt-6 card-pad">
    <h2 class="text-base font-semibold text-ink">Security</h2>

    <dl class="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-3 text-sm">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wider text-muted">Last signed in</dt>
            <dd class="mt-1 text-ink-soft">{{ $user->last_login_at?->format('j M Y, g:ia') ?? 'This session' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wider text-muted">Roles</dt>
            <dd class="mt-1 text-ink-soft">{{ $user->getRoleNames()->implode(', ') ?: '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wider text-muted">Permissions</dt>
            <dd class="mt-1 text-ink-soft">{{ $user->getAllPermissions()->count() }}</dd>
        </div>
    </dl>

    <form method="POST" action="{{ route('logout') }}" class="mt-6 border-t border-line pt-5">
        @csrf
        <button type="submit" class="btn-secondary">Sign out of this device</button>
    </form>
</div>
@endsection
