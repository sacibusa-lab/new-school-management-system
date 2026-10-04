@extends('layouts.admin')

@section('title', 'Students Hub')
@section('subtitle', 'Fees & Payments')

@section('content')

{{--
    The roll, read one child at a time.

    The register under Students & Results reads the same children from the other end —
    guardian, photograph, class teacher. This one is about the money: the account
    number each child's fees are paid into, and how far those fees have got.
--}}
<p class="max-w-3xl text-sm text-muted">
    Every child on the roll, with the account number their fees are paid into and how far
    the fees have got. Tick the names you want account numbers opened for; that is what
    the button above the table is for. Nothing here is billed or changed — the fee
    structures and the invoices do that.
</p>

@if ($session)
    <p class="mt-2 max-w-3xl text-xs text-muted">
        Figures are for {{ $session->name }}. A child with no bill at all is shown as such
        rather than as owing everything — those are two different telephone calls.
    </p>
@endif

{{-- ================= Whose money ================= --}}
{{-- Twelve columns shared out by what each field holds rather than split four ways. A
     section is one letter and a standing is one word; the search box takes a name or an
     admission number. Four equal quarters gave the section the room the search needed. --}}
<form method="GET" class="card-pad mt-6">
    {{-- `items-end` keeps the controls on a single line, and it holds only while no field
         carries a hint. A hint makes its cell taller, and a taller cell that is bottom
         aligned lifts its own label and control above the rest — which is what put "On
         the roll" 22px above the other three. That note belongs in the empty state
         below, which already explains the active-roll default. --}}
    <div class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-12">
        <x-field name="class" label="Year group" type="select"
                 class="lg:col-span-3"
                 placeholder-option="Every year group"
                 :value="$filters['class'] ?: null"
                 :options="$levels->pluck('name', 'id')->all()" />

        <x-field name="section" label="Section" type="select"
                 class="lg:col-span-2"
                 placeholder-option="Every section"
                 :value="$filters['section'] ?: null"
                 :options="$sections->pluck('name', 'id')->all()" />

        <x-field name="status" label="On the roll" type="select"
                 class="lg:col-span-3"
                 placeholder-option="Any standing"
                 :value="$filters['status']"
                 :options="$statuses" />

        <x-field name="q" label="Search" type="text"
                 class="lg:col-span-4"
                 :value="$filters['q']"
                 placeholder="Name or admission number" />
    </div>

    <div class="mt-4 flex items-center gap-2">
        <button type="submit" class="btn-primary">Filter</button>
        <a href="{{ route('admin.fees-payments.students-hub') }}" class="btn-secondary">Clear</a>
    </div>
</form>

