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
            <h2 class="mt-1 font-display text-lg font-semibold text-slate-900">Choose the photographs</h2>
            <p class="mt-1 text-sm text-slate-500">
                Name each file after the candidate's registration number, then select them all at once.
                <span class="font-mono text-slate-700">SAC-00001.jpg</span>,
                <span class="font-mono text-slate-700">SAC-2.png</span> and
                <span class="font-mono text-slate-700">sac00003.webp</span> all work — the number is
                matched however it is written. Nothing is attached until you have seen the matches.
            </p>
        </div>

        <div class="flex gap-3">
            <div class="rounded-xl bg-slate-50 px-4 py-3 text-center ring-1 ring-slate-200">
                <p class="font-display text-xl font-semibold text-slate-900">{{ number_format($withoutPhoto) }}</p>
                <p class="mt-0.5 text-[11px] uppercase tracking-wider text-slate-500">No photo yet</p>
            </div>
            <div class="rounded-xl bg-emerald-50 px-4 py-3 text-center ring-1 ring-emerald-600/10">
                <p class="font-display text-xl font-semibold text-emerald-900">{{ number_format($withPhoto) }}</p>
                <p class="mt-0.5 text-[11px] uppercase tracking-wider text-emerald-700">Have a photo</p>
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
                       class="input file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm">
                @error('photos')
                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                @enderror
                @error('photos.*')
                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                @enderror
                <p class="mt-1 text-xs text-slate-500">
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
                    <h2 class="mt-1 font-display text-lg font-semibold text-slate-900">
                        Check who each one belongs to
                    </h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Untick anybody you are not sure about — a photograph on the wrong candidate is
                        worse than no photograph at all.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <span class="badge bg-emerald-50 text-emerald-700 ring-emerald-600/20">
                        {{ $matched->count() }} matched
                    </span>
                    @if ($unmatched->isNotEmpty())
                        <span class="badge bg-rose-50 text-rose-700 ring-rose-600/20">
                            {{ $unmatched->count() }} could not be placed
                        </span>
                    @endif
                </div>
            </div>
        </div>

        @if ($unmatched->isNotEmpty())
            <div class="card-pad mt-6">
                <h3 class="font-display text-base font-semibold text-slate-900">
                    Photographs that could not be placed
                </h3>
                <p class="mt-1 text-sm text-slate-500">
                    Rename these after the candidate's registration number and upload them again, or
                    add them one at a time from the applicant's own page.
                </p>

                <ul class="mt-4 divide-y divide-slate-100">
                    @foreach ($unmatched as $row)
                        <li class="flex items-center gap-4 py-3">
                            <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600">
                                <x-nav-icon name="upload" class="h-4 w-4" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-mono text-sm text-slate-800">{{ $row['name'] }}</p>
                                <p class="text-xs text-rose-700">{{ $row['problem'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($matched->isNotEmpty())
            <div class="mt-6">
                <div class="flex items-center justify-between gap-4">
                    <p class="text-sm font-medium text-slate-700">
                        {{ $matched->count() }} photograph(s) to attach
                    </p>

                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" x-model="all"
                               class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
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
                                               class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                                    </td>

                                    <td>
                                        {{-- Served from the parked batch by index, never by a
                                             path the browser supplies. --}}
                                        <img src="{{ route('admin.applicants.photos.staged', $index) }}"
                                             alt="" loading="lazy"
                                             class="h-16 w-14 rounded-lg object-cover ring-1 ring-slate-200">
                                    </td>

                                    <td class="font-mono text-xs text-slate-500">{{ $row['name'] }}</td>

                                    <td>
                                        <p class="font-medium text-slate-900">{{ $row['applicant_name'] }}</p>
                                        <p class="font-mono text-xs text-slate-500">{{ $row['registration_number'] }}</p>
                                    </td>

                                    <td>
                                        @if ($row['had_photo'])
                                            <span class="badge bg-gold-50 text-gold-700 ring-gold-600/20">Yes</span>
                                        @else
                                            <span class="text-xs text-slate-400">Nothing</span>
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
            <p class="max-w-2xl text-sm text-slate-500">
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
