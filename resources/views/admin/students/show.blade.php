@extends('layouts.admin')

@section('title', $student->full_name)
@section('subtitle', $student->student_number.' · '.$student->status->label())

@section('actions')
    @can('students.manage')
        <a href="{{ route('admin.students.edit', $student) }}" class="btn-secondary btn-sm">Edit</a>

        {{-- Only offered where there is an account to reset: a student who has never
             been given portal access would be told about a password that is not there. --}}
        @if ($student->user)
            <form method="POST" action="{{ route('admin.students.reset-password', $student) }}"
                  onsubmit="return confirm('Put {{ $student->first_name }}’s portal password back to their admission number?');">
                @csrf
                <button type="submit" class="btn-ghost btn-sm">Reset portal password</button>
            </form>
        @endif
    @endcan
@endsection

@section('content')

{{-- ================= Who this is ================= --}}
<div class="card">
    <div class="flex flex-wrap items-center gap-4 p-5">
        @if ($student->photo_path)
            <img src="{{ asset('storage/'.$student->photo_path) }}"
                 alt="Photograph of {{ $student->full_name }}"
                 class="h-16 w-16 shrink-0 rounded-2xl object-cover ring-1 ring-line">
        @else
            <span class="inline-flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl bg-brand-900 font-display text-lg font-semibold text-gold-300"
                  aria-hidden="true">{{ $student->initials }}</span>
        @endif

        <div class="min-w-0 flex-1">
            <p class="font-display text-lg font-semibold text-ink">{{ $student->full_name }}</p>

            {{-- The admission number is the one the school knows them by, so it is the
                 one in the large hand; the registration number they applied with is a
                 footnote under it. --}}
            <p class="mt-0.5 font-mono text-sm font-semibold text-brand-800 dark:text-brand-200">
                {{ $student->student_number }}
            </p>

            <p class="mt-1 text-xs text-muted">
                {{ $student->schoolClass?->name ?? $student->level?->name ?? 'No class yet' }}
                · {{ $student->academicSession?->name ?? '—' }}

                @if ($student->admission_number)
                    · applied as {{ $student->admission_number }}
                @endif
            </p>
        </div>

        <x-status-pill :status="$student->status" />
    </div>
</div>

