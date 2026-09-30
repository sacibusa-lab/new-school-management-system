@extends('layouts.admin')

@section('title', 'Edit Teacher')

@section('content')
    <div class="card mx-auto max-w-2xl overflow-hidden">
        <div class="border-b border-line bg-surface-2 px-5 py-4">
            <p class="font-display text-base font-semibold text-ink">Edit {{ $teacher->name }}</p>
            <p class="mt-1 text-sm text-muted">
                What the register says about them. Their password is theirs: they change it from their own
                profile, and this page can neither read nor set it.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.students-results.teachers.update', $teacher) }}"
              enctype="multipart/form-data" class="space-y-5 p-5 sm:p-6">
            @csrf
            @method('PUT')

            {{-- The picture that is on the register now, so the office can see what
                 they are about to replace rather than remembering. --}}
            <div class="flex items-start gap-4 rounded-xl border border-line-soft bg-surface-2 p-4">
                <div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-surface-3 text-muted">
                    @if ($teacher->avatar_path)
                        <img src="{{ asset('storage/' . $teacher->avatar_path) }}"
                             alt="Photograph of {{ $teacher->name }}"
                             class="h-full w-full object-cover">
                    @else
                        <x-nav-icon name="camera" class="h-6 w-6" />
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <label for="avatar" class="label">Profile image</label>

                    <input id="avatar" name="avatar" type="file" accept=".jpg,.jpeg,.png,.webp"
                           class="block w-full text-xs text-ink-soft file:mr-2 file:rounded-md file:border-0 file:bg-surface-3 file:px-2.5 file:py-1.5 file:text-xs">

                    @error('avatar')
                        <p class="error-text">{{ $message }}</p>
                    @enderror

                    {{-- Only where there is something to take back, and never
                         pre-ticked: an unticked box is not a decision to keep it. --}}
                    @if ($teacher->avatar_path)
                        <label class="mt-2 inline-flex items-center gap-2 text-xs text-ink-soft">
                            <input type="checkbox" name="remove_avatar" value="1"
                                   class="h-3.5 w-3.5 rounded border-line text-rose-600 dark:text-rose-400 focus:ring-rose-500">
                            Remove the current photograph
                        </label>
                    @endif

                    <p class="hint">Optional. JPG, PNG or WebP, up to 2 MB.</p>
                </div>
            </div>

            <x-field name="name" label="Full name" required :value="$teacher->name" />

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field name="email" type="email" label="Email" required :value="$teacher->email"
                         autocomplete="off" />

                <x-field name="phone" label="Phone" required :value="$teacher->phone"
                         hint="The school texts teachers, so this is how they are reached." />
            </div>

            {{-- A teacher who has left is deactivated rather than deleted: the classes
                 they taught and the marks they entered are still theirs. --}}
            <label class="flex items-center gap-2 text-sm text-ink-soft">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $teacher->is_active))
                       class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                Active — they can sign in
            </label>

            <div class="flex items-center justify-end gap-4 border-t border-line-soft pt-5">
                <a href="{{ route('admin.students-results.teachers.list') }}"
                   class="text-xs text-muted transition-colors hover:text-ink-soft">
                    Cancel
                </a>

                <button type="submit" class="btn-primary btn-sm">
                    <x-nav-icon name="check" class="h-3.5 w-3.5" />
                    Save
                </button>
            </div>
        </form>
    </div>
@endsection
