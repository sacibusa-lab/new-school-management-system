@extends('layouts.admin')

@section('title', $applicant->full_name)
@section('subtitle', $applicant->registration_number . ' · ' . $applicant->status->label())

@section('actions')
    @can('admissions.update')
        <a href="{{ route('admin.applicants.edit', $applicant) }}" class="btn-secondary btn-sm">Edit</a>
    @endcan

    {{-- The registration slip is what the parent walks away with, so it is always
         offered, not just on the day they register. --}}
    <a href="{{ route('admin.applicants.slip', $applicant) }}" class="btn-secondary btn-sm">Registration slip</a>

    @can('admissions.letters')
        @if ($applicant->isAdmitted())
            <a href="{{ route('admin.applicants.letter', $applicant) }}" class="btn-primary btn-sm">
                Admission letter
            </a>
            <a href="{{ route('admin.applicants.letter.pdf', $applicant) }}" class="btn-ghost btn-sm">
                PDF
            </a>
        @endif
    @endcan
@endsection

@section('content')

{{-- ================= Progress ================= --}}
<div class="card-pad">
    <x-stepper
        :steps="['Registered', 'Exam scheduled', 'Exam completed', 'Cutoff applied', 'Admitted']"
        :current="$applicant->status->stage()" />
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">

    {{-- ================= Profile ================= --}}
    <div class="space-y-6 lg:col-span-2">
        <div class="card">
            <div class="flex flex-wrap items-center gap-4 border-b border-line p-5">
                <span class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-900 font-display text-lg font-semibold text-gold-300">
                    {{ $applicant->initials }}
                </span>

                <div class="min-w-0 flex-1">
                    <p class="font-display text-lg font-semibold text-ink">{{ $applicant->full_name }}</p>
                    <p class="mt-0.5 font-mono text-sm text-muted">{{ $applicant->registration_number }}</p>
                </div>

                <x-status-pill :status="$applicant->status" />
            </div>

            <dl class="grid gap-x-8 gap-y-5 p-5 sm:grid-cols-2">
                @foreach ([
                    ['Class applied for', $applicant->levelAppliedFor?->name ?? '—'],
                    ['Academic session', $applicant->academicSession?->name ?? '—'],
                    ['Date of birth', $applicant->date_of_birth?->format('j F Y') ?? '—'],
                    ['Gender', $applicant->gender?->label() ?? '—'],
                    ['Address', $applicant->address ?? '—'],
                    ['State / LGA', trim(($applicant->state ?? '—') . ($applicant->lga ? ' · ' . $applicant->lga : ''))],
                    ['Previous school', $applicant->previous_school ?? '—'],
                    // The parent is the account holder, so their contact details are
                    // the ones that matter here.
                    ['Parent / guardian', $applicant->guardian_name ?? '—'],
                    ['Parent phone', $applicant->guardian_phone ?? '—'],
                    ['Parent email', $applicant->guardian_email ?? '—'],
                    ['Guardian relationship', $applicant->guardian_relationship ?? '—'],
                ] as [$label, $value])
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-muted">{{ $label }}</dt>
                        <dd class="mt-1 text-sm text-ink-soft">{{ $value }}</dd>
                    </div>
                @endforeach

                {{-- The office form does not collect the applicant's own contact
                     details, so these are only listed when a record has them. --}}
                @foreach (array_filter([
                    ['Applicant phone', $applicant->phone],
                    ['Applicant email', $applicant->email],
                ], fn ($row) => filled($row[1])) as [$label, $value])
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-muted">{{ $label }}</dt>
                        <dd class="mt-1 text-sm text-ink-soft">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($applicant->admin_notes)
                <div class="border-t border-line bg-surface-2 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-muted">Office notes</p>
                    <p class="mt-1.5 text-sm text-ink-soft">{{ $applicant->admin_notes }}</p>
                </div>
            @endif
        </div>

        {{-- ================= Passport & documents ================= --}}
        @php
            $documents = is_array($applicant->documents) ? $applicant->documents : [];
        @endphp

        {{-- Always shown, even when empty: names arrive in a spreadsheet but
             photographs do not, so the applicants with no photograph are exactly
             the ones this card has to be usable for. --}}
        <div class="card">
            <div class="panel-header">
                <div>
                    <p class="panel-title">Passport and documents</p>
                    <p class="mt-0.5 text-xs text-muted">
                        Held against this applicant
                    </p>
                </div>

                @if ($documents !== [])
                    <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">
                        {{ count($documents) }} document(s)
                    </span>
                @endif
            </div>

            <div class="flex flex-wrap gap-5 p-5">
                {{-- Passport photograph --}}
                <div class="w-32 shrink-0">
                    @if ($applicant->photo_path)
                        {{-- asset() rather than Storage::url(): the disk URL is built from
                             APP_URL, which is not the host the office actually browses on. --}}
                        <a href="{{ asset('storage/' . $applicant->photo_path) }}" target="_blank"
                           class="block overflow-hidden rounded-xl ring-1 ring-line transition hover:ring-brand-400">
                            <img src="{{ asset('storage/' . $applicant->photo_path) }}"
                                 alt="Passport photograph of {{ $applicant->full_name }}"
                                 class="h-36 w-32 object-cover">
                        </a>
                        <p class="mt-2 text-center text-xs text-muted">Passport photograph</p>
                    @else
                        <div class="flex h-36 w-32 items-center justify-center rounded-xl border border-dashed border-line bg-surface-2">
                            <span class="text-xs text-muted">No photograph</span>
                        </div>
                    @endif

                    {{-- Names arrive in a spreadsheet, photographs do not, so this has
                         to be usable long after the applicant was registered. --}}
                    @can('admissions.update')
                        <form method="POST" action="{{ route('admin.applicants.photo.update', $applicant) }}"
                              enctype="multipart/form-data" class="mt-3">
                            @csrf

                            <label for="applicant-photo" class="sr-only">Passport photograph</label>
                            <input id="applicant-photo" name="photo" type="file" required
                                   accept=".jpg,.jpeg,.png,.webp"
                                   class="block w-full text-xs text-ink-soft file:mr-2 file:rounded-md file:border-0 file:bg-surface-3 file:px-2.5 file:py-1.5 file:text-xs">

                            @error('photo')
                                <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                            @enderror

                            <button type="submit" class="btn-secondary btn-sm mt-2 w-full">
                                {{ $applicant->photo_path ? 'Replace' : 'Upload' }}
                            </button>
                        </form>

                        @if ($applicant->photo_path)
                            <form method="POST" action="{{ route('admin.applicants.photo.destroy', $applicant) }}"
                                  class="mt-1.5"
                                  onsubmit="return confirm('Remove {{ $applicant->full_name }}\'s photograph?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-ghost btn-sm w-full text-rose-600 dark:text-rose-400">
                                    Remove
                                </button>
                            </form>
                        @endif
                    @endcan
                </div>

                {{-- Supporting documents --}}
                <div class="min-w-56 flex-1">
                    @if ($documents !== [])
                        <ul class="space-y-2">
                            @foreach ($documents as $document)
                                @php $path = $document['path'] ?? null; @endphp

                                <li class="flex items-center gap-3 rounded-xl border border-line p-3">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-ink-soft">
                                            {{ $document['name'] ?? 'Document' }}
                                        </p>

                                        @if (! empty($document['size']))
                                            <p class="text-xs text-muted">
                                                {{ number_format(((int) $document['size']) / 1024, 0) }} KB
                                            </p>
                                        @endif
                                    </div>

                                    @if ($path)
                                        <a href="{{ asset('storage/' . $path) }}" target="_blank"
                                           class="btn-ghost btn-sm">Open</a>
                                    @else
                                        <span class="text-xs text-muted">Missing</span>
                                    @endif

                                    @can('admissions.update')
                                        @if ($path)
                                            <form method="POST"
                                                  action="{{ route('admin.applicants.documents.destroy', $applicant) }}"
                                                  onsubmit="return confirm('Remove {{ addslashes($document['name'] ?? 'this document') }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="path" value="{{ $path }}">
                                                <button type="submit" class="btn-ghost btn-sm text-rose-600 dark:text-rose-400">Remove</button>
                                            </form>
                                        @endif
                                    @endcan
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    {{-- The papers arrive as paper, days after the child was registered
                         from a spreadsheet, so this has to be usable at any time. --}}
                    @can('admissions.update')
                        <form method="POST" action="{{ route('admin.applicants.documents.store', $applicant) }}"
                              enctype="multipart/form-data"
                              class="{{ $documents !== [] ? 'mt-4' : '' }} rounded-xl border border-dashed border-line bg-surface-2 p-3">
                            @csrf

                            <label for="applicant-documents" class="block text-xs font-semibold text-ink-soft">
                                Attach documents
                            </label>
                            <p class="mt-1 text-xs text-muted">
                                Birth certificate, testimonial, baptismal card — PDF, JPG, PNG or WEBP,
                                up to {{ (int) (\App\Services\Admissions\ApplicantDocumentService::MAX_KB / 1024) }} MB each.
                            </p>

                            <input id="applicant-documents" name="documents[]" type="file" multiple
                                   accept=".pdf,.jpg,.jpeg,.png,.webp"
                                   class="mt-2 block w-full text-xs text-ink-soft file:mr-2 file:rounded-md file:border-0 file:bg-surface file:px-2.5 file:py-1.5 file:text-xs">

                            @error('documents')
                                <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                            @enderror
                            @error('documents.*')
                                <p class="mt-1 text-xs font-medium text-rose-600 dark:text-rose-400">{{ $message }}</p>
                            @enderror

                            <button type="submit" class="btn-secondary btn-sm mt-2">Attach</button>
                        </form>
                    @endcan
                </div>
            </div>
        </div>

        {{-- ================= Scores ================= --}}
        <div class="card">
            <div class="panel-header">
                <div>
                    <p class="panel-title">Examination scores</p>
                    <p class="mt-0.5 text-xs text-muted">Marks captured against this applicant</p>
                </div>

                @if ($decision)
                    <div class="text-right">
                        <p class="font-display text-xl font-semibold text-ink">
                            {{ rtrim(rtrim(number_format((float) $decision->average_score, 2), '0'), '.') }}%
                        </p>
                        <p class="text-xs text-muted">
                            position {{ $decision->position ?? '—' }} of {{ $decision->subjects_offered ? '' : '' }}candidates
                        </p>
                    </div>
                @endif
            </div>

            @if ($applicant->scores->isEmpty())
                <p class="px-5 py-10 text-center text-sm text-muted">
                    No scores have been captured for this applicant yet.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th class="text-right">Score</th>
                                <th class="text-right">Out of</th>
                                <th class="text-right">Percentage</th>
                                <th>Grade</th>
                                <th>Source</th>
                                <th>Verified</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($applicant->scores as $score)
                                <tr>
                                    <td class="font-medium text-ink">{{ $score->examSubject?->subject?->name ?? '—' }}</td>

                                    <td class="text-right font-medium">
                                        {{ $score->is_absent ? 'Absent' : ($score->score !== null ? rtrim(rtrim(number_format((float) $score->score, 2), '0'), '.') : '—') }}
                                    </td>

                                    <td class="text-right text-sm text-muted">
                                        {{ rtrim(rtrim(number_format((float) ($score->examSubject?->total_marks ?? 0), 2), '0'), '.') }}
                                    </td>

                                    <td class="text-right text-sm">
                                        {{ $score->percentage() !== null ? rtrim(rtrim(number_format($score->percentage(), 2), '0'), '.') . '%' : '—' }}
                                    </td>

                                    <td class="text-sm">{{ $score->grade ?? '—' }}</td>

                                    <td><span class="badge {{ $score->source->badge() }}">{{ $score->source->label() }}</span></td>

                                    <td class="text-sm">
                                        @if ($score->isVerified())
                                            <span class="text-emerald-700 dark:text-emerald-300">Yes</span>
                                        @elseif ($score->needsVerification())
                                            <span class="badge bg-gold-50 dark:bg-gold-950/40 text-gold-700 dark:text-gold-300 ring-gold-600/20 dark:ring-gold-400/20">Check</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- ================= Sidebar ================= --}}
    <aside class="space-y-6">
        @if ($decision)
            <div class="card-pad">
                <h3 class="text-sm font-semibold text-ink">Admission decision</h3>

                <div class="mt-4 flex items-center justify-between">
                    <x-status-pill :status="$decision->decision" />
                    @if ($decision->is_auto)
                        <span class="text-xs text-muted">Automatic</span>
                    @elseif ($decision->decider)
                        <span class="text-xs text-muted">by {{ $decision->decider->name }}</span>
                    @endif
                </div>

                <dl class="mt-5 space-y-3 text-sm">
                    @foreach ([
                        ['Average', rtrim(rtrim(number_format((float) $decision->average_score, 2), '0'), '.') . '%'],
                        ['Cutoff', rtrim(rtrim(number_format((float) $decision->cutoff_mark, 2), '0'), '.') . '%'],
                        ['Margin', ($decision->margin() >= 0 ? '+' : '') . rtrim(rtrim(number_format($decision->margin(), 2), '0'), '.')],
                        ['Position', $decision->position ?? '—'],
                        ['Subjects passed', $decision->subjects_passed . ' / ' . $decision->subjects_offered],
                    ] as [$label, $value])
                        <div class="flex justify-between border-b border-line-soft pb-2.5 last:border-0">
                            <dt class="text-muted">{{ $label }}</dt>
                            <dd class="font-semibold text-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($decision->remarks)
                    <p class="mt-4 rounded-xl bg-surface-2 p-3.5 text-xs text-ink-soft ring-1 ring-line">
                        {{ $decision->remarks }}
                    </p>
                @endif
            </div>
        @endif

        @if ($applicant->student)
            <div class="card-pad bg-emerald-50/60">
                <h3 class="text-sm font-semibold text-emerald-900 dark:text-emerald-100">Transferred to student record</h3>

                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">Admission number</dt>
                        <dd class="mt-1 font-mono font-semibold text-emerald-950">{{ $applicant->student->student_number }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-300">Class</dt>
                        <dd class="mt-1 text-emerald-950">{{ $applicant->student->schoolClass?->name ?? 'Not assigned' }}</dd>
                    </div>
                </dl>

                <div class="mt-5 flex flex-wrap items-center gap-2">
                    <a href="{{ route('admin.students.show', $applicant->student) }}" class="btn-secondary btn-sm">Student record</a>

                    {{-- Deliberately not a link to the invoices screen yet: that module is
                         not built, so the link would 500. The count is still useful here. --}}
                    @php $invoiceCount = $applicant->student->invoices()->count(); @endphp

                    @if ($invoiceCount > 0)
                        <span class="badge bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-200 ring-brand-600/20">
                            {{ $invoiceCount }} invoice(s) raised
                        </span>
                    @else
                        <span class="badge bg-gold-50 dark:bg-gold-950/40 text-gold-700 dark:text-gold-300 ring-gold-600/20 dark:ring-gold-400/20">No invoice raised</span>
                    @endif
                </div>
            </div>
        @endif

        <div class="card-pad">
            <h3 class="text-sm font-semibold text-ink">Application timeline</h3>

            <ol class="mt-4 space-y-4 text-sm">
                <li class="flex gap-3">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span>
                    <span>
                        <span class="block font-medium text-ink-soft">Registered</span>
                        <span class="text-xs text-muted">{{ $applicant->submitted_at?->format('j M Y, g:ia') ?? $applicant->created_at->format('j M Y') }}</span>
                    </span>
                </li>

                @if ($applicant->admitted_at)
                    <li class="flex gap-3">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-emerald-500"></span>
                        <span>
                            <span class="block font-medium text-ink-soft">Admitted</span>
                            <span class="text-xs text-muted">{{ $applicant->admitted_at->format('j M Y, g:ia') }}</span>
                        </span>
                    </li>
                @else
                    <li class="flex gap-3">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-muted"></span>
                        <span class="text-muted">Admission decision pending</span>
                    </li>
                @endif
            </ol>
        </div>

        @can('admissions.delete')
            @if (! $applicant->student)
                <form method="POST" action="{{ route('admin.applicants.destroy', $applicant) }}"
                      onsubmit="return confirm('Delete this applicant record? This cannot be undone.')"
                      class="card-pad">
                    @csrf
                    @method('DELETE')

                    <h3 class="text-sm font-semibold text-ink">Delete record</h3>
                    <p class="mt-1.5 text-xs text-muted">
                        Only possible while the applicant has not been admitted.
                    </p>
                    <button type="submit" class="btn-danger btn-sm mt-4 w-full">Delete applicant</button>
                </form>
            @endif
        @endcan
    </aside>
</div>

@endsection
