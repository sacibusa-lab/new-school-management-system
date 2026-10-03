@extends('layouts.admin')

@section('title', $structure->name)
@section('subtitle', 'Fees & Payments')

@section('actions')
    <a href="{{ route('admin.fees.structures.index') }}" class="btn-secondary btn-sm">
        <x-nav-icon name="list" class="h-3.5 w-3.5" />
        All structures
    </a>
@endsection

@section('content')

{{--
    One bill, and the two things that can be done with it: price it, and hand it out.

    The lines come first because a structure with no lines bills nothing, and billing
    a class against a half-written structure is the expensive mistake this page is
    arranged to prevent — the amount is on screen above the button that spends it.
--}}
@php
    $total = (float) $structure->items->sum('amount');
    $currency = \App\Models\Setting::get('currency_symbol', '₦');
    $compulsory = (float) $structure->items->where('is_compulsory', true)->sum('amount');
@endphp

{{-- ================= What this structure is ================= --}}
<div class="card-pad">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="font-display text-lg font-semibold text-ink">{{ $structure->name }}</h2>
            <p class="mt-1 text-sm text-muted">
                {{ $structure->academicSession?->name }}
                &middot; {{ $structure->term?->name ?? 'whole session' }}
                &middot; {{ $structure->level?->name ?? 'every year group' }}
                &middot; due {{ $structure->due_days }} days after an invoice is raised
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if (! $structure->is_active)
                <span class="badge bg-surface-3 text-muted ring-slate-500/20 dark:ring-slate-400/20">Closed</span>
            @else
                <span class="badge-neutral">In use</span>
            @endif

            @if ($structure->is_default_for_new_students)
                <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">Default for new students</span>
            @endif
        </div>
    </div>

    <dl class="mt-6 grid gap-5 sm:grid-cols-3">
        <div>
            <dt class="eyebrow">Total for this bill</dt>
            <dd class="mt-1 font-display text-2xl font-semibold text-ink">{{ $currency }}{{ number_format($total, 2) }}</dd>
        </div>

        <div>
            <dt class="eyebrow">Compulsory part</dt>
            <dd class="mt-1 font-display text-2xl font-semibold text-ink">{{ $currency }}{{ number_format($compulsory, 2) }}</dd>
        </div>

        <div>
            <dt class="eyebrow">Students this covers</dt>
            <dd class="mt-1 font-display text-2xl font-semibold text-ink">{{ $studentCount }}</dd>
        </div>
    </dl>
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-12">

    {{-- ================= The lines ================= --}}
    <div class="space-y-6 lg:col-span-8">
        <div class="card overflow-hidden">
            <div class="border-b border-line bg-surface-2 px-5 py-4">
                <p class="font-display text-base font-semibold text-ink">Fee lines</p>
                <p class="mt-0.5 text-xs text-muted">
                    One line per category. Saving a category that is already here replaces its amount
                    rather than adding a second line for it.
                </p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                            <th class="border-b border-r border-line p-3">Category</th>
                            <th class="border-b border-r border-line p-3">Description</th>
                            <th class="w-40 border-b border-r border-line p-3 text-right">Amount</th>
                            <th class="w-28 border-b border-r border-line p-3 text-center">Required</th>
                            <th class="w-20 border-b border-line p-3 text-center"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-line text-ink-soft">
                        @forelse ($structure->items as $item)
                            <tr class="transition-colors hover:bg-surface-3/60">
                                <td class="border-r border-line p-3 align-middle font-medium text-ink">
                                    {{ $item->category?->name ?? 'Removed category' }}
                                </td>

                                <td class="border-r border-line p-3 align-middle text-muted">
                                    {{ $item->description ?: '—' }}
                                </td>

                                <td class="border-r border-line p-3 text-right align-middle font-mono text-xs font-semibold text-ink">
                                    {{ $currency }}{{ number_format((float) $item->amount, 2) }}
                                </td>

                                <td class="border-r border-line p-3 text-center align-middle">
                                    @if ($item->is_compulsory)
                                        <span class="badge-neutral">Required</span>
                                    @else
                                        <span class="text-xs text-muted">Optional</span>
                                    @endif
                                </td>

                                <td class="p-3 text-center align-middle">
                                    <form method="POST"
                                          action="{{ route('admin.fees.structures.items.destroy', [$structure, $item]) }}"
                                          onsubmit="return confirm('Take this line off the bill?')">
                                        @csrf
                                        @method('DELETE')

                                        <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400" title="Remove this line">
                                            <x-nav-icon name="trash" class="h-3.5 w-3.5" />
                                            <span class="sr-only">Remove</span>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="p-5">
                                    <x-empty-state
                                        icon="tag"
                                        title="This structure prices nothing yet"
                                        description="Add a line for each kind of money the school collects from this year group. Until there is at least one line, nobody should be billed against it." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>

                    @if ($structure->items->isNotEmpty())
                        <tfoot>
                            <tr class="bg-surface-2 font-semibold text-ink">
                                <td class="border-t border-r border-line p-3" colspan="2">Total</td>
                                <td class="border-t border-r border-line p-3 text-right font-mono text-xs">
                                    {{ $currency }}{{ number_format($total, 2) }}
                                </td>
                                <td class="border-t border-r border-line p-3"></td>
                                <td class="border-t border-line p-3"></td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ================= Add or replace a line ================= --}}
        <div class="card-pad">
            <h2 class="text-base font-semibold text-ink">Add a fee line</h2>
            <p class="mt-1 text-sm text-muted">
                Picking a category that is already billed here replaces its amount.
            </p>

            <form method="POST" action="{{ route('admin.fees.structures.items.store', $structure) }}" class="mt-5">
                @csrf

                <div class="grid gap-5 sm:grid-cols-2">
                    <x-field name="fee_category_id" label="Category" type="select" required
                             :options="$categories->pluck('name', 'id')->all()" />

                    <x-field name="amount" label="Amount" type="number" required step="0.01" min="0"
                             hint="In {{ \App\Models\Setting::get('currency', 'NGN') }}, to two decimal places." />

                    <div class="sm:col-span-2">
                        <x-field name="description" label="Description"
                                 hint="Optional — replaces the category name on the invoice, e.g. “Tuition — First Term”." />
                    </div>

                    <label class="flex items-start gap-3 rounded-xl border border-line p-4 sm:col-span-2">
                        <input type="hidden" name="is_compulsory" value="">
                        <input type="checkbox" name="is_compulsory" value="1" checked
                               class="mt-0.5 h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                        <span>
                            <span class="block text-sm font-medium text-ink-soft">Every student on this bill pays it</span>
                            <span class="mt-0.5 block text-xs text-muted">
                                Leave it ticked for tuition and levies. Untick for a charge only some
                                students take, such as boarding or transport.
                            </span>
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn-primary mt-5">Save line</button>
            </form>
        </div>
    </div>

    {{-- ================= Hand it out ================= --}}
    <div class="space-y-6 lg:col-span-4">
        <div class="card-pad">
            <h2 class="text-base font-semibold text-ink">Bill the students</h2>

            <p class="mt-2 text-sm text-ink-soft">
                @if ($studentCount === 0)
                    No active student matches this structure, so there is nobody to bill yet.
                @elseif ($structure->items->isEmpty())
                    This structure has no lines yet. Add the amounts first — billing now would raise
                    {{ $studentCount }} invoice(s) for nothing.
                @else
                    Raises a {{ $currency }}{{ number_format($total, 2) }} invoice for each of the
                    {{ $studentCount }} active student(s) this structure covers, due
                    {{ $structure->due_days }} days from today.
                @endif
            </p>

            @if ($studentCount > 0 && $structure->items->isNotEmpty())
                <p class="mt-3 text-xs text-muted">
                    Anybody already billed for this term is left alone rather than billed twice.
                </p>

                @can('fees.invoice')
                    <form method="POST" action="{{ route('admin.fees.structures.bill', $structure) }}"
                          class="mt-4"
                          onsubmit="return confirm('Raise invoices for {{ $studentCount }} student(s)?')">
                        @csrf

                        <button type="submit" class="btn-primary w-full">
                            <x-nav-icon name="receipt" class="h-4 w-4" />
                            Bill {{ $studentCount }} student(s)
                        </button>
                    </form>
                @endcan
            @endif
        </div>

        {{-- ================= The structure's own settings ================= --}}
        <div class="card-pad">
            <h2 class="text-base font-semibold text-ink">This structure</h2>

            <form method="POST" action="{{ route('admin.fees.structures.update', $structure) }}" class="mt-5">
                @csrf
                @method('PUT')

                <div class="space-y-5">
                    <x-field name="name" label="Name" required :value="$structure->name" />

                    <x-field name="due_days" label="Payment due within (days)" type="number" required
                             :value="$structure->due_days" />

                    <div>
                        <label for="structure_notes" class="label">Notes</label>
                        <textarea id="structure_notes" name="notes" rows="3"
                                  class="input">{{ old('notes', $structure->notes) }}</textarea>
                    </div>

                    <label class="flex items-center gap-2.5 text-sm text-ink-soft">
                        <input type="hidden" name="is_active" value="">
                        <input type="checkbox" name="is_active" value="1" @checked($structure->is_active)
                               class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                        Being billed from this structure
                    </label>

                    <label class="flex items-start gap-2.5 text-sm text-ink-soft">
                        <input type="hidden" name="is_default_for_new_students" value="">
                        <input type="checkbox" name="is_default_for_new_students" value="1"
                               @checked($structure->is_default_for_new_students)
                               class="mt-0.5 h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                        <span>
                            Bill newly admitted students against this
                            <span class="mt-0.5 block text-xs text-muted">
                                One structure per year group should carry this.
                            </span>
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn-secondary mt-5 w-full">Save</button>
            </form>
        </div>

        {{-- Closing a structure stops new invoices without touching the ones already
             raised against it — the bills a parent holds must not change underneath
             them because the office moved on to next term. --}}
        <div class="card-pad">
            <h2 class="text-base font-semibold text-ink">What closing it does</h2>
            <p class="mt-2 text-sm text-muted">
                Stopping a structure bills nobody new from it. Invoices already raised against it
                keep their amounts and stay payable, so a parent holding last term's bill is not
                surprised by it.
            </p>
        </div>
    </div>
</div>

@endsection
