@extends('layouts.admin')

@section('title', 'Bulk Ops')
@section('subtitle', 'Payments')

@section('content')

{{--
    Opening account numbers for a class at a time.

    The list is shown before the button, with the number of students who will actually
    be touched: "42 accounts" and "42 students, 40 of them already have one" are very
    different propositions, and only the office can tell which they meant.
--}}
<p class="max-w-3xl text-sm text-muted">
    Give a whole class the account number their fees are paid into, in one go. Students who already
    have one are left alone — a parent who has saved the number keeps the number they saved.
</p>

@unless ($paystackReady)
    <div class="mt-4 lg:max-w-3xl">
        <x-alert tone="warn">
            Paystack is not set up, so no account number can be opened. Add the keys in Settings under API.
        </x-alert>
    </div>
@endunless

{{-- ================= Who ================= --}}
<form method="GET" class="card-pad mt-6">
    <div class="grid items-end gap-4 sm:grid-cols-3">
        <x-field name="level" label="Year group" type="select"
                 placeholder-option="Any year group"
                 :value="$level"
                 :options="$levels->pluck('name', 'id')->all()" />

        <x-field name="class" label="Class" type="select"
                 placeholder-option="Any class"
                 :value="$class"
                 :options="$classes->mapWithKeys(fn ($item) => [$item->id => $item->name])->all()"
                 hint="A class is the narrower choice, and the safer one for a big intake." />

        <button type="submit" class="btn-primary w-full">Choose</button>
    </div>
</form>

@if (! $chosen)
    <div class="mt-6">
        <x-empty-state
            icon="key"
            title="Choose a class or a year group"
            description="The students who would be given an account number are listed before anything is opened, so the office can see who is about to be touched." />
    </div>
@else
    {{-- ================= What would happen ================= --}}
    <div class="card-pad mt-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-ink">
                    {{ $without->count() }} of {{ $students->count() }} student(s) have no account number
                </h2>
                <p class="mt-1 text-sm text-muted">
                    @if ($without->isEmpty())
                        Everyone in that selection already has one. Nothing to do.
                    @elseif ($without->count() > $maxPerRun)
                        That is more than one run may open ({{ $maxPerRun }}). Choose a class rather than a
                        whole year group.
                    @else
                        Opening an account number is two calls to Paystack per student, so this takes a
                        moment. Each one is reported if it fails.
                    @endif
                </p>
            </div>

            @if ($without->isNotEmpty() && $without->count() <= $maxPerRun && $paystackReady)
                <form method="POST" action="{{ route('admin.payments.bulk-ops.generate') }}"
                      onsubmit="return confirm('Open {{ $without->count() }} account number(s) with Paystack?')">
                    @csrf
                    <input type="hidden" name="level" value="{{ $level }}">
                    <input type="hidden" name="class" value="{{ $class }}">

                    <button type="submit" class="btn-primary">
                        <x-nav-icon name="key" class="h-4 w-4" />
                        Open {{ $without->count() }} account number(s)
                    </button>
                </form>
            @endif
        </div>
    </div>

    <div class="card mt-6 overflow-hidden">
        <div class="border-b border-line bg-surface-2 px-5 py-4">
            <p class="font-display text-base font-semibold text-ink">The students this covers</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-line text-sm">
                <thead>
                    <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                        <th class="w-40 border-b border-r border-line p-3">Admission no.</th>
                        <th class="border-b border-r border-line p-3">Name</th>
                        <th class="w-32 border-b border-r border-line p-3">Class</th>
                        <th class="w-56 border-b border-line p-3">Account number</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line text-ink-soft">
                    @foreach ($students as $student)
                        <tr class="transition-colors hover:bg-surface-3/60">
                            <td class="border-r border-line p-3 align-middle font-mono text-xs">
                                {{ $student->student_number }}
                            </td>
                            <td class="border-r border-line p-3 align-middle font-medium text-ink">
                                {{ $student->full_name }}
                            </td>
                            <td class="border-r border-line p-3 align-middle">
                                {{ $student->schoolClass?->name ?? '—' }}
                            </td>
                            <td class="p-3 align-middle">
                                @if ($student->virtualAccount)
                                    <span class="font-mono text-xs">{{ $student->virtualAccount->account_number }}</span>
                                    <span class="mt-0.5 block text-[11px] text-muted">{{ $student->virtualAccount->bank_name }}</span>
                                @else
                                    <span class="badge bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/20">No account yet</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@endsection