{{-- ================= The roll ================= --}}
<div class="card mt-6 overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line bg-surface-2 px-5 py-4">
        <div>
            <p class="font-display text-base font-semibold text-ink">Student List</p>
            <p class="mt-0.5 text-xs text-muted">
                {{ $students->total() }} {{ Str::plural('child', $students->total()) }} match.
                Payments are shown for the session above.
            </p>
        </div>

        @unless ($paystackReady)
            <p class="text-xs text-amber-700 dark:text-amber-300">
                Paystack is not set up, so account numbers cannot be opened yet.
            </p>
        @endunless
    </div>

    {{-- One form for the whole page, so the ticked names can be sent together. Two of
         the guardrails live here rather than on the server (the count, and refusing to
         send an empty list), but neither is trusted: the controller re-reads the ids
         and re-checks the gateway before it opens anything. --}}
    <form method="POST" action="{{ route('admin.fees.virtual-accounts') }}"
          x-data="{ count: 0 }"
          @change="count = $el.querySelectorAll('.hub-check:checked').length"
          onsubmit="return this.querySelectorAll('.hub-check:checked').length > 0;">
        @csrf

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3">
            <p class="text-xs text-muted">
                Tick the names to open account numbers for, or use the eye beside one name
                to open their record. A child who already has a number keeps the one their
                parent saved.
            </p>

            <button type="submit" class="btn-primary btn-sm" :disabled="count === 0">
                <x-nav-icon name="cash" class="h-3.5 w-3.5" />
                Open account numbers<span x-show="count > 0" x-cloak> (<span x-text="count"></span>)</span>
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-line text-sm">
                <thead>
                    <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                        <th class="w-10 border-b border-r border-line p-3 text-center">
                            <input type="checkbox"
                                   class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300"
                                   aria-label="Tick every student in the list"
                                   title="Tick every student in the list"
                                   @change="$root.querySelectorAll('.hub-check').forEach(box => box.checked = $el.checked);
                                            count = $root.querySelectorAll('.hub-check:checked').length">
                        </th>
                        <th class="w-14 border-b border-r border-line p-3 text-center">S/N</th>
                        <th class="border-b border-r border-line p-3">Payer</th>
                        <th class="w-40 border-b border-r border-line p-3">Admission No.</th>
                        <th class="w-32 border-b border-r border-line p-3">Class</th>
                        <th class="w-56 border-b border-r border-line p-3">Pays into</th>
                        <th class="w-36 border-b border-r border-line p-3">Phone</th>
                        <th class="w-32 border-b border-r border-line p-3">Fees</th>
                        <th class="w-32 border-b border-r border-line p-3 text-right">Owing</th>
                        <th class="w-28 border-b border-line p-3 text-center">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-line text-ink-soft">
                    @forelse ($students as $child)
                        @php
                            // Summed in the query, so a page of twenty-five costs two
                            // reads rather than fifty.
                            $billed = (float) ($child->billed_total ?? 0);
                            $paid = (float) ($child->paid_total ?? 0);
                            $owing = max(0, round($billed - $paid, 2));

                            // Three states, not two. "Not paid" and "has never been
                            // billed" are different telephone calls, and a red pill on a
                            // child nobody has billed would be the page's mistake.
                            if ($billed <= 0) {
                                $standing = ['No bill', 'bg-surface-3 text-ink-soft ring-slate-500/20 dark:ring-slate-400/20'];
                            } elseif ($owing <= 0) {
                                $standing = ['Settled', 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20'];
                            } elseif ($paid > 0) {
                                $standing = ['Part paid', 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/20'];
                            } else {
                                $standing = ['Not paid', 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 ring-rose-600/20 dark:ring-rose-400/20'];
                            }

                            $account = $child->virtualAccount;
                        @endphp

                        <tr class="transition-colors hover:bg-surface-3/60">
                            <td class="border-r border-line p-3 text-center align-middle">
                                <input type="checkbox"
                                       name="students[]"
                                       value="{{ $child->id }}"
                                       class="hub-check h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300"
                                       aria-label="Open an account number for {{ $child->full_name }}">
                            </td>

                            <td class="border-r border-line p-3 text-center align-middle text-xs text-muted">
                                {{ $students->firstItem() + $loop->index }}
                            </td>

                            {{-- The child, with the guardian beside them: the payer and
                                 the name on the account are rarely the same person.
                                 The photograph matters more here than on most screens:
                                 this page is read across a counter, and a face is what
                                 tells the office they have the right child. The initials
                                 stand in only for a child nobody has photographed yet. --}}
                            <td class="border-r border-line p-3 align-middle">
                                <div class="flex items-center gap-3">
                                    @if ($child->photo_path)
                                        <img src="{{ asset('storage/'.$child->photo_path) }}"
                                             alt="Photograph of {{ $child->full_name }}"
                                             class="h-9 w-9 shrink-0 rounded-full object-cover ring-1 ring-line">
                                    @else
                                        <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-900 text-[11px] font-semibold text-gold-300"
                                              aria-hidden="true">{{ $child->initials }}</span>
                                    @endif

                                    <span class="min-w-0">
                                        <a href="{{ route('admin.students.show', $child) }}"
                                           class="block truncate font-medium text-ink hover:underline">{{ $child->full_name }}</a>

                                        @if ($child->guardian_name)
                                            <span class="mt-0.5 block truncate text-xs text-muted">{{ $child->guardian_name }}</span>
                                        @endif
                                    </span>
                                </div>
                            </td>

                            {{-- Held in `student_number`, not in `admission_number`:
                                 those two columns are named the wrong way round, and
                                 `admission_number` is the registration number they
                                 applied with. --}}
                            <td class="border-r border-line p-3 align-middle font-mono text-xs">
                                {{ $child->student_number ?? '—' }}
                            </td>

                            <td class="border-r border-line p-3 align-middle">
                                {{ $child->schoolClass?->name ?? $child->level?->name ?? 'No class yet' }}
                            </td>

                            <td class="border-r border-line p-3 align-middle">
                                @if ($account)
                                    <span class="block font-mono text-xs font-semibold text-ink">{{ $account->account_number }}</span>
                                    <span class="mt-0.5 block text-xs text-muted">{{ $account->bank_name }}</span>
                                @else
                                    <span class="text-xs text-amber-700 dark:text-amber-300">Not generated</span>
                                @endif
                            </td>

                            <td class="border-r border-line p-3 align-middle font-mono text-xs">
                                {{ $child->guardian_phone ?? '—' }}
                            </td>

                            <td class="border-r border-line p-3 align-middle">
                                <span class="badge {{ $standing[1] }}">{{ $standing[0] }}</span>
                            </td>

                            <td class="border-r border-line p-3 text-right align-middle">
                                @if ($billed <= 0)
                                    <span class="text-xs text-muted">Nothing billed</span>
                                @else
                                    <span class="block font-mono text-xs font-semibold {{ $owing > 0 ? 'text-ink' : 'text-muted' }}">
                                        {{ $currency }}{{ number_format($owing, 2) }}
                                    </span>
                                    <span class="mt-0.5 block text-[11px] text-muted">
                                        of {{ $currency }}{{ number_format($billed, 0) }}
                                    </span>
                                @endif
                            </td>

                            <td class="p-3 align-middle">
                                <div class="flex justify-center gap-2">
                                    <a href="{{ route('admin.students.show', $child) }}"
                                       class="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-surface text-ink-soft transition hover:bg-surface-3"
                                       title="Open {{ $child->full_name }}">
                                        <x-nav-icon name="eye" class="h-3.5 w-3.5" />
                                    </a>

                                    {{-- Straight to their bills, searched by the number
                                         the office knows them by. --}}
                                    <a href="{{ route('admin.invoices.index', ['q' => $child->student_number]) }}"
                                       class="flex h-8 w-8 items-center justify-center rounded-full border border-line bg-surface text-ink-soft transition hover:bg-surface-3"
                                       title="Bills for {{ $child->full_name }}">
                                        <x-nav-icon name="receipt" class="h-3.5 w-3.5" />
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-6">
                                <x-empty-state
                                    icon="users"
                                    title="Nobody matches that"
                                    description="No child on the roll answers to those filters. Widen the year group or the section, clear the search, or set the standing to any — a child who has graduated is off the active roll, which is where this page opens." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </form>
</div>

@if ($students->hasPages())
    <div class="mt-5">{{ $students->links() }}</div>
@endif

@endsection