{{-- ================= What the fees have come to ================= --}}
<div class="mt-6 grid gap-4 sm:grid-cols-3">
    <x-stat-card label="Billed" icon="receipt" tone="brand"
                 :value="$school->currency.number_format($financials['billed'], 2)"
                 :hint="$invoices->count().' '.($invoices->count() === 1 ? 'invoice' : 'invoices').' raised against them'" />

    <x-stat-card label="Paid" icon="cash" tone="emerald"
                 :value="$school->currency.number_format($financials['paid'], 2)"
                 :hint="$payments->count().' '.($payments->count() === 1 ? 'payment' : 'payments').' on record'" />

    {{-- Cancelled invoices are left out of both figures, so a student whose bill was
         withdrawn does not read as owing money the school has stopped asking for. --}}
    <x-stat-card label="Outstanding" icon="scale"
                 :tone="$financials['balance'] > 0 ? 'rose' : 'emerald'"
                 :value="$school->currency.number_format($financials['balance'], 2)"
                 :hint="$financials['balance'] > 0 ? 'Still to come in' : 'Nothing owing'" />
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">

    {{-- ================= The record ================= --}}
    <div class="space-y-6 lg:col-span-2">

        <div class="card">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-display text-base font-semibold text-ink">Invoices</h2>
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice</th>
                            <th>Term</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Paid</th>
                            <th class="text-right">Balance</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($invoices as $invoice)
                            <tr>
                                <td>
                                    <p class="font-mono text-xs font-semibold text-ink">{{ $invoice->invoice_number }}</p>
                                    <p class="text-xs text-muted">{{ $invoice->issued_at?->format('j M Y') }}</p>
                                </td>

                                <td class="text-sm">{{ $invoice->term?->name ?? '—' }}</td>

                                <td class="text-right text-sm">
                                    {{ $school->currency }}{{ number_format((float) $invoice->total, 2) }}
                                </td>

                                <td class="text-right text-sm">
                                    {{ $school->currency }}{{ number_format((float) $invoice->amount_paid, 2) }}
                                </td>

                                <td class="text-right text-sm font-medium {{ (float) $invoice->balance > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-300' }}">
                                    {{ $school->currency }}{{ number_format((float) $invoice->balance, 2) }}
                                </td>

                                <td><x-status-pill :status="$invoice->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-10 text-center text-sm text-muted">
                                    No fees have been raised against this student yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
                <h2 class="font-display text-base font-semibold text-ink">Payments</h2>

                {{-- The list is the last twenty rather than all of them: a family paying
                     in small amounts over three years would otherwise bury the page. --}}
                @if ($payments->isNotEmpty())
                    <p class="text-xs text-muted">Last {{ $payments->count() }}</p>
                @endif
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Paid</th>
                            <th>Method</th>
                            <th>Against</th>
                            <th class="text-right">Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($payments as $payment)
                            <tr>
                                <td class="font-mono text-xs font-semibold text-ink">
                                    {{ $payment->receipt_number ?? '—' }}
                                </td>

                                <td class="text-sm">{{ $payment->paid_at?->format('j M Y') ?? '—' }}</td>

                                <td class="text-sm">{{ $payment->methodLabel() }}</td>

                                <td class="font-mono text-xs text-muted">
                                    {{ $payment->invoice?->invoice_number ?? '—' }}
                                </td>

                                <td class="text-right text-sm font-medium text-ink">
                                    {{ $school->currency }}{{ number_format((float) $payment->amount, 2) }}
                                </td>

                                <td><x-status-pill :status="$payment->status" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-10 text-center text-sm text-muted">
                                    Nothing has been paid in against this student yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Results are only worth a table once there are some. A student admitted
             last week has none, and an empty one would say the same thing twice. --}}
        @if ($student->termResults->isNotEmpty())
            <div class="card">
                <div class="border-b border-line px-5 py-4">
                    <h2 class="font-display text-base font-semibold text-ink">Term results</h2>
                </div>

                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Term</th>
                                <th>Session</th>
                                <th class="text-center">Subjects</th>
                                <th class="text-right">Average</th>
                                <th class="text-center">Position</th>
                                <th>Grade</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($student->termResults as $result)
                                <tr>
                                    <td class="text-sm font-medium text-ink">{{ $result->term?->name ?? '—' }}</td>
                                    <td class="text-sm">{{ $result->academicSession?->name ?? '—' }}</td>
                                    <td class="text-center text-sm">{{ $result->subjects_count ?: '—' }}</td>
                                    <td class="text-right text-sm">
                                        {{ $result->average !== null ? rtrim(rtrim(number_format((float) $result->average, 2), '0'), '.') : '—' }}
                                    </td>
                                    <td class="text-center text-sm">
                                        {{ $result->position ?: '—' }}{{ $result->class_size ? ' of '.$result->class_size : '' }}
                                    </td>
                                    <td class="text-sm font-semibold text-ink">{{ $result->grade ?? '—' }}</td>
                                    <td><x-status-pill :status="$result->status" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- How they got from one year to the next. The student row says where they are
             now and nothing about how many times they got there, which is the question
             asked whenever an old record is being picked up again. --}}
        <div class="card">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-display text-base font-semibold text-ink">Promotion history</h2>
            </div>

            @if ($student->promotions->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-muted">
                    No decision has been recorded about this student at the end of a session yet.
                </p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>At the end of</th>
                                <th>From</th>
                                <th>To</th>
                                <th>Decision</th>
                                <th>Decided</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($student->promotions->sortByDesc('decided_at') as $promotion)
                                <tr>
                                    <td>
                                        <p class="text-sm font-medium text-ink">
                                            {{ $promotion->fromSession?->name ?? '—' }}
                                        </p>

                                        @if ($promotion->toSession)
                                            <p class="text-xs text-muted">into {{ $promotion->toSession->name }}</p>
                                        @endif
                                    </td>

                                    <td class="text-sm">{{ $promotion->fromClass?->name ?? '—' }}</td>

                                    {{-- A student who left or graduated has no class to go
                                         to, and a dash says that better than a blank. --}}
                                    <td class="text-sm">{{ $promotion->toClass?->name ?? '—' }}</td>

                                    <td>
                                        <span class="badge {{ $promotion->action->badge() }}">
                                            {{ $promotion->action->label() }}
                                        </span>
                                    </td>

                                    <td>
                                        <p class="text-sm">{{ $promotion->decided_at?->format('j M Y') ?? '—' }}</p>
                                        <p class="text-xs text-muted">{{ $promotion->decidedBy?->name ?? '—' }}</p>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @php
            // The examination belongs to the application rather than the student, so it
            // is read through them. A student who joined without sitting one — a
            // transfer in, say — simply has nothing here.
            $examScores = $student->applicant?->scores ?? collect();
        @endphp

        <div class="card">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-display text-base font-semibold text-ink">Examination results</h2>
                <p class="mt-0.5 text-xs text-muted">The entrance examination they came in on</p>
            </div>

            @if ($examScores->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-muted">
                    {{ $student->applicant
                        ? 'No examination scores were captured for this student.'
                        : 'This student has no application on file, so there is no entrance examination behind them.' }}
                </p>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th class="text-right">Score</th>
                                <th class="text-right">Out of</th>
                                <th class="text-right">Percentage</th>
                                <th>Grade</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($examScores as $score)
                                <tr>
                                    <td class="font-medium text-ink">{{ $score->examSubject?->subject?->name ?? '—' }}</td>

                                    <td class="text-right font-medium">
                                        {{ $score->is_absent ? 'Absent' : ($score->score !== null ? rtrim(rtrim(number_format((float) $score->score, 2), '0'), '.') : '—') }}
                                    </td>

                                    <td class="text-right text-sm text-muted">
                                        {{ rtrim(rtrim(number_format((float) ($score->examSubject?->total_marks ?? 0), 2), '0'), '.') }}
                                    </td>

                                    <td class="text-right text-sm">
                                        {{ $score->percentage() !== null ? rtrim(rtrim(number_format($score->percentage(), 2), '0'), '.').'%' : '—' }}
                                    </td>

                                    <td class="text-sm font-semibold text-ink">{{ $score->grade ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ================= Their details ================= --}}
    <div class="space-y-6">
        {{-- The one thing on this page that has to be added by hand — names and
             numbers arrive with the class list, faces do not. It lives on the record
             rather than on the application they came in on because it outlives the
             admission: this is the face the results sheet and the fee slip print. --}}
        <div class="card">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-display text-base font-semibold text-ink">Photograph</h2>
            </div>

            <div class="p-5">
                @if ($student->photo_path)
                    {{-- asset() rather than Storage::url(): the disk URL is built from
                         APP_URL, which is not the host the office actually browses on. --}}
                    <a href="{{ asset('storage/'.$student->photo_path) }}" target="_blank"
                       class="mx-auto block w-32 overflow-hidden rounded-xl ring-1 ring-line transition hover:ring-brand-400">
                        <img src="{{ asset('storage/'.$student->photo_path) }}"
                             alt="Photograph of {{ $student->full_name }}"
                             class="h-36 w-32 object-cover">
                    </a>
                @else
                    <div class="mx-auto flex h-36 w-32 items-center justify-center rounded-xl border border-dashed border-line bg-surface-2">
                        <span class="text-xs text-muted">No photograph</span>
                    </div>
                @endif

                @can('update', $student)
                    <form method="POST" action="{{ route('admin.students.photo.update', $student) }}"
                          enctype="multipart/form-data" class="mt-4">
                        @csrf

                        <label for="student-photo" class="sr-only">Photograph</label>
                        <input id="student-photo" name="photo" type="file" required
                               accept=".jpg,.jpeg,.png,.webp"
                               class="block w-full text-xs text-ink-soft file:mr-2 file:rounded-md file:border-0 file:bg-surface-3 file:px-2.5 file:py-1.5 file:text-xs">

                        @error('photo')
                            <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                        @enderror

                        <button type="submit" class="btn-secondary btn-sm mt-2 w-full">
                            {{ $student->photo_path ? 'Replace' : 'Upload' }}
                        </button>
                    </form>

                    @if ($student->photo_path)
                        {{-- The name is left out of the question on purpose: it would have
                             to be escaped into the script, and a name with an apostrophe in
                             it would break the page. --}}
                        <form method="POST" action="{{ route('admin.students.photo.destroy', $student) }}"
                              class="mt-1.5"
                              onsubmit="return confirm('Remove this student’s photograph?')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-ghost btn-sm w-full text-rose-600 dark:text-rose-400">
                                Remove
                            </button>
                        </form>
                    @endif
                @endcan
            </div>
        </div>

        <div class="card">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-display text-base font-semibold text-ink">Student</h2>
            </div>

            {{-- No email and no phone for the child: a pupil of this school has neither.
                 The parent's are in the block below, and those are what the school writes
                 to and texts. --}}
            <dl class="grid gap-x-6 gap-y-5 p-5">
                @foreach ([
                    ['Admission number', $student->student_number],
                    ['Registration number', $student->admission_number ?? '—'],
                    ['Class', $student->schoolClass?->name ?? 'Not yet placed'],
                    ['Year group', $student->level?->name ?? '—'],
                    ['Session admitted', $student->academicSession?->name ?? '—'],
                    ['Admitted on', $student->admitted_at?->format('j F Y') ?? '—'],
                    ['Admission average', $student->admission_average !== null
                        ? rtrim(rtrim(number_format((float) $student->admission_average, 2), '0'), '.').'%'
                        : '—'],
                    ['Date of birth', $student->date_of_birth?->format('j F Y') ?? '—'],
                    ['Gender', $student->gender?->label() ?? '—'],
                    ['Address', $student->address ?? '—'],
                ] as [$label, $value])
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ $label }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="card">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-display text-base font-semibold text-ink">Parent / guardian</h2>
            </div>

            <dl class="grid gap-x-6 gap-y-5 p-5">
                @foreach ([
                    ['Name', $student->guardian_name ?? '—'],
                    ['Phone', $student->guardian_phone ?? '—'],
                    ['Email', $student->guardian_email ?? '—'],
                ] as [$label, $value])
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-muted">{{ $label }}</dt>
                        <dd class="mt-1 text-sm text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        {{-- What the parent can reach with the number they were given. Worth showing
             here because it is the question the office is asked at the counter. --}}
        <div class="card">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-display text-base font-semibold text-ink">Portal access</h2>
            </div>

            <ul class="divide-y divide-line-soft">
                @foreach ([
                    ['Results portal', $student->results_portal_enabled],
                    ['Fees portal', $student->fees_portal_enabled],
                    ['Portal account', $student->user !== null],
                ] as [$label, $on])
                    <li class="flex items-center justify-between gap-4 px-5 py-3.5">
                        <span class="text-sm text-ink-soft">{{ $label }}</span>

                        <span @class([
                            'inline-flex items-center gap-2 text-xs font-semibold',
                            'text-emerald-700 dark:text-emerald-300' => $on,
                            'text-muted' => ! $on,
                        ])>
                            <span @class([
                                'h-2 w-2 shrink-0 rounded-full',
                                'bg-emerald-500 dark:bg-emerald-400' => $on,
                                'bg-ink-soft/50' => ! $on,
                            ])></span>
                            {{ $on ? 'On' : 'Off' }}
                        </span>
                    </li>
                @endforeach
            </ul>

            @if ($student->applicant)
                <div class="border-t border-line px-5 py-4">
                    <a href="{{ route('admin.applicants.show', $student->applicant) }}"
                       class="btn-ghost btn-sm">
                        <x-nav-icon name="eye" class="h-3.5 w-3.5" />
                        The application they came in on
                    </a>
                </div>
            @endif
        </div>
    </div>
</div>

@endsection
