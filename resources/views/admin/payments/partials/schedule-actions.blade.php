{{--
    The two things the office does to a sheet of slips, as panels.

    A plain @include, not a component: there is no `x-data` here on purpose. Both panels read
    `selected`, `adjust`, `pay` and `mode` from the page's own Alpine scope, and a component
    of their own would be a sibling that cannot see the tick-boxes in the grid.

    The children are sent as hidden inputs built from that selection, rather than by putting
    the checkboxes inside the form — the checkboxes live in the grid, which is a different
    element, and one control cannot belong to two forms.
--}}

{{-- ================= Change what is owed ================= --}}
<div x-show="adjust" x-cloak
     class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:p-8"
     @keydown.escape.window="adjust = false">

    <div class="card mx-auto w-full max-w-lg" @click.outside="adjust = false">
        <div class="panel-header">
            <div class="min-w-0">
                <p class="panel-title">Change what is owed</p>
                <p class="mt-0.5 text-xs text-muted">
                    <span x-text="chosen"></span><span x-text="chosen === 1 ? ' child' : ' children'"></span>
                    · {{ $filters['term']?->name ?? 'the whole session' }}
                </p>
            </div>

            <button type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-line text-ink-soft transition hover:bg-surface-3"
                    title="Close" @click="adjust = false">
                <x-nav-icon name="x-mark" class="h-4 w-4" />
            </button>
        </div>

        <form method="POST" action="{{ route('admin.payments.schedule.adjust') }}">
            @csrf

            {{-- Which term the change belongs to. Without it the adjustment would land on
                 whichever term the app happens to be in when the form is read. --}}
            <input type="hidden" name="session" value="{{ $filters['session']?->id }}">
            <input type="hidden" name="term" value="{{ $filters['term']?->id }}">

            <template x-for="id in selected" :key="id">
                <input type="hidden" name="students[]" :value="id">
            </template>

            <div class="grid gap-5 px-5 py-5 sm:grid-cols-2">
                <x-field name="amount" label="Amount" type="number"
                         required
                         hint="Per child, not in total." />

                <x-field name="type" label="What it does" type="select"
                         :placeholder-option="false"
                         :options="['subtract' => 'Take it off the bill', 'add' => 'Add it to the bill']" />

                <div class="sm:col-span-2">
                    <x-field name="description" label="Why" type="text"
                             placeholder="Sibling discount"
                             hint="Printed on the slip beside the amount. Leave blank for Discount or Additional charge." />
                </div>
            </div>

            <div class="flex items-center justify-between gap-3 border-t border-line-soft px-5 py-4">
                <p class="text-xs text-muted">
                    One line each, so it can be reversed for one child later.
                </p>

                <div class="flex items-center gap-2">
                    <button type="button" class="btn-secondary" @click="adjust = false">Cancel</button>
                    <button type="submit" class="btn-primary" :disabled="selected.length === 0">
                        Change <span x-text="chosen"></span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- ================= Mark as paid ================= --}}
<div x-show="pay" x-cloak
     class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:p-8"
     @keydown.escape.window="pay = false">

    <div class="card mx-auto w-full max-w-lg" @click.outside="pay = false">
        <div class="panel-header">
            <div class="min-w-0">
                <p class="panel-title">Record money received</p>
                <p class="mt-0.5 text-xs text-muted">
                    <span x-text="chosen"></span><span x-text="chosen === 1 ? ' child' : ' children'"></span>
                    · {{ $filters['term']?->name ?? 'the whole session' }}
                </p>
            </div>

            <button type="button"
                    class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-line text-ink-soft transition hover:bg-surface-3"
                    title="Close" @click="pay = false">
                <x-nav-icon name="x-mark" class="h-4 w-4" />
            </button>
        </div>

        <form method="POST" action="{{ route('admin.payments.schedule.record') }}">
            @csrf

            <input type="hidden" name="session" value="{{ $filters['session']?->id }}">
            <input type="hidden" name="term" value="{{ $filters['term']?->id }}">

            <template x-for="id in selected" :key="id">
                <input type="hidden" name="students[]" :value="id">
            </template>

            <div class="grid gap-5 px-5 py-5 sm:grid-cols-2">
                <x-field name="mode" label="How much" type="select"
                         x-model="mode"
                         :placeholder-option="false"
                         :options="['full' => 'The whole balance', 'part' => 'The same part payment each']" />

                <x-field name="method" label="How it came in" type="select"
                         :placeholder-option="false"
                         :options="['cash' => 'Cash', 'bank_transfer' => 'Bank transfer', 'card' => 'Card', 'gateway' => 'Online payment', 'cheque' => 'Cheque']" />

                <div x-show="mode === 'part'" x-cloak>
                    <x-field name="amount" label="Amount each" type="number"
                             hint="Capped at what each child actually owes." />
                </div>

                <div>
                    <x-field name="paid_at" label="When" type="date"
                             hint="Leave blank for today." />
                </div>

                <div class="sm:col-span-2">
                    <x-field name="notes" label="Note" type="text"
                             placeholder="Reference, or what it was for" />
                </div>
            </div>

            <div class="flex items-start justify-between gap-3 border-t border-line-soft px-5 py-4">
                {{-- Said here rather than discovered afterwards: this is a bill-settling run,
                     not the counter taking one payment, and forty texts is not a favour. --}}
                <p class="max-w-xs text-xs text-muted">
                    Goes against each child's bill for this term, raising it first if it does not
                    exist yet. No receipt text is sent from here.
                </p>

                <div class="flex items-center gap-2">
                    <button type="button" class="btn-secondary" @click="pay = false">Cancel</button>
                    <button type="submit" class="btn-primary" :disabled="selected.length === 0">
                        Record <span x-text="chosen"></span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
