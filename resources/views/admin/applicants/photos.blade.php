@extends('layouts.admin')

@section('title', 'Applicant photographs')
@section('subtitle', 'Attach passport photographs after the names are already in')

@section('actions')
    <a href="{{ route('admin.applicants.import') }}" class="btn-secondary btn-sm">Bulk upload names</a>
    <a href="{{ route('admin.applicants.index') }}" class="btn-ghost btn-sm">Back to applicants</a>
@endsection

@section('content')

@php
    $rows = $staged ?? [];
    $matched = collect($rows)->whereNull('problem');
    $unmatched = collect($rows)->filter(fn ($row) => $row['problem'] !== null);
@endphp

{{-- ================= Upload ================= --}}
<div class="card-pad">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="max-w-3xl">
            <p class="eyebrow">Step 1</p>
            <h2 class="mt-1 font-display text-lg font-semibold text-ink">Choose the photographs</h2>
            <p class="mt-1 text-sm text-muted">
                Name each file after the candidate's registration number, then select them all at once.
                <span class="font-mono text-ink-soft">SAC-00001.jpg</span>,
                <span class="font-mono text-ink-soft">SAC-2.png</span> and
                <span class="font-mono text-ink-soft">sac00003.webp</span> all work — the number is
                matched however it is written. Nothing is attached until you have seen the matches.
            </p>
        </div>

        <div class="flex gap-3">
            <div class="rounded-xl bg-surface-2 px-4 py-3 text-center ring-1 ring-line">
                <p class="font-display text-xl font-semibold text-ink">{{ number_format($withoutPhoto) }}</p>
                <p class="mt-0.5 text-[11px] uppercase tracking-wider text-muted">No photo yet</p>
            </div>
            <div class="rounded-xl bg-emerald-50 dark:bg-emerald-950/40 px-4 py-3 text-center ring-1 ring-emerald-600/10">
                <p class="font-display text-xl font-semibold text-emerald-900 dark:text-emerald-100">{{ number_format($withPhoto) }}</p>
                <p class="mt-0.5 text-[11px] uppercase tracking-wider text-emerald-700 dark:text-emerald-300">Have a photo</p>
            </div>
        </div>
    </div>

    <div class="mt-5 flex flex-wrap items-end gap-4">
        <form method="POST" action="{{ route('admin.applicants.photos.preview') }}"
              enctype="multipart/form-data" class="flex flex-1 flex-wrap items-end gap-4">
            @csrf

            <div class="min-w-64 flex-1">
                <label for="photos" class="label">Photographs</label>
                <input id="photos" name="photos[]" type="file" multiple required
                       accept=".{{ str_replace(',', ',.', $extensions) }}"
                       class="input file:mr-3 file:rounded-md file:border-0 file:bg-surface-3 file:px-3 file:py-1.5 file:text-sm">
                @error('photos')
                    <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
                @error('photos.*')
                    <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                @enderror
                <p class="mt-1 text-xs text-muted">
                    Up to {{ $maxFiles }} photographs at a time, {{ (int) ($maxKb / 1024) }} MB each.
                    JPG, PNG or WEBP.
                </p>
            </div>

            <button type="submit" class="btn-primary btn-sm">Read the photographs</button>
        </form>
    </div>
</div>

{{-- ================= Review ================= --}}
@if ($rows !== [])
    <form method="POST" action="{{ route('admin.applicants.photos.commit') }}" class="mt-6"
          x-data="{ all: true }">
        @csrf

        <div class="card-pad">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="eyebrow">Step 2</p>
                    <h2 class="mt-1 font-display text-lg font-semibold text-ink">
                        Check who each one belongs to
                    </h2>
                    <p class="mt-1 text-sm text-muted">
                        Untick anybody you are not sure about — a photograph on the wrong candidate is
                        worse than no photograph at all.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">
                        {{ $matched->count() }} matched
                    </span>
                    @if ($unmatched->isNotEmpty())
                        <span class="badge bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 ring-rose-600/20 dark:ring-rose-400/20">
                            {{ $unmatched->count() }} could not be placed
                        </span>
                    @endif
                </div>
            </div>
        </div>

        @if ($unmatched->isNotEmpty())
            <div class="card-pad mt-6">
                <h3 class="font-display text-base font-semibold text-ink">
                    Photographs that could not be placed
                </h3>
                <p class="mt-1 text-sm text-muted">
                    Rename these after the candidate's registration number and upload them again, or
                    add them one at a time from the applicant's own page.
                </p>

                <ul class="mt-4 divide-y divide-line-soft">
                    @foreach ($unmatched as $row)
                        <li class="flex items-center gap-4 py-3">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-50 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400">
                                <x-nav-icon name="upload" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-mono text-sm text-ink-soft">{{ $row['name'] }}</p>
                                <p class="text-xs text-rose-700 dark:text-rose-300">{{ $row['problem'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($matched->isNotEmpty())
            <div class="mt-6">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-sm font-medium text-ink-soft">
                        {{ $matched->count() }} photograph(s) to attach
                    </p>

                    <label class="flex items-center gap-2 text-xs text-ink-soft">
                        <input type="checkbox" x-model="all"
                               class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                        Select all
                    </label>
                </div>

                <div class="table-wrap mt-3 max-h-[36rem] overflow-y-auto">
                    <table class="table">
                        <thead class="sticky top-0">
                            <tr>
                                <th class="w-12"></th>
                                <th class="w-24">Photo</th>
                                <th>File</th>
                                <th>Candidate</th>
                                <th>Replaces</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($rows as $index => $row)
                                @continue($row['problem'] !== null)

                                <tr>
                                    <td>
                                        {{-- Ticked server-side too, so the form still works
                                             if the Select all helper never loads. --}}
                                        <input type="checkbox" name="rows[]" value="{{ $index }}"
                                               checked x-bind:checked="all"
                                               class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                                    </td>

                                    <td>
                                        {{-- Served from the parked batch by index, never by a
                                             path the browser supplies. --}}
                                        <img src="{{ route('admin.applicants.photos.staged', $index) }}"
                                             alt="" loading="lazy"
                                             class="h-16 w-14 rounded-lg object-cover ring-1 ring-line">
                                    </td>

                                    <td class="font-mono text-xs text-muted">{{ $row['name'] }}</td>

                                    <td>
                                        <p class="font-medium text-ink">{{ $row['applicant_name'] }}</p>
                                        <p class="font-mono text-xs text-muted">{{ $row['registration_number'] }}</p>
                                    </td>

                                    <td>
                                        @if ($row['had_photo'])
                                            <span class="badge bg-gold-50 dark:bg-gold-950/40 text-gold-700 dark:text-gold-300 ring-gold-600/20 dark:ring-gold-400/20">Yes</span>
                                        @else
                                            <span class="text-xs text-muted">Nothing</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="card-pad mt-6 flex flex-wrap items-center gap-4">
            <p class="max-w-2xl text-sm text-muted">
                Attaching a photograph replaces whatever that candidate had before. Nothing is sent by
                text message.
            </p>

            @if ($matched->isNotEmpty())
                <button type="submit" class="btn-primary ml-auto">Attach {{ $matched->count() }} photograph(s)</button>
            @endif
        </div>
    </form>
@endif

@endsection
