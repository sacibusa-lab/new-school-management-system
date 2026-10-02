@php
    // A card per teacher, two to a row and four to a sheet, in the same arrangement as
    // the examination's admit cards. The passwords are shown once and stored nowhere,
    // so this page is the only place they exist — and a sheet the office prints and
    // cuts up travels further than a table somebody has to copy out by hand.

    // Where a teacher goes to sign in. The office's own address if it has set one, and
    // otherwise wherever this installation answers. Shown without the scheme, because
    // "https://" is something a browser adds rather than something anybody types — and
    // pointed at the sign-in page rather than the school's website, because that is the
    // one door a card with a password on it is for.
    $signInUrl = preg_replace('#^https?://#', '', rtrim((string) $school->website, '/')).'/login';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passwords to give out</title>
    <meta name="robots" content="noindex">
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .no-print { display: none !important; }
            @page { margin: 10mm; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 print:bg-white">

<div class="mx-auto max-w-5xl p-6 print:max-w-none print:p-0">

    <div class="no-print mb-5 flex flex-wrap items-center gap-3">
        @if ($credentials !== [])
            <button type="button" onclick="window.print()" class="btn-primary btn-sm">
                Print {{ count($credentials) }} card(s)
            </button>
        @endif

        <a href="{{ route('admin.students-results.teachers.list') }}" class="btn-ghost btn-sm">
            &larr; Back to the register
        </a>
    </div>

    <div class="mb-4 text-center">
        <h1 class="font-display text-lg font-semibold">{{ $school->name }}</h1>
        <p class="text-sm font-semibold">Passwords to give out</p>
        <p class="text-xs text-slate-500">
            Staff sign-in cards
            @if ($academicSession)
                · {{ $academicSession->name }}
            @endif
            · printed {{ now()->format('j F Y') }}
        </p>
    </div>

    @if ($credentials === [])
        <div class="card-pad text-center">
            <p class="text-sm font-medium text-slate-900">Nothing left to hand out</p>
            <p class="mx-auto mt-1 max-w-xl text-sm text-slate-500">
                These passwords are shown once — when the accounts are made, and when one is
                re-issued — and none of them are stored, so the page cannot be opened again.
                If one has gone missing, issue a new one from the register.
            </p>
            <a href="{{ route('admin.students-results.teachers.list') }}" class="btn-primary btn-sm mt-4">Go to the register</a>
        </div>
    @else
        {{-- Kept off the printout: the sheet is cut up and handed to teachers, and this
             is a note to the office, not to them. --}}
        <div class="no-print mb-4 rounded-lg border border-gold-300 bg-gold-50 px-4 py-3 text-sm text-slate-700">
            This is the only time these will be shown — nothing is stored, and a refresh or a
            bookmark finds nothing. Print the sheet now, then cut the cards apart.
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($credentials as $credential)
                @php
                    $initials = strtoupper(
                        collect(preg_split('/\s+/', trim($credential['name'])))
                            ->filter()
                            ->take(2)
                            ->map(fn ($word) => substr($word, 0, 1))
                            ->implode('')
                    );
                @endphp

                {{-- Every card has to stand on its own once the sheet is cut up, so each
                     one carries the school's own crest and the teacher's own details.
                     The password is the largest thing on it, because that is the part
                     that gets typed. --}}
                <article class="card flex items-stretch gap-4 p-4 print:break-inside-avoid print:rounded-lg">
                    <span class="inline-flex h-24 w-20 shrink-0 items-center justify-center rounded-lg bg-brand-900 font-display text-lg font-semibold text-gold-300">
                        {{ $initials ?: 'SAC' }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400">
                            Staff sign-in card
                        </p>
                        {{-- Allowed to wrap rather than clipped: the sheet is cut up,
                             so a shortened name leaves a card nobody can be sure of. --}}
                        <p class="mt-0.5 break-words font-display text-base font-semibold text-slate-900">
                            {{ $credential['name'] }}
                        </p>
                        <p class="font-mono text-xs text-slate-500">
                            {{ $credential['phone'] ?: 'no number on file' }}
                        </p>

                        <dl class="mt-2.5 grid grid-cols-2 gap-x-4 gap-y-1.5 text-slate-600">
                            <div class="col-span-2">
                                <dt class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Password</dt>
                                <dd class="font-mono text-xl font-semibold tracking-[0.3em] text-slate-900">{{ $credential['password'] }}</dd>
                            </div>

                            {{-- The address is the thing that gets a teacher from holding
                                 this card to being signed in, so it is given a line of its
                                 own and set as large as the password. --}}
                            <div class="col-span-2">
                                <dt class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Sign in at</dt>
                                <dd class="font-mono text-[13px] font-semibold break-all text-slate-900">{{ $signInUrl }}</dd>
                            </div>

                            <div>
                                <dt class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Sign in with</dt>
                                <dd class="text-[11px] font-medium">Phone number</dd>
                            </div>
                            <div>
                                <dt class="text-[9px] font-semibold uppercase tracking-wider text-slate-400">Change it</dt>
                                <dd class="text-[11px] font-medium">Any time, from your profile</dd>
                            </div>
                        </dl>

                        <p class="mt-2 text-[9px] text-slate-400">
                            Keep this card. The password stays yours until you change it.
                        </p>
                    </div>

                    <div class="flex shrink-0 flex-col items-center justify-center border-l border-slate-200 pl-4">
                        <x-brand-mark size="xl" />
                    </div>
                </article>
            @endforeach
        </div>

        <p class="mt-4 text-center text-xs text-slate-500">
            Each teacher signs in with their phone number and the password on their card.
            If one is lost, issue a new one from the register.
        </p>
    @endif
</div>

</body>
</html>
