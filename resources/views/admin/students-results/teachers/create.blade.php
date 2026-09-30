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

        <form method="POST" action="{{ route('admin.students-results.teachers.store') }}"
              enctype="multipart/form-data" class="space-y-5 p-5 sm:p-6">
            @csrf

            {{-- Not required: a teacher is taken on before their photograph is to
                 hand, and a record without a picture is still a record. --}}
            <div class="flex items-center gap-4 rounded-xl border border-line-soft bg-surface-2 p-4">
                <span class="inline-flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-surface-3 text-muted">
                    <x-nav-icon name="camera" class="h-6 w-6" />
                </span>

                <div class="min-w-0 flex-1">
                    <label for="avatar" class="label">Profile image</label>

                    <input id="avatar" name="avatar" type="file" accept=".jpg,.jpeg,.png,.webp"
                           class="block w-full text-xs text-ink-soft file:mr-2 file:rounded-md file:border-0 file:bg-surface-3 file:px-2.5 file:py-1.5 file:text-xs">

                    <p class="hint">Optional. JPG, PNG or WebP, up to 2 MB.</p>

                    @error('avatar')
                        <p class="error-text">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <x-field name="name" label="Full name" placeholder="Chidera Okafor" required />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field name="email" type="email" label="Email" placeholder="chidera@example.com" required
                         autocomplete="off" />

                <x-field name="phone" label="Phone" placeholder="080 1234 5678" required
                         hint="The school texts teachers, so this is how they are reached." />
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
