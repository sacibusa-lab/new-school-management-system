<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">

    <title>@yield('title', $title ?? 'Dashboard') · {{ $school->name }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full bg-slate-100" x-data="{ sidebar: false }">

<div class="flex min-h-full">

    {{-- ================= Sidebar ================= --}}
    <aside x-cloak
           :class="sidebar ? 'translate-x-0' : '-translate-x-full'"
           class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col bg-brand-950 transition-transform duration-200 lg:static lg:translate-x-0">

        <div class="flex h-18 shrink-0 items-center justify-between gap-3 px-5">
            <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3">
                <x-brand-mark />
                <span class="leading-tight">
                    <span class="block font-display text-sm font-semibold text-white">{{ $school->name }}</span>
                    <span class="block text-[11px] uppercase tracking-wider text-brand-300">Control panel</span>
                </span>
            </a>

            <button type="button" @click="sidebar = false" class="text-brand-300 hover:text-white lg:hidden" aria-label="Close menu">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <nav class="flex-1 space-y-6 overflow-y-auto px-3 pb-6">
            @php
                // A `*` route becomes its .index URL; a plain name is used as-is,
                // so an entry can point at a create screen.
                $href = fn (array $link) => str_contains($link['route'], '*')
                    ? route(str_replace('.*', '.index', $link['route']))
                    : route($link['route']);

                // `matches` lets an item claim the exact routes it should light up
                // for, so Register does not also highlight Applicants.
                $isActive = fn (array $link) => request()->routeIs(...(array) ($link['matches'] ?? [$link['route']]));

                $sections = [
                    'Overview' => [
                        ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'icon' => 'home', 'can' => null],
                    ],
                    'Admissions' => [
                        ['route' => 'admin.applicants.*', 'label' => 'Applicants', 'icon' => 'users', 'can' => 'admissions.view',
                         'matches' => ['admin.applicants.index', 'admin.applicants.show', 'admin.applicants.edit']],

                        ['route' => 'admin.applicants.create', 'label' => 'Register applicant', 'icon' => 'user-plus', 'can' => 'admissions.create',
                         'matches' => ['admin.applicants.create', 'admin.applicants.import', 'admin.applicants.import.*']],

                        ['route' => 'admin.exams.*', 'label' => 'Examinations', 'icon' => 'clipboard', 'can' => 'exams.view'],
                        ['route' => 'admin.scores.*', 'label' => 'Score entry', 'icon' => 'pencil', 'can' => 'scores.enter'],
                        ['route' => 'admin.imports.*', 'label' => 'Scoresheet imports', 'icon' => 'upload', 'can' => 'scores.import'],
                        ['route' => 'admin.admissions.*', 'label' => 'Cutoff & decisions', 'icon' => 'scale', 'can' => 'admissions.decide'],
                    ],
                    'Students & Results' => [
                        ['route' => 'admin.students.*', 'label' => 'Students', 'icon' => 'academic', 'can' => 'students.view'],
                        ['route' => 'admin.results.*', 'label' => 'Results', 'icon' => 'chart', 'can' => 'results.view'],
                    ],
                    'Fees' => [
                        ['route' => 'admin.fees.categories.*', 'label' => 'Fee categories', 'icon' => 'tag', 'can' => 'fees.manage'],
                        ['route' => 'admin.fees.structures.*', 'label' => 'Fee structures', 'icon' => 'list', 'can' => 'fees.manage'],
                        ['route' => 'admin.invoices.*', 'label' => 'Invoices', 'icon' => 'receipt', 'can' => 'fees.view'],
                        ['route' => 'admin.payments.*', 'label' => 'Payments', 'icon' => 'cash', 'can' => 'fees.view'],
                    ],
                    'Communication' => [
                        ['route' => 'admin.sms.*', 'label' => 'Text messages', 'icon' => 'chat', 'can' => 'sms.view'],
                    ],
                    'Administration' => [
                        ['route' => 'admin.users.*', 'label' => 'Staff & roles', 'icon' => 'shield', 'can' => 'users.manage'],
                        ['route' => 'admin.settings.*', 'label' => 'Settings', 'icon' => 'cog', 'can' => 'settings.manage'],
                        ['route' => 'admin.activity.*', 'label' => 'Activity log', 'icon' => 'clock', 'can' => 'audit.view'],
                    ],
                ];
            @endphp

            @foreach ($sections as $heading => $links)
                @php
                    $visible = collect($links)->filter(fn ($l) => is_null($l['can']) || auth()->user()?->can($l['can']));
                @endphp

                @if ($visible->isNotEmpty())
                    <div>
                        <p class="px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-brand-400">{{ $heading }}</p>
                        <ul class="space-y-0.5">
                            @foreach ($visible as $link)
                                <li>
                                    <a href="{{ $href($link) }}"
                                       @class([
                                           'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                                           'bg-white/10 text-white ring-1 ring-white/15' => $isActive($link),
                                           'text-brand-200 hover:bg-white/5 hover:text-white' => ! $isActive($link),
                                       ])>
                                        <x-nav-icon :name="$link['icon']" />
                                        {{ $link['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="shrink-0 border-t border-white/10 p-3">
            <a href="{{ route('home') }}" target="_blank"
               class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-brand-300 transition hover:bg-white/5 hover:text-white">
                <x-nav-icon name="external" />
                View public website
            </a>
        </div>
    </aside>

    {{-- Mobile backdrop --}}
    <div x-show="sidebar" x-cloak @click="sidebar = false"
         x-transition.opacity
         class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-sm lg:hidden"></div>

    {{-- ================= Content ================= --}}
    <div class="flex min-w-0 flex-1 flex-col">

        <header class="sticky top-0 z-30 flex h-18 shrink-0 items-center gap-4 border-b border-slate-200 bg-white/85 px-4 backdrop-blur-lg sm:px-6 lg:px-8">
            <button type="button" @click="sidebar = true" class="btn-ghost -ml-2 p-2 lg:hidden" aria-label="Open menu">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
            </button>

            <div class="min-w-0 flex-1">
                {{-- Read from the section, not a $title variable: every page sets
                     @section('title', ...) and none of them pass $title. --}}
                <p class="truncate text-sm font-semibold text-slate-900">
                    {{ trim($__env->yieldContent('title')) ?: 'Dashboard' }}
                </p>
                @hasSection('subtitle')
                    <p class="truncate text-xs text-slate-500">@yield('subtitle')</p>
                @endif
            </div>

            @hasSection('actions')
                <div class="hidden items-center gap-2 sm:flex">@yield('actions')</div>
            @endif

            <div x-data="{ open: false }" class="relative">
                <button type="button" @click="open = ! open" @click.outside="open = false"
                        class="flex items-center gap-2.5 rounded-xl p-1.5 pr-2.5 transition hover:bg-slate-100">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand-900 text-xs font-semibold text-gold-300">
                        {{ auth()->user()->initials }}
                    </span>
                    <span class="hidden text-left sm:block">
                        <span class="block text-xs font-semibold text-slate-900">{{ auth()->user()->name }}</span>
                        <span class="block text-[11px] text-slate-500">{{ auth()->user()->primaryRole() }}</span>
                    </span>
                    <svg class="h-4 w-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>

                <div x-show="open" x-cloak x-transition.origin.top.right
                     class="absolute right-0 z-50 mt-2 w-56 overflow-hidden rounded-xl border border-slate-200 bg-white py-1 shadow-float">
                    <div class="border-b border-slate-100 px-4 py-3">
                        <p class="text-sm font-semibold text-slate-900">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="block px-4 py-2.5 text-sm text-slate-700 transition hover:bg-slate-50">My profile</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2.5 text-left text-sm text-rose-600 transition hover:bg-rose-50">
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-6"><x-alert tone="success">{{ session('status') }}</x-alert></div>
            @endif

            @if (session('error'))
                <div class="mb-6"><x-alert tone="danger">{{ session('error') }}</x-alert></div>
            @endif

            @if ($errors->any() && ! isset($suppressErrorSummary))
                <div class="mb-6">
                    <x-alert tone="danger" title="Please fix the following:">
                        <ul class="list-inside list-disc space-y-0.5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-alert>
                </div>
            @endif

            {{ $slot ?? '' }}
            @yield('content')
        </main>
    </div>
</div>

@stack('scripts')
</body>
</html>
