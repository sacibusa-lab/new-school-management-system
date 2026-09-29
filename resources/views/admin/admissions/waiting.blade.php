@extends('layouts.admin')

@section('title', 'Waiting list')
@section('subtitle', $exam->title . ' · ' . ($exam->level?->name ?? 'All classes'))

@section('actions')
    <a href="{{ route('admin.admissions.merit', $exam) }}" class="btn-secondary btn-sm">Merit list</a>
    <a href="{{ route('admin.admissions.index', ['exam' => $exam->id]) }}" class="btn-ghost btn-sm">
        &larr; Cutoff &amp; decisions
    </a>
@endsection

@section('content')
@php
    $fmt = fn ($value) => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
@endphp

<div class="grid gap-4 sm:grid-cols-3">
    <x-stat-card label="Places" :value="$slots === null ? 'None set' : number_format($slots)"
                 hint="Available slots for this class" icon="users" tone="brand" />
    <x-stat-card label="Admitted" :value="number_format($admitted)" hint="Places taken" icon="check" tone="emerald" />
    <x-stat-card label="Free now" :value="$free === null ? 'Unlimited' : number_format($free)"
                 hint="Places a waiting candidate could take" icon="clock" tone="gold" />
</div>

@if ($slots === null)
    <div class="mt-6">
        <x-alert tone="info" title="No limit was set for this class">
            Every candidate who passed the cutoff was admitted, so nobody is waiting.
            To hold places back, set an available-slots figure under
            <a href="{{ route('admin.admissions.index', ['exam' => $exam->id]) }}" class="underline decoration-brand-300 underline-offset-2">Cutoff &amp; decisions</a>.
        </x-alert>
    </div>
@endif

<div class="mt-6 card">
    <div class="panel-header">
        <div>
            <p class="panel-title">Waiting list</p>
            <p class="mt-0.5 text-xs text-muted">
                Candidates who passed the cutoff after the places ran out. Closest to the line comes first.
            </p>
        </div>

        <span class="badge-neutral">{{ $decisions->count() }} waiting</span>
    </div>

    @if ($decisions->isEmpty())
        <div class="p-5 sm:p-6">
            <x-empty-state title="Nobody is waiting"
                           description="Everyone above the cutoff has a place. This list fills up when an examination has more passers than places."
                           icon="check" />
        </div>
    @else
        <form method="POST" action="{{ route('admin.admissions.waiting.promote', $exam) }}">
            @csrf

            <div class="table-wrap rounded-none border-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="w-10">
                                {{-- Plain JS rather than Alpine: this form is the whole
                                     point of the page and must work even if the scripts
                                     never load. --}}
                                <input type="checkbox"
                                       onclick="const on = this.checked; this.closest('form').querySelectorAll('input[name^=decisions]').forEach(function (cb) { cb.checked = on; });"
                                       class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500"
                                       aria-label="Select every waiting candidate">
                            </th>
                            <th>Candidate</th>
                            <th>Class</th>
                            <th class="text-right">Average</th>
                            <th class="text-right">Gap to cutoff</th>
                            <th>Why they are waiting</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($decisions as $index => $decision)
                            <tr>
                                <td>
                                    <input type="checkbox" name="decisions[]" value="{{ $decision->id }}"
                                           @checked($free !== null && $index < $free)
                                           class="h-4 w-4 rounded border-line text-brand-600 focus:ring-brand-500">
                                </td>

                                <td>
                                    <p class="font-medium text-ink">{{ $decision->applicant?->full_name ?? '—' }}</p>
                                    <p class="font-mono text-xs text-muted">
                                        {{ $decision->applicant?->registration_number ?? '—' }}
                                    </p>
                                </td>

                                <td class="text-sm">{{ $decision->applicant?->levelAppliedFor?->name ?? '—' }}</td>

                                <td class="text-right font-semibold">{{ $fmt($decision->average_score) }}%</td>

                                <td class="text-right text-sm text-emerald-700 dark:text-emerald-300">
                                    +{{ $fmt($decision->margin()) }}
                                </td>

                                <td class="max-w-xs text-xs text-muted">{{ $decision->remarks ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center gap-3 border-t border-line p-5 sm:p-6">
                @can('admissions.decide')
                    <button type="submit" class="btn-primary btn-sm"
                            @disabled($free !== null && $free === 0)>
                        Give the free place(s) to those ticked
                    </button>
                @endcan

                <p class="text-xs text-muted">
                    @if ($free === null)
                        No limit is set, so any number can be promoted.
                    @elseif ($free === 0)
                        Every place is filled. Promote somebody and the count goes over the limit.
                    @else
                        {{ $free }} place(s) free — the first {{ min($free, $decisions->count()) }} are ticked for you.
                    @endif
                    Promoting sends no message and raises no invoice: transfer them into the
                    results and fees portals from the cutoff desk when you are ready.
                </p>
            </div>
        </form>
    @endif
</div>
@endsection
