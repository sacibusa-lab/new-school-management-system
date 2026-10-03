@extends('layouts.admin')

@section('title', 'Scholarships')
@section('subtitle', 'Fees')

@section('content')

{{--
    What a student has been let off, and by whom.

    Approving an award moves the money onto the bills themselves rather than being
    worked out when the page is read: a bill a parent is holding must not change
    underneath them, and the discount has to be visible on the invoice it came off.
--}}
<div class="grid gap-4 sm:grid-cols-3">
    <x-stat-card label="Approved and applied" icon="rosette" tone="emerald"
                 :value="$currency.number_format($totals['approved'], 2)"
                 hint="Already off the bills" />

    <x-stat-card label="Waiting for a decision" icon="clock" tone="gold"
                 :value="$currency.number_format($totals['pending'], 2)"
                 hint="Recorded, not yet applied" />

    <x-stat-card label="Awards" icon="list" tone="slate"
                 :value="$awards->total()"
                 hint="Matching the filters" />
</div>

{{-- ================= Record one ================= --}}
<div class="card-pad mt-6 lg:max-w-3xl">
    <h2 class="text-base font-semibold text-ink">Record an award</h2>
    <p class="mt-1 text-sm text-muted">
        Approving it takes the amount off the student's unpaid bills, oldest first.
    </p>

    <form method="POST" action="{{ route('admin.fees.scholarships.store') }}" class="mt-5">
        @csrf

        <div class="grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-field name="student_id" label="Student" type="select" required
                         :options="$students->mapWithKeys(fn ($student) => [$student->id => $student->student_number.' — '.$student->full_name])->all()" />
            </div>

            <x-field name="type" label="What kind" type="select" required :options="$types" />

            <x-field name="amount" label="Amount" type="number" required step="0.01" min="0.01"
                     hint="In {{ \App\Models\Setting::get('currency', 'NGN') }}." />

            <x-field name="academic_session_id" label="Session" type="select"
                     :value="$session?->id"
                     :options="$sessions->pluck('name', 'id')->all()" />

            <x-field name="term_id" label="Term" type="select"
                     placeholder-option="Every term of the session"
                     :options="$terms->pluck('name', 'id')->all()" />

            <div class="sm:col-span-2">
                <label for="scholarship_description" class="label">Why</label>
                <textarea id="scholarship_description" name="description" rows="2" class="input"
                          placeholder="e.g. Best entrance examination result, 2026"></textarea>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap items-center gap-3">
            <button type="submit" name="approve" value="1" class="btn-primary">Record and apply</button>
            <button type="submit" class="btn-secondary">Record it for approval</button>
        </div>
    </form>
</div>

{{-- ================= The awards ================= --}}
<form method="GET" class="card-pad mt-6">
    <div class="grid items-end gap-4 sm:grid-cols-3">
        <x-field name="q" label="Find" :value="request('q')"
                 placeholder="Name or admission number" />

        <x-field name="status" label="State" type="select"
                 placeholder-option="Any state"
                 :value="request('status')"
                 :options="['pending' => 'Waiting for a decision', 'approved' => 'Approved', 'rejected' => 'Rejected']" />

        <button type="submit" class="btn-primary w-full">Filter</button>
    </div>
</form>

<div class="card mt-6 overflow-hidden">
    <div class="border-b border-line bg-surface-2 px-5 py-4">
        <p class="font-display text-base font-semibold text-ink">Awards</p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse border border-line text-sm">
            <thead>
                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                    <th class="border-b border-r border-line p-3">Student</th>
                    <th class="w-32 border-b border-r border-line p-3">Kind</th>
                    <th class="w-32 border-b border-r border-line p-3 text-right">Amount</th>
                    <th class="w-44 border-b border-r border-line p-3">Session / term</th>
                    <th class="w-32 border-b border-r border-line p-3 text-center">State</th>
                    <th class="w-44 border-b border-line p-3 text-center">Action</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line text-ink-soft">
                @forelse ($awards as $award)
                    <tr class="transition-colors hover:bg-surface-3/60">
                        <td class="border-r border-line p-3 align-middle">
                            <span class="block font-medium text-ink">{{ $award->student?->full_name }}</span>
                            <span class="mt-0.5 block text-xs text-muted">
                                {{ $award->student?->student_number }}
                                @if ($award->student?->schoolClass)
                                    &middot; {{ $award->student->schoolClass->name }}
                                @endif
                            </span>
                            @if ($award->description)
                                <span class="mt-1 block text-xs text-muted">{{ $award->description }}</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 align-middle">{{ $award->typeLabel() }}</td>

                        <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold text-ink">
                            {{ $currency }}{{ number_format((float) $award->amount, 2) }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            {{ $award->academicSession?->name ?? '—' }}
                            <span class="mt-0.5 block text-xs text-muted">{{ $award->term?->name ?? 'every term' }}</span>
                        </td>

                        <td class="border-r border-line p-3 text-center align-middle">
                            @if ($award->isApproved())
                                <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">Applied</span>
                                @if ($award->approver)
                                    <span class="mt-1 block text-[11px] text-muted">by {{ $award->approver->name }}</span>
                                @endif
                            @elseif ($award->status === 'rejected')
                                <span class="badge bg-surface-3 text-muted ring-slate-500/20 dark:ring-slate-400/20">Rejected</span>
                            @else
                                <span class="badge bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 ring-amber-600/20 dark:ring-amber-400/20">Waiting</span>
                            @endif
                        </td>

                        <td class="p-3 text-center align-middle">
                            @if ($award->status === 'pending')
                                <div class="flex items-center justify-center gap-2">
                                    <form method="POST" action="{{ route('admin.fees.scholarships.approve', $award) }}"
                                          onsubmit="return confirm('Take {{ $currency }}{{ number_format((float) $award->amount, 2) }} off this student\'s bills?')">
                                        @csrf
                                        <button type="submit" class="btn-primary btn-sm">Approve</button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.fees.scholarships.reject', $award) }}">
                                        @csrf
                                        <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400">Reject</button>
                                    </form>
                                </div>
                            @elseif ($award->isApproved())
                                <form method="POST" action="{{ route('admin.fees.scholarships.reject', $award) }}"
                                      onsubmit="return confirm('Take this award back off the bill?')">
                                    @csrf
                                    <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400">
                                        Withdraw
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-5">
                            <x-empty-state
                                icon="rosette"
                                title="No awards match"
                                description="A scholarship or bursary recorded here is taken off the student's unpaid bills once it is approved, and the bill shows it as a discount." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($awards->hasPages())
        <div class="border-t border-line px-5 py-4">
            {{ $awards->links() }}
        </div>
    @endif
</div>

@endsection
