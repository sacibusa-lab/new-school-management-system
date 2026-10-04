@extends('layouts.admin')

@section('title', $fee->title)
@section('subtitle', 'Fees & Payments')

@section('actions')
    <a href="{{ route('admin.fees.index') }}" class="btn-secondary btn-sm">Back to the fee list</a>
@endsection

@section('content')

{{--
    A fee's own page, in five tabs.

    The tab lives in the url rather than in Alpine, so a reload keeps the office where they
    were and a validation failure comes back to the tab the form was on. Details is the fee
    itself and Settings is its standing; the three in the middle are the ways one fee
    becomes a set of bills, and are not built yet.
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

<section class="card mt-6 overflow-hidden">
    <div class="flex flex-wrap gap-x-6 border-b border-line px-5 pt-4 sm:px-6">
        @foreach ($tabs as $item)
            <a href="{{ route('admin.fees.edit', ['fee' => $fee, 'tab' => $item['key']]) }}"
               @if ($tab === $item['key']) aria-current="page" @endif
               @class([
                   'flex items-center gap-2 border-b-2 pb-3 text-sm font-medium transition-colors',
                   'border-gold-400 text-gold-700 dark:text-gold-300' => $tab === $item['key'],
                   'border-transparent text-muted hover:text-ink-soft' => $tab !== $item['key'],
               ])>
                <x-nav-icon :name="$item['icon']" class="h-4 w-4" />
                <span>{{ $item['label'] }}</span>
            </a>
        @endforeach
    </div>

    <div class="p-5 sm:p-6">

        {{-- ================= Details ================= --}}
        @if ($tab === 'details')
            <form method="POST" action="{{ route('admin.fees.update', $fee) }}" class="max-w-3xl space-y-5">
                @csrf
                @method('PUT')

                <x-field name="title" label="Fee title" required maxlength="150"
                         :value="$fee->title"
                         hint="What the office calls it, and what appears on the bill." />

                <x-field name="description" label="Description" maxlength="500"
                         :value="$fee->description"
                         hint="Optional. Shown under the title on the list." />

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field name="cycle" label="Cycle" type="select" required
                             :options="$cycles"
                             :value="$fee->cycle"
                             :placeholder-option="false"
                             hint="How often it comes round." />

                    <x-field name="amount" label="Default amount" type="number" required
                             step="0.01" min="0"
                             :value="$fee->amount"
                             :hint="'In ' . $school->code . '. The default a bill starts from.'" />
                </div>

                <x-field name="academic_session_id" label="Academic session" type="select"
                         placeholder-option="Every session"
                         :options="$sessions->pluck('name', 'id')->all()"
                         :value="$fee->academic_session_id"
                         hint="Left as Every session, one entry covers every year's intake." />

                <div>
                    <p class="label">Term activation</p>

                    <div class="mt-1.5 flex flex-wrap gap-x-6 gap-y-2">
                        @foreach ($terms as $column => $label)
                            <label class="inline-flex items-center gap-2 text-sm text-ink-soft">
                                <input type="checkbox" name="{{ $column }}" value="1"
                                       @checked(old($column, $fee->{$column}))
                                       class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    <p class="hint">Which terms a termly fee comes round in. The other two cycles ignore it.</p>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-line pt-5">
                    <button type="submit" class="btn-primary">Save changes</button>
                    <a href="{{ route('admin.fees.index') }}" class="btn-secondary">Back to the fee list</a>
                </div>
            </form>
        @endif

        {{-- ================= The three that are not built ================= --}}
        @php
            $unbuilt = [
                'splits' => [
                    'title' => 'Not built yet',
                    'note' => 'Dividing one fee between the school\'s own accounts — the bursary, a building fund, whoever the money is finally owed to. Nothing records a split yet, so there is nothing here to show.',
                ],
                'class-amounts' => [
                    'title' => 'Not built yet',
                    'note' => 'A year group charged something other than the default amount above, or let off a fee the rest of the school pays. Nothing records an amount per class yet.',
                ],
                'transactions' => [
                    'title' => 'Nothing to show yet',
                    'note' => 'What has been paid against this fee, term by term. It fills in once bills are raised from it, because money is recorded against a bill and not against the catalogue.',
                ],
            ];
        @endphp

        @if (isset($unbuilt[$tab]))
            <div class="mx-auto max-w-2xl rounded-xl bg-surface-2 p-8 text-center ring-1 ring-line">
                <p class="font-display text-base font-semibold text-ink">{{ $unbuilt[$tab]['title'] }}</p>
                <p class="mx-auto mt-2 max-w-xl text-sm text-muted">{{ $unbuilt[$tab]['note'] }}</p>
            </div>
        @endif

        {{-- ================= Settings ================= --}}
        @if ($tab === 'settings')
            <div class="max-w-3xl">
                <p class="text-sm font-semibold text-ink">
                    {{ $fee->is_active ? 'This fee is active' : 'This fee is switched off' }}
                </p>

                <p class="mt-1.5 max-w-xl text-sm text-muted">
                    @if ($fee->is_active)
                        It can be billed. Switching it off leaves every bill already raised from it
                        exactly as it is — it only stops new ones being built.
                    @else
                        It cannot be billed, but it has not been removed: every bill already raised
                        from it still stands, and switching it back on changes nothing except the
                        choice appearing again.
                    @endif
                </p>

                <form method="POST" action="{{ route('admin.fees.toggle', $fee) }}" class="mt-4">
                    @csrf

                    <button type="submit" class="{{ $fee->is_active ? 'btn-secondary' : 'btn-primary' }} btn-sm">
                        {{ $fee->is_active ? 'Switch this fee off' : 'Switch this fee back on' }}
                    </button>
                </form>

                <p class="mt-6 border-t border-line pt-5 text-xs text-muted">
                    Deleting a fee is not offered, here or anywhere else. A fee that has been
                    billed cannot be removed without deciding what happens to the bills raised
                    from it, and that is a decision nobody has been asked to make yet.
                </p>
            </div>
        @endif
    </div>
</section>

@endsection
