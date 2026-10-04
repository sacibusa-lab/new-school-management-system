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

                <x-field name="revenue_code" label="Revenue code" maxlength="40"
                         :value="$fee->revenue_code"
                         hint="Optional. The code the school's own books know this fee by." />

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

                {{-- The terms together: what each one costs, and whether the fee comes round
                     in it at all. Two questions about the same term, so they are asked in the
                     same place rather than a checkbox block and an amount block apart. --}}
                <div class="rounded-xl border border-line p-5">
                    <p class="text-sm font-semibold text-ink">Terms</p>
                    <p class="mt-1 text-sm text-muted">
                        What this fee costs in each term, and which terms it comes round in. A blank
                        amount means the default above. The other two cycles ignore the amounts.
                    </p>

                    <div class="mt-4 space-y-3">
                        @foreach ($terms as $term)
                            <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
                                <label class="inline-flex w-36 shrink-0 items-center gap-2 text-sm text-ink-soft">
                                    <input type="checkbox" name="{{ $term['active'] }}" value="1"
                                           @checked(old($term['active'], $fee->{$term['active']}))
                                           class="h-4 w-4 rounded border-line text-brand-700 dark:text-brand-300">
                                    {{ $term['label'] }}
                                </label>

                                <div class="min-w-0 flex-1">
                                    <input type="number" name="{{ $term['amount'] }}" step="0.01" min="0"
                                           value="{{ old($term['amount'], $fee->{$term['amount']}) }}"
                                           placeholder="Default: {{ number_format((float) $fee->amount, 2) }}"
                                           class="input">
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2 border-t border-line pt-5">
                    <button type="submit" class="btn-primary">Save changes</button>
                    <a href="{{ route('admin.fees.index') }}" class="btn-secondary">Back to the fee list</a>
                </div>
            </form>
        @endif

        {{-- ================= Internal ledger splits ================= --}}
        @if ($tab === 'splits')
            <div x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-ink">Internal ledger splits</p>
                        <p class="mt-1 max-w-2xl text-sm text-muted">
                            How this fee is divided between the school's own accounts. Whatever is not
                            split out pays into the main account, so a fee with no splits is a fee that
                            has not been divided — not one nobody finished.
                        </p>
                    </div>

                    <button type="button" class="btn-secondary btn-sm" @click="open = true">
                        <x-nav-icon name="columns" class="h-4 w-4" />
                        Configure splits
                    </button>
                </div>

                @error('beneficiaries')
                    <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900 ring-1 ring-inset ring-rose-600/15 dark:bg-rose-950/40 dark:text-rose-100">
                        {{ $message }}
                    </p>
                @enderror

                @if ($fee->beneficiaries->isEmpty())
                    <p class="mt-5 rounded-xl bg-surface-2 p-6 text-center text-sm text-muted ring-1 ring-line">
                        No splits. All {{ $school->currency }}{{ number_format((float) $fee->amount, 2) }}
                        of this fee pays into the main account.
                    </p>
                @else
                    <div class="mt-5 overflow-hidden rounded-xl ring-1 ring-line">
                        <table class="w-full border-collapse text-sm">
                            <thead>
                                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                                    <th class="p-3">Account</th>
                                    <th class="w-40 p-3 text-right">Share</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-line">
                                @foreach ($fee->beneficiaries as $split)
                                    <tr>
                                        <td class="p-3">
                                            {{-- The bank's name for the account, because the caption the
                                                 office used to type ("Fees account") is gone — and the
                                                 bank's answer is the one thing here nobody typed. --}}
                                            @if ($split->bankAccount)
                                                <span class="block font-medium text-ink">
                                                    {{ $split->bankAccount->account_name }}
                                                </span>

                                                <span class="mt-0.5 block font-mono text-xs text-muted">
                                                    {{ $split->bankAccount->account_number }} · {{ $split->bankAccount->bank_name }}
                                                </span>
                                            @else
                                                <span class="block font-medium text-muted">Account no longer held</span>
                                            @endif
                                        </td>

                                        <td class="p-3 text-right font-mono text-xs font-semibold text-ink">
                                            {{ $school->currency }}{{ number_format((float) $split->amount, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>

                            <tfoot class="bg-surface-2">
                                <tr>
                                    <td class="p-3 text-xs text-muted">Pays into the main account</td>
                                    <td class="p-3 text-right font-mono text-xs font-semibold {{ $fee->unsplitAmount() > 0 ? 'text-ink' : 'text-muted' }}">
                                        {{ $school->currency }}{{ number_format($fee->unsplitAmount(), 2) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif

                <div x-show="open" x-cloak
                     class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:p-8"
                     @keydown.escape.window="open = false">

                    <div class="card mx-auto w-full max-w-2xl" @click.outside="open = false">
                        <div class="panel-header">
                            <div>
                                <p class="panel-title">Split {{ $fee->title }}</p>
                                <p class="mt-0.5 text-xs text-muted">
                                    Of {{ $school->currency }}{{ number_format((float) $fee->amount, 2) }}.
                                    Anything left out pays into the main account.
                                </p>
                            </div>

                            <button type="button"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-line text-ink-soft transition hover:bg-surface-3"
                                    title="Close" @click="open = false">
                                <x-nav-icon name="x-mark" class="h-4 w-4" />
                            </button>
                        </div>

                        <form method="POST" action="{{ route('admin.fees.beneficiaries', $fee) }}"
                              x-data="{
                                  fee: {{ (float) $fee->amount }},
                                  rows: @js($fee->beneficiaries->map(fn ($split) => ['bank_account_id' => (string) $split->bank_account_id, 'amount' => (string) $split->amount])->values()),
                                  accounts: @js($bankAccounts->map(fn ($account) => ['id' => $account->id, 'label' => $account->bank_name.' — '.$account->account_number])->values()),
                                  add() {
                                      const used = this.rows.map(row => String(row.bank_account_id));
                                      const free = this.accounts.find(account => ! used.includes(String(account.id)));

                                      if (free) {
                                          this.rows.push({ bank_account_id: String(free.id), amount: '' });
                                      }
                                  },
                                  taken(index, id) {
                                      return this.rows.some((row, at) => at !== index && String(row.bank_account_id) === String(id));
                                  },
                                  total() {
                                      return this.rows.reduce((sum, row) => sum + (parseFloat(row.amount) || 0), 0);
                                  },
                                  over() {
                                      return this.total() > this.fee;
                                  },
                              }">
                            @csrf

                            <div class="space-y-3 p-5 sm:p-6">
                                <template x-for="(row, index) in rows" :key="index">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <div class="min-w-0 flex-1">
                                            <select :name="`beneficiaries[${index}][bank_account_id]`"
                                                    x-model="row.bank_account_id" class="input">
                                                <template x-for="account in accounts" :key="account.id">
                                                    <option :value="String(account.id)"
                                                            :disabled="taken(index, account.id)"
                                                            x-text="account.label"></option>
                                                </template>
                                            </select>
                                        </div>

                                        <div class="w-40 shrink-0">
                                            <input type="number" step="0.01" min="0" placeholder="Amount"
                                                   :name="`beneficiaries[${index}][amount]`"
                                                   x-model="row.amount" class="input">
                                        </div>

                                        <button type="button"
                                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-line text-ink-soft transition hover:bg-surface-3"
                                                title="Remove this split" @click="rows.splice(index, 1)">
                                            <x-nav-icon name="trash" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </template>

                                @if ($bankAccounts->isEmpty())
                                    <p class="rounded-xl bg-surface-2 p-4 text-sm text-muted ring-1 ring-line">
                                        No bank accounts have been set up yet. Add them under Business →
                                        Bank accounts, then come back to divide this fee between them.
                                    </p>
                                @else
                                    <button type="button" class="btn-secondary btn-sm" @click="add()"
                                            :disabled="rows.length >= accounts.length">
                                        <x-nav-icon name="plus" class="h-4 w-4" />
                                        Add an account
                                    </button>
                                @endif

                                <p class="text-xs" :class="over() ? 'text-rose-600 dark:text-rose-400' : 'text-muted'">
                                    <span x-text="`Split: {{ $school->currency }}${total().toLocaleString()}`"></span>
                                    · <span x-text="`Left for the main account: {{ $school->currency }}${Math.max(fee - total(), 0).toLocaleString()}`"></span>
                                    <span x-show="over()"> — that is more than the fee.</span>
                                </p>
                            </div>

                            <div class="flex items-center justify-end gap-2 border-t border-line px-5 py-4">
                                <button type="button" class="btn-secondary btn-sm" @click="open = false">Cancel</button>
                                <button type="submit" class="btn-primary btn-sm" :disabled="over()">Save splits</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        {{-- ================= Class amounts ================= --}}
        @if ($tab === 'class-amounts')
            <div x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-sm font-semibold text-ink">Class amounts</p>
                        <p class="mt-1 max-w-2xl text-sm text-muted">
                            A year group charged something other than the default. Every arm of the year
                            group is charged it, so this is set per year group and not per class.
                        </p>
                    </div>

                    <button type="button" class="btn-secondary btn-sm" @click="open = true">
                        <x-nav-icon name="tag" class="h-4 w-4" />
                        Manage amounts
                    </button>
                </div>

                @error('overrides')
                    <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900 ring-1 ring-inset ring-rose-600/15 dark:bg-rose-950/40 dark:text-rose-100">
                        {{ $message }}
                    </p>
                @enderror

                @if ($fee->overrides->isEmpty())
                    <p class="mt-5 rounded-xl bg-surface-2 p-6 text-center text-sm text-muted ring-1 ring-line">
                        No year group is charged anything different. Every one pays the default of
                        {{ $school->currency }}{{ number_format((float) $fee->amount, 2) }}.
                    </p>
                @else
                    <div class="mt-5 overflow-hidden rounded-xl ring-1 ring-line">
                        <table class="w-full border-collapse text-sm">
                            <thead>
                                <tr class="bg-surface-3 text-left font-semibold text-ink-soft">
                                    <th class="p-3">Year group</th>
                                    <th class="w-40 p-3 text-right">Amount</th>
                                    <th class="w-28 p-3 text-center">Status</th>
                                </tr>
                            </thead>

                            <tbody class="divide-y divide-line">
                                @foreach ($fee->overrides->sortBy(fn ($override) => $override->level?->order ?? 0) as $override)
                                    <tr>
                                        <td class="p-3 font-medium text-ink">
                                            {{ $override->level?->name ?? 'Year group no longer exists' }}
                                        </td>

                                        <td class="p-3 text-right font-mono text-xs font-semibold text-ink">
                                            {{ $school->currency }}{{ number_format((float) $override->amount, 2) }}
                                        </td>

                                        <td class="p-3 text-center">
                                            <span class="badge {{ $override->isActive()
                                                ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20'
                                                : 'bg-surface-3 text-muted ring-slate-500/20 dark:ring-slate-400/20' }}">
                                                {{ $override->isActive() ? 'Active' : 'Inactive' }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div x-show="open" x-cloak
                     class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:p-8"
                     @keydown.escape.window="open = false">

                    <div class="card mx-auto w-full max-w-2xl" @click.outside="open = false">
                        <div class="panel-header">
                            <div>
                                <p class="panel-title">Class amounts for {{ $fee->title }}</p>
                                <p class="mt-0.5 text-xs text-muted">
                                    Every year group not listed pays the default of
                                    {{ $school->currency }}{{ number_format((float) $fee->amount, 2) }}.
                                </p>
                            </div>

                            <button type="button"
                                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-line text-ink-soft transition hover:bg-surface-3"
                                    title="Close" @click="open = false">
                                <x-nav-icon name="x-mark" class="h-4 w-4" />
                            </button>
                        </div>

                        <form method="POST" action="{{ route('admin.fees.overrides', $fee) }}"
                              x-data="{
                                  rows: @js($fee->overrides->map(fn ($override) => ['level_id' => (string) $override->level_id, 'amount' => (string) $override->amount, 'status' => $override->status])->values()),
                                  levels: @js($levels->map(fn ($level) => ['id' => $level->id, 'name' => $level->name])->values()),
                                  fallback: {{ (float) $fee->amount }},
                                  add() {
                                      const used = this.rows.map(row => String(row.level_id));
                                      const free = this.levels.find(level => ! used.includes(String(level.id)));

                                      if (free) {
                                          this.rows.push({ level_id: String(free.id), amount: String(this.fallback), status: 'active' });
                                      }
                                  },
                                  taken(index, id) {
                                      return this.rows.some((row, at) => at !== index && String(row.level_id) === String(id));
                                  },
                              }">
                            @csrf

                            <div class="space-y-3 p-5 sm:p-6">
                                <template x-for="(row, index) in rows" :key="index">
                                    <div class="flex flex-wrap items-center gap-3">
                                        <div class="min-w-0 flex-1">
                                            <select :name="`overrides[${index}][level_id]`"
                                                    x-model="row.level_id" class="input">
                                                <template x-for="level in levels" :key="level.id">
                                                    <option :value="String(level.id)"
                                                            :disabled="taken(index, level.id)"
                                                            x-text="level.name"></option>
                                                </template>
                                            </select>
                                        </div>

                                        <div class="w-40 shrink-0">
                                            <input type="number" step="0.01" min="0" placeholder="Amount"
                                                   :name="`overrides[${index}][amount]`"
                                                   x-model="row.amount" class="input">
                                        </div>

                                        <div class="w-32 shrink-0">
                                            <select :name="`overrides[${index}][status]`"
                                                    x-model="row.status" class="input">
                                                @foreach ($statuses as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                        </div>

                                        <button type="button"
                                                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-line text-ink-soft transition hover:bg-surface-3"
                                                title="Remove this year group" @click="rows.splice(index, 1)">
                                            <x-nav-icon name="trash" class="h-4 w-4" />
                                        </button>
                                    </div>
                                </template>

                                @if ($levels->isEmpty())
                                    <p class="rounded-xl bg-surface-2 p-4 text-sm text-muted ring-1 ring-line">
                                        No year groups have been set up yet. Add them under Academic, then
                                        come back to price this fee for each of them.
                                    </p>
                                @else
                                    <button type="button" class="btn-secondary btn-sm" @click="add()"
                                            :disabled="rows.length >= levels.length">
                                        <x-nav-icon name="plus" class="h-4 w-4" />
                                        Add a year group
                                    </button>
                                @endif
                            </div>

                            <div class="flex items-center justify-end gap-2 border-t border-line px-5 py-4">
                                <button type="button" class="btn-secondary btn-sm" @click="open = false">Cancel</button>
                                <button type="submit" class="btn-primary btn-sm">Save amounts</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        {{-- ================= Transactions ================= --}}
        @if ($tab === 'transactions')
            <div class="mx-auto max-w-2xl rounded-xl bg-surface-2 p-8 text-center ring-1 ring-line">
                <p class="font-display text-base font-semibold text-ink">Nothing to show yet</p>
                <p class="mx-auto mt-2 max-w-xl text-sm text-muted">
                    What has been paid against this fee, term by term. It fills in once bills are raised from it, because money is recorded against a bill and not against the catalogue.
                </p>
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
