@extends('layouts.admin')

@section('title', 'Fees')
@section('subtitle', 'Fees & Payments')

@section('actions')
    {{-- The modal lives in the page body, where the table it belongs to is, and this is
         the other side of the page header. An event rather than a shared Alpine scope:
         the two sit in different parts of the layout and neither owns the other. --}}
    <button type="button" class="btn-primary btn-sm" @click="$dispatch('open-add-fee')">
        <x-nav-icon name="plus" class="h-4 w-4" />
        Add Fee
    </button>
@endsection

@section('content')

{{--
    The catalogue of what the school charges.

    Not a fee structure and not a bill. A fee is a thing that can be billed: what it is
    called, how often it comes round, and what it costs by default. The structures decide
    which year group is billed which of them, and the amount here is the default they
    start from.
--}}
<div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
    <p class="max-w-3xl text-sm text-muted">
        Everything the school charges, and how often it comes round. A term's bill is built
        from these — the amount here is the default, not the price.
    </p>

    <p class="text-xs text-muted">
        {{ number_format($fees->count()) }} {{ Str::plural('fee', $fees->count()) }} on the list.
    </p>
</div>

@unless ($sessions->isNotEmpty())
    <div class="mt-6">
        <x-alert tone="warning">
            No academic session exists yet, so a fee cannot be tied to one. Set a session up
            under Academic Calendar first — a fee added now will apply to every session.
        </x-alert>
    </div>
@endunless

<div class="card mt-6 overflow-hidden"
     x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }"
     @open-add-fee.window="open = true">

    <div class="panel-header">
        <div>
            <p class="panel-title">Fee list</p>
            <p class="mt-0.5 text-xs text-muted">A to Z</p>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse border border-line text-sm">
            <thead>
                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                    <th class="w-14 border-b border-r border-line p-3 text-center">S/N</th>
                    <th class="border-b border-r border-line p-3">Fee title / description</th>
                    <th class="w-32 border-b border-r border-line p-3">Cycle</th>
                    <th class="w-40 border-b border-r border-line p-3">Academic session</th>
                    <th class="w-36 border-b border-r border-line p-3 text-right">Amount</th>
                    <th class="w-28 border-b border-r border-line p-3 text-center">Status</th>
                    <th class="w-20 border-b border-line p-3 text-center">Action</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line text-ink-soft">
                @forelse ($fees as $fee)
                    <tr class="transition-colors hover:bg-surface-3/60">
                        <td class="border-r border-line p-3 text-center align-middle text-xs text-muted">
                            {{ $loop->iteration }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            <span class="block font-medium text-ink">{{ $fee->title }}</span>

                            @if ($fee->description)
                                <span class="mt-0.5 block text-xs text-muted">{{ $fee->description }}</span>
                            @endif
                        </td>

                        {{-- The active terms sit inside the cycle rather than in a column of
                             their own: they are a detail of a termly fee and mean nothing
                             for the other two cycles. --}}
                        <td class="border-r border-line p-3 align-middle">
                            <span class="block">{{ $fee->cycleLabel() }}</span>

                            @if ($fee->isTermly())
                                <span class="mt-0.5 block text-xs text-muted">{{ $fee->termsSummary() }}</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            @if ($fee->academicSession)
                                {{ $fee->academicSession->name }}
                            @else
                                {{-- Not a mistake: a levy that applies to every session is
                                     entered once rather than copied year after year. --}}
                                <span class="text-xs text-muted">Every session</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold text-ink">
                            {{ $school->currency }}{{ number_format((float) $fee->amount, 2) }}
                        </td>

                        <td class="border-r border-line p-3 text-center align-middle">
                            <span class="badge {{ $fee->is_active
                                ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20'
                                : 'bg-surface-3 text-muted ring-slate-500/20 dark:ring-slate-400/20' }}">
                                {{ $fee->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>

                        <td class="p-3 text-center align-middle">
                            <a href="{{ route('admin.fees.edit', $fee) }}"
                               class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-line bg-surface text-ink-soft transition hover:bg-surface-3"
                               title="Edit {{ $fee->title }}">
                                <x-nav-icon name="pencil" class="h-3.5 w-3.5" />
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="p-6">
                            <x-empty-state
                                icon="tag"
                                title="No fees on the list yet"
                                description="Add the first one — tuition, a levy, a one-off charge — and it becomes something a term's bill can be built from. Nothing is billed from this page; it is the catalogue." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ================= Add new fee ================= --}}
    {{-- Opened by the button in the page header. A failed validation reopens it, because
         the error messages are inside it and a closed modal would swallow them silently. --}}
    <div x-show="open" x-cloak
         class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:p-8"
         @keydown.escape.window="open = false">

        <div class="card mx-auto w-full max-w-2xl" @click.outside="open = false">
            <div class="panel-header">
                <div>
                    <p class="panel-title">Add new fee</p>
                    <p class="mt-0.5 text-xs text-muted">It goes on the list straight away.</p>
                </div>

                <button type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-line text-ink-soft transition hover:bg-surface-3"
                        title="Close"
                        @click="open = false">
                    <x-nav-icon name="x-mark" class="h-4 w-4" />
                </button>
            </div>

            <form method="POST" action="{{ route('admin.fees.store') }}">
                @csrf

                <div class="space-y-5 p-5 sm:p-6">
                    <x-field name="title" label="Fee title" required maxlength="150"
                             placeholder="e.g. Tuition Fee"
                             hint="What the office calls it, and what appears on the bill." />

                    <x-field name="description" label="Description" maxlength="500"
                             placeholder="What this fee covers, in a line"
                             hint="Optional. Shown under the title on the list." />

                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-field name="cycle" label="Cycle" type="select" required
                                 :options="$cycles"
                                 :value="'termly'"
                                 :placeholder-option="false"
                                 hint="How often it comes round." />

                        <x-field name="amount" label="Default amount" type="number" required
                                 step="0.01" min="0" placeholder="0.00"
                                 :hint="'In ' . $school->code . '. The default a bill starts from.'" />
                    </div>

                    <x-field name="academic_session_id" label="Academic session" type="select"
                             placeholder-option="Every session"
                             :options="$sessions->pluck('name', 'id')->all()"
                             hint="Left as Every session, one entry covers every year's intake — the usual answer for a levy." />

                    <div>
                        <p class="label">Term activation</p>

                        <div class="mt-1.5 flex flex-wrap gap-x-6 gap-y-2">
                            @foreach ($terms as $term)
                                <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                                    <input type="checkbox" name="{{ $term['active'] }}" value="1"
                                           @checked(old($term['active'], true))
                                           class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300">
                                    {{ $term['label'] }}
                                </label>
                            @endforeach
                        </div>

                        <p class="hint">Which terms a termly fee comes round in. The other two cycles ignore it.</p>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-line px-5 py-4">
                    <button type="button" class="btn-secondary btn-sm" @click="open = false">Cancel</button>
                    <button type="submit" class="btn-primary btn-sm">Add fee</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection
