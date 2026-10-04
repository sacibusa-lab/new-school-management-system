{{--
    One year group's children, opened from a row of the overview.

    Drawn from the Alpine scope of the page rather than its own: the chevron that opens it
    is in the table, and the two have to share the state that says which row is open. So
    there is no `x-data` here — this is markup inside somebody else's component.

    The three colours are a partition. Every child on the roll is green, yellow or red and
    no child is two of them, which is deliberately not how the row above counts: there,
    "paid" and "owing" overlap for a family part-way through, and the page says so. Here
    the colours have to account for everybody between them or the grid looks wrong.
--}}
<div x-show="open" x-cloak
     class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-sm sm:p-8"
     @keydown.escape.window="close()">

    <div class="card mx-auto w-full max-w-6xl" @click.outside="close()">

        {{-- ================= Who and when ================= --}}
        <div class="panel-header">
            <div class="min-w-0">
                <p class="panel-title truncate" x-text="level.name"></p>
                <p class="mt-0.5 text-xs text-muted">
                    <span x-text="termName || 'No term set'"></span>
                    <span x-show="sessionName"> · <span x-text="sessionName"></span></span>
                    · <span x-text="currency + unit.toLocaleString()"></span> a child
                </p>
            </div>

            <div class="flex items-center gap-2">
                {{-- The four subsets the office asked for. One button, because four buttons
                     in a header is a toolbar; what it writes is the same list either way.

                     Links rather than JavaScript: the file is written by the server with
                     fputcsv, so it opens whether or not the page has finished loading,
                     and the filters travel as query parameters. --}}
                <div class="relative" x-data="{ menu: false }">
                    <button type="button"
                            class="btn-secondary btn-sm"
                            :disabled="children.length === 0"
                            @click="menu = ! menu">
                        <x-nav-icon name="download" class="h-3.5 w-3.5" />
                        Export
                    </button>

                    <div x-show="menu" x-cloak
                         class="absolute right-0 z-10 mt-1 w-48 overflow-hidden rounded-xl border border-line bg-surface shadow-lift"
                         @click.outside="menu = false">
                        @foreach (['all' => 'All children', 'completed' => 'Paid in full', 'partial' => 'Part payment', 'pending' => 'Not paid'] as $subset => $label)
                            <a :href="spreadsheet('{{ $subset }}')"
                               class="block px-3.5 py-2.5 text-left text-sm text-ink-soft transition hover:bg-surface-3"
                               @click="menu = false">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <button type="button"
                        class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-line text-ink-soft transition hover:bg-surface-3"
                        title="Close" @click="close()">
                    <x-nav-icon name="x-mark" class="h-4 w-4" />
                </button>
            </div>
        </div>

        {{-- ================= Narrowing it down ================= --}}
        <div class="border-b border-line-soft px-5 py-4">
            <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-12">
                <div class="lg:col-span-3">
                    <label for="level_subclass" class="label">Class</label>
                    <select id="level_subclass" class="input py-2 text-sm"
                            x-model="subclass">
                        <option value="">Every class</option>
                        <template x-for="arm in arms" :key="arm">
                            <option :value="arm" x-text="arm"></option>
                        </template>
                    </select>
                </div>

                <div class="lg:col-span-6">
                    <label for="level_search" class="label">Search</label>
                    <input id="level_search" type="text" class="input py-2 text-sm"
                           placeholder="Name or admission number"
                           x-model="search">
                </div>

                <p class="text-xs text-muted lg:col-span-3 lg:text-right">
                    <span x-text="visible.length"></span> of
                    <span x-text="children.length"></span> shown
                </p>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                <span class="flex items-center gap-1.5 text-muted">
                    <span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>
                    Paid in full <span x-text="tally.completed"></span>
                </span>
                <span class="flex items-center gap-1.5 text-muted">
                    <span class="h-2.5 w-2.5 rounded-full bg-gold-500"></span>
                    Part payment <span x-text="tally.partial"></span>
                </span>
                <span class="flex items-center gap-1.5 text-muted">
                    <span class="h-2.5 w-2.5 rounded-full bg-rose-500"></span>
                    Not paid <span x-text="tally.pending"></span>
                </span>
            </div>
        </div>

        {{-- ================= The children ================= --}}
        <div class="max-h-[60vh] overflow-y-auto px-5 py-4">
            <div x-show="loading" class="py-10 text-center text-sm text-muted">
                Reading the year group…
            </div>

            <div x-show="! loading && failed" class="py-10 text-center text-sm text-rose-700 dark:text-rose-300">
                That year group could not be read. Close this and try again.
            </div>

            <div x-show="! loading && ! failed && visible.length === 0" class="py-10 text-center text-sm text-muted">
                <span x-show="children.length === 0">Nobody is on this year group's roll.</span>
                <span x-show="children.length > 0">Nobody matches that.</span>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <template x-for="child in visible" :key="child.id">
                    <div class="rounded-xl border p-3.5"
                         :class="{
                             'border-emerald-300 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40': child.status === 'completed',
                             'border-gold-300 bg-gold-50 dark:border-gold-900 dark:bg-gold-950/40': child.status === 'partial',
                             'border-rose-300 bg-rose-50 dark:border-rose-900 dark:bg-rose-950/40': child.status === 'pending',
                         }">

                        <p class="truncate font-mono text-[11px] font-semibold text-ink" x-text="child.number"></p>
                        <p class="mt-0.5 truncate text-sm font-medium text-ink" x-text="child.name"></p>
                        <p class="mt-0.5 truncate text-[11px] text-muted" x-text="child.class || 'No class yet'"></p>

                        <dl class="mt-2.5 space-y-0.5 text-[11px] text-ink-soft">
                            <div class="flex justify-between gap-2">
                                <dt class="text-muted">Discount</dt>
                                <dd x-text="child.discount > 0 ? '−' + currency + child.discount.toLocaleString() : 'None'"></dd>
                            </div>

                            <div class="flex justify-between gap-2">
                                <dt class="text-muted">Status</dt>
                                <dd class="font-medium" x-text="child.status_label"></dd>
                            </div>

                            <div class="flex justify-between gap-2">
                                <dt class="text-muted">Received</dt>
                                <dd class="font-mono" x-text="currency + child.received.toLocaleString()"></dd>
                            </div>
                        </dl>
                    </div>
                </template>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-line-soft px-5 py-4">
            <p class="text-xs text-muted">
                The active roll, and what each child has paid against this term's bills.
            </p>

            <button type="button" class="btn-secondary btn-sm" @click="close()">Close</button>
        </div>
    </div>
</div>
