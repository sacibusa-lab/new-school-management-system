@extends('layouts.admin')

@section('title', $fee->title)
@section('subtitle', 'Fees & Payments')

@section('actions')
    <a href="{{ route('admin.fees.index') }}" class="btn-secondary btn-sm">Back to the fee list</a>
@endsection

@section('content')

{{--
    A fee's own page, not built yet.

    It exists so the pencil in the list leads somewhere and "where do I change this?" has an
    answer that is not a 404. What belongs here is the editing itself: the title, the
    amount, which terms it comes round in, and switching the fee off once the school has
    stopped charging it. The details are shown rather than hidden, so the page still tells
    the office something while it waits.
--}}
<div class="card">
    <div class="flex flex-wrap items-center gap-4 p-5">
        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-900 text-gold-300">
            <x-nav-icon name="tag" class="h-5 w-5" />
        </span>

        <div class="min-w-0 flex-1">
            <p class="font-display text-lg font-semibold text-ink">{{ $fee->title }}</p>

            @if ($fee->description)
                <p class="mt-0.5 text-sm text-muted">{{ $fee->description }}</p>
            @endif

            <p class="mt-1 text-xs text-muted">
                {{ $fee->cycleLabel() }}@if ($fee->isTermly()) · {{ $fee->termsSummary() }}@endif
                · {{ $fee->academicSession?->name ?? 'Every session' }}
                · {{ $school->currency }}{{ number_format((float) $fee->amount, 2) }}
            </p>
        </div>

        <span class="badge {{ $fee->is_active
            ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20'
            : 'bg-surface-3 text-muted ring-slate-500/20 dark:ring-slate-400/20' }}">
            {{ $fee->is_active ? 'Active' : 'Inactive' }}
        </span>
    </div>
</div>

<div class="mt-6">
    <x-module-placeholder
        icon="pencil"
        title="Editing this fee"
        note="Not built yet. Changing the title, the amount, and which terms it comes round in belongs here, along with switching it off when the school stops charging it." />
</div>

@endsection
