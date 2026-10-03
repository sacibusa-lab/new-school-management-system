@extends('layouts.admin')

@section('title', 'Bank Accounts')
@section('subtitle', 'Business')

@section('content')

{{--
    The school's own accounts, as against the one each child is given. These are what
    a letter names and what the office reads out over the telephone, so the number is
    put to the bank before it is saved: a mistyped account number is money that never
    arrives, and nobody finds out until a parent produces a teller slip.
--}}
<p class="max-w-3xl text-sm text-muted">
    The accounts the school is paid into. One of them is the main fees account — the one printed on
    a letter when nobody says otherwise.
</p>

@if ($banks === [])
    <p class="mt-2 max-w-3xl text-xs text-muted">
        Paystack is not set up, so an account number cannot be checked against the bank and the name
        has to be typed in. Add the keys in Settings under API to have it confirmed instead.
    </p>
@endif

{{-- ================= Add one ================= --}}
<div class="card-pad mt-6 lg:max-w-3xl">
    <h2 class="text-base font-semibold text-ink">Add an account</h2>

    <form method="POST" action="{{ route('admin.bank-accounts.store') }}" class="mt-5">
        @csrf

        <div class="grid gap-5 sm:grid-cols-2">
            <x-field name="label" label="What it is for" required placeholder="e.g. Fees account" />

            @if ($banks !== [])
                <x-field name="bank_code" label="Bank" type="select" required
                         :options="collect($banks)->pluck('name', 'code')->all()" />
            @else
                <x-field name="bank_name" label="Bank" required placeholder="e.g. Wema Bank" />
            @endif

            <x-field name="account_number" label="Account number" required
                     hint="Ten digits. Put to the bank before it is saved." />

            @if ($banks === [])
                <x-field name="account_name" label="Name on the account" required
                         hint="Exactly as the bank holds it." />
            @endif
        </div>

        <label class="mt-5 flex items-start gap-3 rounded-xl border border-line p-4">
            <input type="hidden" name="is_primary" value="">
            <input type="checkbox" name="is_primary" value="1"
                   class="mt-0.5 h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
            <span>
                <span class="block text-sm font-medium text-ink-soft">This is the main fees account</span>
                <span class="mt-0.5 block text-xs text-muted">
                    Marking a second account as the main one demotes the first — a letter that names two
                    accounts as the one to pay into names neither.
                </span>
            </span>
        </label>

        <button type="submit" class="btn-primary mt-5">Save account</button>
    </form>
</div>

{{-- ================= The list ================= --}}
<div class="card mt-6 overflow-hidden" x-data="{ open: null }">
    <div class="border-b border-line bg-surface-2 px-5 py-4">
        <p class="font-display text-base font-semibold text-ink">Accounts</p>
        <p class="mt-0.5 text-xs text-muted">
            {{ $accounts->count() }} {{ Str::plural('account', $accounts->count()) }}.
            An account that is closed is switched off rather than removed, so the bills and receipts
            that named it still make sense.
        </p>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse border border-line text-sm">
            <thead>
                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                    <th class="border-b border-r border-line p-3">What it is for</th>
                    <th class="border-b border-r border-line p-3">Bank</th>
                    <th class="w-36 border-b border-r border-line p-3">Account number</th>
                    <th class="border-b border-r border-line p-3">Name on the account</th>
                    <th class="w-32 border-b border-r border-line p-3 text-center">State</th>
                    <th class="w-28 border-b border-line p-3 text-center">Action</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-line text-ink-soft">
                @forelse ($accounts as $account)
                    <tr class="transition-colors hover:bg-surface-3/60">
                        <td class="border-r border-line p-3 align-middle font-medium text-ink">
                            {{ $account->label }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">{{ $account->bank_name }}</td>

                        <td class="border-r border-line p-3 align-middle font-mono text-xs">
                            {{ $account->account_number }}
                        </td>

                        <td class="border-r border-line p-3 align-middle">{{ $account->account_name }}</td>

                        <td class="border-r border-line p-3 text-center align-middle">
                            @if ($account->is_primary)
                                <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">Main account</span>
                            @elseif (! $account->is_active)
                                <span class="badge bg-surface-3 text-muted ring-slate-500/20 dark:ring-slate-400/20">Closed</span>
                            @else
                                <span class="badge-neutral">In use</span>
                            @endif
                        </td>

                        <td class="p-3 text-center align-middle">
                            <button type="button" class="btn-ghost btn-sm"
                                    @click="open = open === {{ $account->id }} ? null : {{ $account->id }}">
                                <x-nav-icon name="pencil" class="h-3.5 w-3.5" />
                                Edit
                            </button>
                        </td>
                    </tr>

                    <tr x-show="open === {{ $account->id }}" x-cloak>
                        <td colspan="6" class="border-t border-line bg-surface-2 p-5">
                            <form method="POST" action="{{ route('admin.bank-accounts.update', $account) }}">
                                @csrf
                                @method('PUT')

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <x-field name="label" label="What it is for" required :value="$account->label" />

                                    <div class="space-y-3 pt-6">
                                        <label class="flex items-center gap-2.5 text-sm text-ink-soft">
                                            <input type="hidden" name="is_primary" value="">
                                            <input type="checkbox" name="is_primary" value="1" @checked($account->is_primary)
                                                   class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                                            The main fees account
                                        </label>

                                        <label class="flex items-center gap-2.5 text-sm text-ink-soft">
                                            <input type="hidden" name="is_active" value="">
                                            <input type="checkbox" name="is_active" value="1" @checked($account->is_active)
                                                   class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                                            Still in use
                                        </label>
                                    </div>
                                </div>

                                <div class="mt-4 flex items-center justify-between gap-3">
                                    <span class="text-xs text-muted">
                                        The number and the name on it are the bank's; changing them means
                                        adding the account again.
                                    </span>

                                    <div class="flex items-center gap-2">
                                        <button type="button" class="btn-ghost btn-sm" @click="open = null">Cancel</button>
                                        <button type="submit" class="btn-primary btn-sm">Save</button>
                                    </div>
                                </div>
                            </form>

                            <form method="POST" action="{{ route('admin.bank-accounts.destroy', $account) }}"
                                  class="mt-4 border-t border-line pt-4"
                                  onsubmit="return confirm('Remove this account? Add it again if it comes back.')">
                                @csrf
                                @method('DELETE')

                                <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400">
                                    <x-nav-icon name="trash" class="h-3.5 w-3.5" />
                                    Remove this account
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="p-5">
                            <x-empty-state
                                icon="briefcase"
                                title="No bank accounts yet"
                                description="Add the accounts the school is paid into. The main one is what a letter names when nobody says otherwise." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
