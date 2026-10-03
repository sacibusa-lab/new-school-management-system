@extends('layouts.admin')

@section('title', 'Students Hub')
@section('subtitle', 'Fees & Payments')

@section('content')

{{--
    Two questions, in the order the office asks them.

    How far has each class got with its bills? That is a question about thirty rows, so
    it is answered before anything is chosen — the office should not have to guess
    which class to look at before they can see which class is behind. Then: who, inside
    one class? That is a question about names, and worth asking about one class at a
    time.
--}}
<p class="max-w-3xl text-sm text-muted">
    The school read by what it owes: how far each class has got with its bills, and — once a
    class is chosen — the children behind the figures, with the account number each one pays
    into. Nothing here is billed or changed; the fee structures and the invoices do that.
</p>

@if ($session)
    <p class="mt-2 max-w-3xl text-xs text-muted">
        Figures are for {{ $session->name }}. A child with no bill at all is counted separately —
        that is a different thing from a child who owes the lot.
    </p>
@endif

{{-- ================= Whose money ================= --}}
<form method="GET" class="card-pad mt-6">
    <div class="grid items-end gap-4 sm:grid-cols-3">
        <x-field name="level" label="Year group" type="select"
                 placeholder-option="Every year group"
                 :value="$level?->id"
                 :options="$levels->pluck('name', 'id')->all()" />

        <x-field name="class" label="Class" type="select"
                 placeholder-option="Every class"
                 :value="$class?->id"
                 :options="$classes->mapWithKeys(fn ($item) => [$item->id => $item->name])->all()"
                 hint="Choose a class to see the children behind the figures." />

        <div class="flex items-center gap-2">
            <button type="submit" class="btn-primary flex-1">Show</button>

            @if ($class || $level)
                <a href="{{ route('admin.fees-payments.students-hub') }}" class="btn-secondary">Clear</a>
            @endif
        </div>
    </div>
</form>

{{-- ================= The figures ================= --}}
@php
    // A chosen class's own row where there is one, otherwise the totals for whatever is
    // being shown — so the cards always describe the table underneath them.
    $row = $class ? $perClass->get($class->id) : null;

    $roll = (int) ($row->students_count ?? $totals['students']);
    $unbilled = (int) ($row->unbilled_count ?? $totals['unbilled']);
    $billed = (float) ($row->billed ?? $totals['billed']);
    $collected = (float) ($row->collected ?? $totals['collected']);
    $outstanding = (float) ($row->outstanding ?? $totals['outstanding']);
    $rate = $billed > 0 ? (int) round($collected / $billed * 100) : 0;
@endphp

<div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <x-stat-card label="Children on the roll" icon="users" tone="slate"
                 :value="$roll"
                 :hint="$unbilled > 0 ? $unbilled.' with no bill at all' : 'Every child has a bill'" />

    <x-stat-card label="Billed" icon="receipt" tone="brand"
                 :value="$currency.number_format($billed, 2)"
                 hint="Raised against these children" />

    <x-stat-card label="Collected" icon="cash" tone="emerald"
                 :value="$currency.number_format($collected, 2)"
                 hint="{{ $rate }}% of what was billed" />

    <x-stat-card label="Still owing" icon="clock" tone="rose"
                 :value="$currency.number_format($outstanding, 2)"
                 hint="Across these bills" />
</div>

