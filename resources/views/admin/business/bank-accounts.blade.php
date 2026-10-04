@extends('layouts.admin')

@section('title', 'Bank Accounts')
@section('subtitle', 'Business')

@section('content')

{{--
    The school's own accounts, as against the one each child is given. These are what
    a letter names and what the office reads out over the telephone, so the number is
    put to the bank before it is saved: a mistyped account number is money that never
    arrives, and nobody finds out until a parent produces a teller slip.

    The form is filled in number first, and the bank is asked the moment both answers
    are in. That is for the office's sake rather than the bank's — the name it is about
    to save is on the screen before they press anything — and it is asked once more on
    submit, because a request does not have to come from this page.
--}}
<p class="max-w-3xl text-sm text-muted">
    The accounts the school is paid into. One of them is the main fees account — the one printed on
    a letter when nobody says otherwise.
</p>

{{-- ================= Add one ================= --}}
<div class="card-pad mt-6 lg:max-w-3xl">
    <h2 class="text-base font-semibold text-ink">Add an account</h2>

    @if ($banks !== [])
        <p class="mt-1 max-w-2xl text-sm text-muted">
            Type the account number, then choose the bank it is held with. The name it is held in comes
            back from the bank, and the bank's answer is what gets saved.
        </p>

        {{--
            The whole Alpine object below is an HTML attribute, so nothing inside it may
            contain a double quote — not even in a comment. One does, and the attribute
            ends early: Alpine is handed a truncated object, every method in it vanishes,
            and the page reports `typed is not defined` while looking perfectly normal.

            The resolve URL and the token are handed in rather than read off the element:
            `$el` inside an x-data method is not this form, and `dataset.resolve` came back
            undefined, so the page asked the server for a route named undefined.
        --}}
        <form method="POST"
              action="{{ route('admin.bank-accounts.store') }}"
              class="mt-5"
              x-data="{
                  resolveUrl: @js(route('admin.bank-accounts.resolve')),
                  csrf: @js(csrf_token()),

                  number: '',
                  bankCode: '',
                  name: '',
                  checking: false,
                  error: '',
                  timer: null,

                  get ready() {
                      return this.number.length === 10 && this.bankCode !== '';
                  },

                  start() {
                      this.number = this.$refs.number.value.trim();
                      this.bankCode = this.$refs.bank.value;

                      if (this.ready) {
                          this.check();
                      }
                  },

                  typed(input) {
                      this.number = input.value.replace(/\D/g, '').slice(0, 10);

                      // Written back rather than left to the binding. A letter typed into
                      // the box leaves this.number unchanged when it is the only character,
                      // so nothing re-renders and the letter stays on screen in a field
                      // that is supposed to hold ten digits and nothing else.
                      if (input.value !== this.number) {
                          input.value = this.number;
                      }

                      this.forget();
                      clearTimeout(this.timer);

                      // Only once the number is complete. Asking the bank on the seventh
                      // digit would be seven questions with six useless answers.
                      if (this.ready) {
                          this.timer = setTimeout(() => this.check(), 500);
                      }
                  },

                  chose(value) {
                      this.bankCode = value;

                      // A bank picked within the half second the debounce is waiting out
                      // would otherwise be asked about twice: once now, and once again when
                      // the timer it did not cancel comes round.
                      clearTimeout(this.timer);
                      this.check();
                  },

                  forget() {
                      this.name = '';
                      this.error = '';
                  },

                  async check() {
                      if (! this.ready || this.checking) {
                          return;
                      }

                      this.checking = true;
                      this.forget();

                      try {
                          const response = await fetch(this.resolveUrl, {
                              method: 'POST',
                              headers: {
                                  'Content-Type': 'application/json',
                                  'Accept': 'application/json',
                                  'X-CSRF-TOKEN': this.csrf,
                              },
                              body: JSON.stringify({ account_number: this.number, bank_code: this.bankCode }),
                          });

                          const answer = await response.json();

                          if (answer.ok) {
                              this.name = answer.account_name;
                          } else {
                              this.error = answer.message || 'That account number could not be checked.';
                          }
                      } catch (problem) {
                          this.error = 'The bank could not be reached just now. Try again.';
                      } finally {
                          this.checking = false;
                      }
                  },
              }"
              x-init="start()">
            @csrf

            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="account_number" class="label">
                        Account number <span class="text-rose-500">*</span>
                    </label>

                    <input id="account_number"
                           x-ref="number"
                           name="account_number"
                           type="text"
                           inputmode="numeric"
                           autocomplete="off"
                           maxlength="10"
                           placeholder="0123456789"
                           value="{{ old('account_number') }}"
                           @input="typed($event.target)"
                           class="input font-mono tracking-widest @error('account_number') input-error @enderror">

                    <p class="hint">Ten digits, as they are printed on a cheque.</p>

                    @error('account_number')
                        <p class="error-text">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="bank_code" class="label">
                        Bank <span class="text-rose-500">*</span>
                    </label>

                    {{-- Closed until the number is complete: there is nothing to ask the
                         bank yet, and a bank chosen against half a number is a bank chosen
                         against a different account. --}}
                    <select id="bank_code"
                            x-ref="bank"
                            name="bank_code"
                            @change="chose($event.target.value)"
                            :disabled="number.length !== 10"
                            class="input @error('bank_code') input-error @enderror">
                        <option value="" @selected(blank(old('bank_code')))>Choose the bank</option>

                        @foreach ($banks as $bank)
                            <option value="{{ $bank['code'] }}" @selected(old('bank_code') === $bank['code'])>
                                {{ $bank['name'] }}
                            </option>
                        @endforeach
                    </select>

                    <p class="hint" x-show="number.length === 10" x-cloak>
                        Choosing one fetches the name it is held in.
                    </p>
                    <p class="hint" x-show="number.length !== 10">
                        The ten digits first.
                    </p>

                    @error('bank_code')
                        <p class="error-text">{{ $message }}</p>
                    @enderror
                </div>

                {{-- What the bank says, while it is being said. Three states in one place
                     rather than a field that changes under the reader's hands. --}}
                <div class="sm:col-span-2"
                     x-show="checking || name !== '' || error !== ''"
                     x-cloak>
                    <p class="label">Name on the account</p>

                    <div class="rounded-xl border px-4 py-3"
                         :class="error !== ''
                             ? 'border-rose-300 bg-rose-50 dark:border-rose-900 dark:bg-rose-950/40'
                             : 'border-line bg-surface-2'">

                        <template x-if="checking">
                            <span class="flex items-center gap-2.5 text-sm text-muted">
                                <svg class="h-4 w-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                                </svg>
                                Checking with the bank…
                            </span>
                        </template>

                        <template x-if="! checking && name !== ''">
                            <span class="flex items-center gap-2.5 text-sm font-medium text-ink">
                                <x-nav-icon name="check" class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                <span x-text="name"></span>
                            </span>
                        </template>

                        <template x-if="! checking && error !== ''">
                            <span class="flex items-start gap-2.5 text-sm text-rose-700 dark:text-rose-300">
                                <x-nav-icon name="x-mark" class="mt-0.5 h-4 w-4 shrink-0" />
                                <span x-text="error"></span>
                            </span>
                        </template>
                    </div>

                    <p class="hint">
                        What the bank holds the account in. It is asked again when you save, and it is
                        that answer which is kept.
                    </p>
                </div>
            </div>

            <label class="mt-5 flex items-start gap-3 rounded-xl border border-line p-4">
                <input type="hidden" name="is_primary" value="">
                <input type="checkbox" name="is_primary" value="1" @checked(old('is_primary'))
                       class="mt-0.5 h-4 w-4 rounded border-line text-brand-700 dark:text-brand-200 focus:ring-brand-500">
                <span>
                    <span class="block text-sm font-medium text-ink-soft">This is the main fees account</span>
                    <span class="mt-0.5 block text-xs text-muted">
                        Marking a second account as the main one demotes the first — a letter that names two
                        accounts as the one to pay into names neither.
                    </span>
                </span>
            </label>

            <div class="mt-5 flex flex-wrap items-center gap-3">
                {{-- Held shut until the bank has named it. Saving is what asks the bank for
                     the answer that counts, so saving before there is one is a round trip
                     that can only end in the same error. --}}
                <button type="submit" class="btn-primary" :disabled="! name">Save account</button>

                <p class="text-xs text-muted" x-show="! name" x-cloak>
                    Waiting for the bank to name the account.
                </p>
            </div>
        </form>
    @else
        {{--
            Two different situations, and telling them apart matters. "Paystack is not set
            up" sent somebody back to Settings → API to re-check a key that was already
            correct, when what had happened was that the server could not reach Paystack at
            all — a broken CA bundle in php.ini, in that case, which is a machine fault and
            nothing to do with the school's account.
        --}}
        <p class="mt-1 max-w-2xl text-sm text-muted">
            @if ($paystackReady)
                Paystack has a key but did not answer just now, so the number cannot be put to the
                bank and the name has to be typed in. Try again in a moment; if it keeps happening,
                check the key in Settings → API.
            @else
                Paystack is not set up, so the number cannot be put to the bank and the name has to be
                typed in. Add the keys in Settings → API and an account number will be checked before
                it is saved.
            @endif
        </p>

        <form method="POST" action="{{ route('admin.bank-accounts.store') }}" class="mt-5">
            @csrf

            <div class="grid gap-5 sm:grid-cols-2">
                <x-field name="account_number" label="Account number" required
                         hint="Ten digits, as they are printed on a cheque." />

                <x-field name="bank_name" label="Bank" required placeholder="e.g. Wema Bank"
                         hint="Exactly as the bank writes it." />

                <x-field name="account_name" label="Name on the account" required
                         hint="Exactly as the bank holds it." />
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
    @endif
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
                        <td class="border-r border-line p-3 align-middle font-medium text-ink">{{ $account->bank_name }}</td>

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
                        <td colspan="5" class="border-t border-line bg-surface-2 p-5">
                            <form method="POST" action="{{ route('admin.bank-accounts.update', $account) }}">
                                @csrf
                                @method('PUT')

                                {{-- The caption is gone, so the only things left to edit are the
                                     two that are the school's rather than the bank's. --}}
                                <div class="flex flex-wrap items-center gap-6">
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
                        <td colspan="5" class="p-5">
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
