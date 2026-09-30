@extends('layouts.admin')

@section('title', 'Add Teachers')

@section('content')
    <div class="card mx-auto max-w-2xl overflow-hidden">
        <div class="border-b border-line bg-surface-2 px-5 py-4">
            <p class="font-display text-base font-semibold text-ink">Add a teacher</p>
            <p class="mt-1 text-sm text-muted">
                This creates the account they sign in with as well as the record itself. The role is
                Teacher — that is what being on this page means — and they choose a password of their
                own the first time they sign in.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.students-results.teachers.store') }}" class="space-y-5 p-5 sm:p-6">
            @csrf

            <x-field name="name" label="Full name" placeholder="Chidera Okafor" required />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field name="email" type="email" label="Email" placeholder="chidera@example.com" required
                         autocomplete="off" />

                <x-field name="phone" label="Phone" placeholder="080 1234 5678"
                         hint="Optional, but it is how the school reaches them." />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field name="password" type="password" label="Password" required
                         hint="At least 8 characters. Give it to them; they change it on first sign-in."
                         autocomplete="new-password" />

                <x-field name="password_confirmation" type="password" label="Confirm password" required
                         autocomplete="new-password" />
            </div>

            <div class="flex items-center justify-end gap-4 border-t border-line-soft pt-5">
                <a href="{{ route('admin.students-results.teachers.list') }}"
                   class="text-xs text-muted transition-colors hover:text-ink-soft">
                    Cancel
                </a>

                <button type="submit" class="btn-primary btn-sm">
                    <x-nav-icon name="check" class="h-3.5 w-3.5" />
                    Add teacher
                </button>
            </div>
        </form>
    </div>
@endsection
