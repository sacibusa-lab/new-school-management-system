<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', $title ?? 'Home') · {{ $school->name }}</title>
    {{-- Kept neutral about *how* admission is applied for, because the public form
         is not always open. --}}
    <meta name="description" content="{{ $description ?? $school->name . ' — ' . $school->tagline . '. Admission status, examination results and school fees, in one place for parents and students.' }}">

    @if ($school->favicon)
        <link rel="icon" href="{{ asset('storage/' . $school->favicon) }}">
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.theme')
    @stack('head')
</head>
<body class="flex min-h-screen flex-col bg-surface print:min-h-0">

    {{-- ================= Announcement bar ================= --}}
    @if (! empty($announcement))
        <div class="bg-brand-950 px-4 py-2.5 text-center text-sm text-brand-100 print:hidden">
            {!! $announcement !!}
        </div>
    @endif

    {{-- ================= Header ================= --}}
    {{-- print:hidden — a parent printing their record wants the record, not the
         navigation and the "apply now" button that go with it. --}}
    <header x-data="{ open: false, scrolled: false }"
            @scroll.window="scrolled = window.scrollY > 8"
            :class="scrolled ? 'shadow-lg shadow-slate-900/5' : ''"
            class="sticky top-0 z-40 border-b border-line/80 bg-surface/85 backdrop-blur-lg transition-shadow print:hidden">
        <div class="section flex h-18 items-center justify-between gap-6 py-3">

            <a href="{{ route('home') }}" class="flex items-center gap-3">
                <x-brand-mark />
                <span class="leading-tight">
                    <span class="block font-display text-base font-semibold text-ink">{{ $school->name }}</span>
                    <span class="hidden text-xs text-muted sm:block">{{ $school->tagline }}</span>
                </span>
            </a>

            <nav class="hidden items-center gap-1 lg:flex">
                @foreach ([
                    ['route' => 'home', 'label' => 'Home'],
                    ['route' => 'public.status', 'label' => 'Admission Status'],
                    ['route' => 'public.results', 'label' => 'Check Result'],
                    ['route' => 'public.fees', 'label' => 'Fee Status'],
                ] as $item)
                    @php $active = request()->routeIs($item['route']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'rounded-lg px-3.5 py-2 text-sm font-medium transition-colors',
                           'bg-brand-50 dark:bg-brand-900/30 text-brand-800 dark:text-brand-200' => $active,
                           'text-ink-soft hover:bg-surface-3 hover:text-ink' => ! $active,
                       ])>{{ $item['label'] }}</a>
                @endforeach
            </nav>

            <div class="hidden items-center gap-3 lg:flex">
                <x-theme-toggle />

                @auth
                    <a href="{{ route(auth()->user()->homeRoute()) }}" class="btn-secondary btn-sm">Dashboard</a>
                @else
                    <a href="{{ route('login') }}" class="btn-ghost btn-sm">Staff login</a>
                @endauth

                {{-- Online application is switched off by default: the office registers
                     candidates. When that changes, this button comes back with it. --}}
                @if ($registrationOpen)
                    <a href="{{ route('public.register') }}" class="btn-gold btn-sm">
                        Apply for admission
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/>
                        </svg>
                    </a>
                @else
                    <a href="{{ route('public.status') }}" class="btn-gold btn-sm">Check admission status</a>
                @endif
            </div>

            <button type="button"
                    @click="open = ! open"
                    class="btn-ghost -mr-2 p-2 lg:hidden"
                    :aria-expanded="open.toString()"
                    aria-label="Toggle navigation">
                <svg x-show="! open" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
                <svg x-show="open" x-cloak class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        {{-- Mobile menu --}}
        <div x-show="open" x-cloak x-transition.origin.top
             class="border-t border-line bg-surface lg:hidden">
            <div class="section space-y-1 py-4">
                @foreach ([
                    ['route' => 'home', 'label' => 'Home'],
                    ['route' => 'public.status', 'label' => 'Admission Status'],
                    ['route' => 'public.results', 'label' => 'Check Result'],
                    ['route' => 'public.fees', 'label' => 'Fee Status'],
                ] as $item)
                    <a href="{{ route($item['route']) }}" class="nav-link">{{ $item['label'] }}</a>
                @endforeach

                <div class="flex items-center gap-2 pt-2">
                    <x-theme-toggle />
                    <span class="text-sm text-muted">Light or dark</span>
                </div>

                <div class="flex gap-3 pt-3">
                    @auth
                        <a href="{{ route(auth()->user()->homeRoute()) }}" class="btn-secondary flex-1">Dashboard</a>
                    @else
                        <a href="{{ route('login') }}" class="btn-secondary flex-1">Staff login</a>
                    @endauth

                    @if ($registrationOpen)
                        <a href="{{ route('public.register') }}" class="btn-gold flex-1">Apply now</a>
                    @else
                        <a href="{{ route('public.status') }}" class="btn-gold flex-1">Check admission status</a>
                    @endif
                </div>
            </div>
        </div>
    </header>

    {{-- ================= Main ================= --}}
    <main class="flex-1">
        @if (session('status'))
            <div class="section pt-6">
                <x-alert tone="success">{{ session('status') }}</x-alert>
            </div>
        @endif

        {{-- Error flashes were being swallowed on the public site, so a redirect
             with a reason looked like nothing had happened. --}}
        @if (session('error'))
            <div class="section pt-6">
                <x-alert tone="danger">{{ session('error') }}</x-alert>
            </div>
        @endif

        {{ $slot ?? '' }}
        @yield('content')
    </main>

    {{-- ================= Footer ================= --}}
    <footer class="mt-24 bg-brand-950 text-brand-100 print:hidden">
        <div class="section grid gap-10 py-14 md:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <div class="flex items-center gap-3">
                    <x-brand-mark size="lg" />
                    <div>
                        <p class="font-display text-lg font-semibold text-white">{{ $school->name }}</p>
                        <p class="text-sm text-brand-300">{{ $school->tagline }}</p>
                    </div>
                </div>
                <p class="mt-5 max-w-md text-sm leading-relaxed text-brand-200">
                    A single portal for admission, examination results and school fees — so parents and
                    students never have to visit three different websites again.
                </p>
            </div>

            <div>
                <p class="text-sm font-semibold uppercase tracking-wider text-white">Portals</p>
                <ul class="mt-4 space-y-2.5 text-sm">
                    <li><a href="{{ route('public.status') }}" class="text-brand-200 transition hover:text-gold-300">Check admission status</a></li>
                    <li><a href="{{ route('public.results') }}" class="text-brand-200 transition hover:text-gold-300">Check result</a></li>
                    <li><a href="{{ route('public.fees') }}" class="text-brand-200 transition hover:text-gold-300">School fees</a></li>
                </ul>
            </div>

            <div>
                <p class="text-sm font-semibold uppercase tracking-wider text-white">Contact</p>
                <ul class="mt-4 space-y-2.5 text-sm text-brand-200">
                    @if ($school->address)<li>{{ $school->address }}</li>@endif
                    @if ($school->phone)<li>{{ $school->phone }}</li>@endif
                    @if ($school->email)<li>{{ $school->email }}</li>@endif
                    <li><a href="{{ route('login') }}" class="transition hover:text-gold-300">Staff &amp; student login</a></li>
                </ul>
            </div>
        </div>

        <div class="border-t border-white/10">
            <div class="section flex flex-col items-center justify-between gap-3 py-6 text-xs text-brand-300 sm:flex-row">
                <p>&copy; {{ now()->year }} {{ $school->name }}. All rights reserved.</p>
                <p>Admissions · Results · Fees — one account, three portals.</p>
            </div>
        </div>
    </footer>
</body>
</html>
