@extends('layouts.admin')

@section('title', 'Fee structures')
@section('subtitle', 'Fees')

@section('content')

{{--
    A fee structure is one bill: what a year group pays, in one term of one session.
    The lines and their amounts are set on the structure's own page, because a
    structure with no lines prices nothing and is not worth reading here.

    Level and term are both optional on purpose. Leaving the level empty makes it the
    bill for every year group that has none of its own; leaving the term empty makes
    it the bill for the whole session. Invoice generation prefers the more specific
    structure, which is what lets the office price one term differently without
    writing six copies of the same list.
--}}
<div class="max-w-3xl">
    <p class="text-sm text-muted">
        What each year group is billed, per session and term. Open one to set its lines and
        amounts, then bill the students it covers in one go.
    </p>
</div>

{{-- ================= Add one ================= --}}
<div class="card-pad mt-6 lg:max-w-3xl">
    <h2 class="text-base font-semibold text-ink">New fee structure</h2>

    <form method="POST" action="{{ route('admin.fees.structures.store') }}" class="mt-5">
        @csrf

        <div class="grid gap-5 sm:grid-cols-2">
            <x-field name="name" label="Name" required
                     class="sm:col-span-2"
                     hint="What the office will recognise it by, e.g. “JSS1 — First Term 2026/2027”."
                     placeholder="e.g. JSS1 — First Term 2026/2027" />

            <x-field name="academic_session_id" label="Session" type="select" required
                     :options="$sessions->pluck('name', 'id')->all()" />

            <x-field name="term_id" label="Term" type="select"
                     placeholder-option="Whole session"
                     :options="$terms->pluck('name', 'id')->all()" />

            <x-field name="level_id" label="Year group" type="select"
                     placeholder-option="Every year group"
                     hint="Leave empty to price every year group that has no bill of its own."
                     :options="$levels->pluck('name', 'id')->all()" />

            <x-field name="due_days" label="Payment due within (days)" type="number" required
                     :value="30"
                     hint="Counted from the day an invoice is raised." />

            <div class="sm:col-span-2">
                <x-field name="notes" label="Notes" type="text" hint="Optional. Printed on nothing; for the office." />
            </div>

            <label class="flex items-start gap-3 rounded-xl border border-line p-4 sm:col-span-2">
                <input type="hidden" name="is_default_for_new_students" value="">
                <input type="checkbox" name="is_default_for_new_students" value="1"
                       class="mt-0.5 h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                <span>
                    <span class="block text-sm font-medium text-ink-soft">Bill newly admitted students against this</span>
                    <span class="mt-0.5 block text-xs text-muted">
                        A student enrolled this term is invoiced from this structure the moment they are
                        admitted, without anybody raising it by hand. Set it on one structure per year group.
                    </span>
                </span>
            </label>
        </div>

        <button type="submit" class="btn-primary mt-5">Create structure</button>
    </form>
</div>

{{-- ================= The list ================= --}}
<div class="card mt-6 overflow-hidden">
    <div class="border-b border-line bg-surface-2 px-5 py-4">
        <p class="font-display text-base font-semibold text-ink">Structures</p>
        <p class="mt-0.5 text-xs text-muted">
            {{ $structures->count() }} {{ Str::plural('structure', $structures->count()) }}.
            Most sessions first, then by year group.
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse border border-line text-sm">
            <thead>
                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                    <th class="border-b border-r border-line p-3">Name</th>
                    <th class="w-32 border-b border-r border-line p-3">Session</th>
                    <th class="w-32 border-b border-r border-line p-3">Term</th>
                    <th class="w-32 border-b border-r border-line p-3">Year group</th>
                    <th class="w-24 border-b border-r border-line p-3 text-center">Lines</th>
                    <th class="w-32 border-b border-r border-line p-3 text-center">State</th>
                    <th class="w-28 border-b border-line p-3 text-center">Action</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line text-ink-soft">
                @forelse ($structures as $structure)
                    <tr class="transition-colors hover:bg-surface-3/60">
                        <td class="border-r border-line p-3 align-middle">
                            <a href="{{ route('admin.fees.structures.show', $structure) }}"
                               class="block font-medium text-ink hover:underline">{{ $structure->name }}</a>

                            @if ($structure->notes)
                                <span class="mt-0.5 block text-xs text-muted">{{ $structure->notes }}</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 align-middle">{{ $structure->academicSession?->name }}</td>

                        <td class="border-r border-line p-3 align-middle">
                            {{ $structure->term?->name ?? 'Whole session' }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            {{ $structure->level?->name ?? 'Every year group' }}
                        </td>

                        <td class="border-r border-line p-3 text-center align-middle">
                            {{ $structure->items_count }}
                        </td>

                        <td class="border-r border-line p-3 text-center align-middle">
                            @if (! $structure->is_active)
                                <span class="badge bg-surface-3 text-muted ring-slate-500/20 dark:ring-slate-400/20">Closed</span>
                            @elseif ($structure->is_default_for_new_students)
                                <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">Default for new</span>
                            @else
                                <span class="badge-neutral">In use</span>
                            @endif
                        </td>

                        <td class="p-3 text-center align-middle">
                            <a href="{{ route('admin.fees.structures.show', $structure) }}" class="btn-secondary btn-sm">
                                Open
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="p-5">
                            <x-empty-state
                                icon="list"
                                title="No fee structures yet"
                                description="A structure is one bill for one year group in one term. Create one, add its lines and amounts, and the students it covers can be billed in a single action." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