{{-- ================= Class by class ================= --}}
@unless ($class)
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-line bg-surface-2 px-5 py-4">
            <p class="font-display text-base font-semibold text-ink">Class by class</p>
            <p class="mt-0.5 text-xs text-muted">
                In the order the office works: the class owing most is first, so the list reads as a
                list of telephone calls. A class with nobody on the roll yet has nothing to show.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-line text-sm">
                <thead>
                    <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                        <th class="border-b border-r border-line p-3">Class</th>
                        <th class="w-28 border-b border-r border-line p-3">Year group</th>
                        <th class="w-24 border-b border-r border-line p-3 text-center">Children</th>
                        <th class="w-24 border-b border-r border-line p-3 text-center">No bill</th>
                        <th class="w-32 border-b border-r border-line p-3 text-right">Billed</th>
                        <th class="w-32 border-b border-r border-line p-3 text-right">Collected</th>
                        <th class="w-32 border-b border-r border-line p-3 text-right">Owing</th>
                        <th class="w-32 border-b border-line p-3">How far</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line text-ink-soft">
                    @forelse ($classes->sortByDesc(fn ($item) => (float) ($perClass->get($item->id)->outstanding ?? 0)) as $item)
                        @php
                            $figures = $perClass->get($item->id);
                            $share = ($figures && (float) $figures->billed > 0)
                                ? (int) round((float) $figures->collected / (float) $figures->billed * 100)
                                : 0;
                        @endphp

                        <tr class="transition-colors hover:bg-surface-3/60">
                            <td class="border-r border-line p-3 align-middle">
                                <a href="{{ route('admin.fees-payments.students-hub', ['class' => $item->id, 'level' => $level?->id]) }}"
                                   class="font-medium text-ink hover:underline">{{ $item->name }}</a>
                            </td>

                            <td class="border-r border-line p-3 align-middle">{{ $item->level?->name }}</td>

                            <td class="border-r border-line p-3 text-center align-middle">
                                {{ $figures->students_count ?? 0 }}
                            </td>

                            <td class="border-r border-line p-3 text-center align-middle">
                                @if (($figures->unbilled_count ?? 0) > 0)
                                    <span class="badge bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/20">
                                        {{ $figures->unbilled_count }}
                                    </span>
                                @else
                                    <span class="text-xs text-muted">—</span>
                                @endif
                            </td>

                            <td class="border-r border-line p-3 text-right align-middle font-mono text-xs">
                                {{ $currency }}{{ number_format((float) ($figures->billed ?? 0), 2) }}
                            </td>

                            <td class="border-r border-line p-3 text-right align-middle font-mono text-xs">
                                {{ $currency }}{{ number_format((float) ($figures->collected ?? 0), 2) }}
                            </td>

                            <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold text-ink">
                                {{ $currency }}{{ number_format((float) ($figures->outstanding ?? 0), 2) }}
                            </td>

                            <td class="p-3 align-middle">
                                @if ($share > 0)
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-16 overflow-hidden rounded-full bg-surface-3">
                                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, $share) }}%"></div>
                                        </div>
                                        <span class="text-xs text-muted">{{ $share }}%</span>
                                    </div>
                                @else
                                    <span class="text-xs text-muted">Nothing paid</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-5">
                                <x-empty-state
                                    icon="users"
                                    title="No classes to read"
                                    description="A class only appears here once it exists and has an active roll. Classes and their sections are set up under Students & Results → Academic." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endunless

{{-- ================= Child by child ================= --}}
@if ($class || $level)
    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-line bg-surface-2 px-5 py-4">
            <p class="font-display text-base font-semibold text-ink">
                {{ $class?->name ?? $level?->name }}
            </p>
            <p class="mt-0.5 text-xs text-muted">
                {{ $students->count() }} {{ Str::plural('child', $students->count()) }} on the roll.
                A balance of zero means settled; a child with no bill shows nothing against them.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-line text-sm">
                <thead>
                    <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                        <th class="w-40 border-b border-r border-line p-3">Admission no.</th>
                        <th class="border-b border-r border-line p-3">Name</th>
                        <th class="w-32 border-b border-r border-line p-3">Class</th>
                        <th class="w-32 border-b border-r border-line p-3 text-right">Billed</th>
                        <th class="w-32 border-b border-r border-line p-3 text-right">Paid</th>
                        <th class="w-32 border-b border-r border-line p-3 text-right">Balance</th>
                        <th class="w-48 border-b border-r border-line p-3">Pays into</th>
                        <th class="w-24 border-b border-line p-3 text-center">Bill</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line text-ink-soft">
                    @forelse ($students as $child)
                        @php
                            $childBilled = (float) ($child->billed_total ?? 0);
                            $childBalance = (float) ($child->balance_total ?? 0);
                        @endphp

                        <tr class="transition-colors hover:bg-surface-3/60">
                            <td class="border-r border-line p-3 align-middle font-mono text-xs">
                                {{ $child->student_number }}
                            </td>

                            <td class="border-r border-line p-3 align-middle">
                                <span class="font-medium text-ink">{{ $child->full_name }}</span>
                                @if ($childBilled <= 0)
                                    <span class="mt-0.5 block text-[11px] text-amber-700 dark:text-amber-300">No bill raised</span>
                                @endif
                            </td>

                            <td class="border-r border-line p-3 align-middle">{{ $child->schoolClass?->name }}</td>

                            <td class="border-r border-line p-3 text-right align-middle font-mono text-xs">
                                {{ $currency }}{{ number_format($childBilled, 2) }}
                            </td>

                            <td class="border-r border-line p-3 text-right align-middle font-mono text-xs">
                                {{ $currency }}{{ number_format((float) ($child->paid_total ?? 0), 2) }}
                            </td>

                            <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold {{ $childBalance > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-300' }} ">
                                {{ $currency }}{{ number_format($childBalance, 2) }}
                            </td>

                            <td class="border-r border-line p-3 align-middle">
                                @if ($child->virtualAccount)
                                    <span class="font-mono text-xs">{{ $child->virtualAccount->account_number }}</span>
                                    <span class="mt-0.5 block text-[11px] text-muted">{{ $child->virtualAccount->bank_name }}</span>
                                @else
                                    <span class="text-xs text-muted">No account number</span>
                                @endif
                            </td>

                            <td class="p-3 text-center align-middle">
                                <a href="{{ route('admin.invoices.index', ['q' => $child->student_number]) }}"
                                   class="btn-secondary btn-sm">Bills</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-5">
                                <x-empty-state
                                    icon="users"
                                    title="Nobody on this roll"
                                    description="Nothing to read here until a child is on the roll and billed for the session." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
