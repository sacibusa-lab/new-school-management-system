@extends('layouts.admin')

@section('title', 'Fee categories')
@section('subtitle', 'Fees & Payments')

@section('content')

{{--
    What the school collects, as a list of named kinds of money: tuition, a levy, a
    boarding fee. A category carries no amount — the amount is a line on a fee
    structure, because JSS1 and SS3 pay different tuition out of the same category.

    So this page is the vocabulary, and the Fee structures page is the sentence. The
    count beside each one says how many structures bill it, which is what stops a
    category being quietly renamed out from under a published bill.
--}}
<div class="max-w-3xl">
    <p class="text-sm text-muted">
        The kinds of money the school collects. Amounts are set on the fee structures, not here —
        a category is the name of a charge, and it is billed at whatever each year group pays.
    </p>
</div>

{{-- ================= Add one ================= --}}
<div class="card-pad mt-6 lg:max-w-3xl">
    <h2 class="text-base font-semibold text-ink">Add a category</h2>

    <form method="POST" action="{{ route('admin.fees.categories.store') }}" class="mt-5">
        @csrf

        <div class="grid gap-5 sm:grid-cols-2">
            <x-field name="name" label="Name" required
                     placeholder="e.g. Tuition Fee" />

            <x-field name="code" label="Code" required
                     hint="Short and permanent — used to line up imports and reports. Letters and digits only."
                     placeholder="e.g. TUITION" />

            <x-field name="type" label="Kind" type="select" required
                     :options="$types" />

            <x-field name="description" label="Description"
                     hint="Optional. What this money is spent on, for anyone reading the bill." />
        </div>

        <button type="submit" class="btn-primary mt-5">Add category</button>
    </form>
</div>

{{-- ================= The list ================= --}}
<div class="card mt-6 overflow-hidden" x-data="{ open: null }">
    <div class="border-b border-line bg-surface-2 px-5 py-4">
        <p class="font-display text-base font-semibold text-ink">Categories</p>
        <p class="mt-0.5 text-xs text-muted">
            {{ $categories->count() }} {{ Str::plural('category', $categories->count()) }}.
            The middle column is how many fee structures bill each one.
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse border border-line text-sm">
            <thead>
                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                    <th class="border-b border-r border-line p-3">Name</th>
                    <th class="w-32 border-b border-r border-line p-3">Code</th>
                    <th class="w-32 border-b border-r border-line p-3">Kind</th>
                    <th class="w-28 border-b border-r border-line p-3 text-center">Billed by</th>
                    <th class="w-28 border-b border-r border-line p-3 text-center">State</th>
                    <th class="w-28 border-b border-line p-3 text-center">Action</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line text-ink-soft">
                @forelse ($categories as $category)
                    <tr class="transition-colors hover:bg-surface-3/60">
                        <td class="border-r border-line p-3 align-middle">
                            <span class="block font-medium text-ink">{{ $category->name }}</span>
                            @if ($category->description)
                                <span class="mt-0.5 block text-xs text-muted">{{ $category->description }}</span>
                            @endif
                        </td>

                        <td class="border-r border-line p-3 align-middle font-mono text-xs">
                            {{ $category->code }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">
                            {{ $types[$category->type] ?? ucfirst((string) $category->type) }}
                        </td>

                        <td class="border-r border-line p-3 text-center align-middle">
                            {{ $category->structure_items_count }}
                        </td>

                        <td class="border-r border-line p-3 text-center align-middle">
                            @if ($category->is_active)
                                <span class="badge-neutral">In use</span>
                            @else
                                <span class="badge bg-surface-3 text-muted ring-slate-500/20 dark:ring-slate-400/20">Retired</span>
                            @endif
                        </td>

                        <td class="p-3 text-center align-middle">
                            <button type="button"
                                    class="btn-ghost btn-sm"
                                    @click="open = open === {{ $category->id }} ? null : {{ $category->id }}">
                                <x-nav-icon name="pencil" class="h-3.5 w-3.5" />
                                Edit
                            </button>
                        </td>
                    </tr>

                    {{--
                        The edit form opens in the row below the one it belongs to, the
                        same way a message template opens. The code is not editable: a
                        structure line and an import both point at it.
                    --}}
                    <tr x-show="open === {{ $category->id }}" x-cloak>
                        <td colspan="6" class="border-t border-line bg-surface-2 p-5">
                            <form method="POST" action="{{ route('admin.fees.categories.update', $category) }}">
                                @csrf
                                @method('PUT')

                                <div class="grid gap-4 sm:grid-cols-3">
                                    <x-field name="name" label="Name" required :value="$category->name" />

                                    <x-field name="type" label="Kind" type="select" required
                                             :value="$category->type" :options="$types" />

                                    <x-field name="description" label="Description" :value="$category->description" />
                                </div>

                                <div class="mt-4 flex flex-wrap items-center justify-between gap-4">
                                    <label class="flex items-center gap-2.5 text-sm text-ink-soft">
                                        <input type="hidden" name="is_active" value="">
                                        <input type="checkbox" name="is_active" value="1"
                                               @checked($category->is_active)
                                               class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                                        Still in use
                                    </label>

                                    <div class="flex items-center gap-2">
                                        <button type="button" class="btn-ghost btn-sm" @click="open = null">Cancel</button>
                                        <button type="submit" class="btn-primary btn-sm">Save category</button>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-5">
                            <x-empty-state
                                icon="tag"
                                title="No fee categories yet"
                                description="Add the kinds of money the school collects — tuition, a levy, boarding — and then build the fee structures that price them." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
