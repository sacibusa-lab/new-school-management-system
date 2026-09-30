<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">

    <title>@yield('title', $title ?? 'Dashboard') · {{ $school->name }}</title>

    @if ($school->favicon)
        <link rel="icon" href="{{ asset('storage/' . $school->favicon) }}">
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials.theme')
    @stack('head')
</head>
<body class="h-full bg-surface-2" x-data="{ sidebar: false }">

@php
    // Named once, in a block rather than as @php(...): the inline form of this
    // directive compiles to an opening tag PHP does not recognise, which swallows
    // the markup after it and turns the whole page into an undefined variable.
    $menu = \App\Support\AdminMenu::class;

    // Blade escapes a section's content when it is defined with
    // @section('title', 'value'), so the page title arrives here already escaped
    // once. Decoded and escaped again where it is printed, rather than printed as
    // it arrives and doubled — "Classes & Sections" came out as "Classes &amp;
    // Sections" on the page and in the browser tab.
    $pageTitle = trim(html_entity_decode((string) $__env->yieldContent('title')));
@endphp

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
                // The menu itself lives in App\Support\AdminMenu, because the trail
                // above a page is derived from it: drawn from one place, read from
                // two, so the sidebar and the breadcrumb cannot disagree.
                $sections = $menu::sections();
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
                                @php
                                    // Second-level pages inherit the parent's permission
                                    // when they do not name one of their own.
                                    $children = collect($link['children'] ?? [])
                                        ->filter(fn (array $child) => auth()->user()?->can($child['can'] ?? $link['can']));
                                @endphp

                                <li>
                                    <a href="{{ $menu::href($link) }}"
                                       @class([
                                           'group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
                                           'bg-white/10 text-white ring-1 ring-white/15' => $menu::isActive($link),
                                           'text-brand-200 hover:bg-white/5 hover:text-white' => ! $menu::isActive($link),
                                       ])>
                                        <x-nav-icon :name="$link['icon']" />
                                        {{ $link['label'] }}
                                    </a>

                                    {{-- Shown only while you are inside the section, so the
                                         sidebar stays the length the office judged it to be.
                                         The child pages are reached by opening the parent. --}}
                                    @if ($children->isNotEmpty() && $menu::isActive($link))
                                        <ul class="mt-0.5 ml-5 space-y-0.5 border-l border-white/10 pl-3">
                                            @foreach ($children as $child)
                                                <li>
                                                    <a href="{{ route($child['route']) }}"
                                                       @class([
                                                           'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors',
                                                           'text-white' => request()->routeIs($child['route']),
                                                           'text-brand-300 hover:bg-white/5 hover:text-white' => ! request()->routeIs($child['route']),
                                                       ])>
                                                        <x-nav-icon :name="$child['icon']" class="h-4 w-4" />
                                                        {{ $child['label'] }}
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
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

        <header class="sticky top-0 z-30 flex h-18 shrink-0 items-center gap-4 border-b border-line bg-surface/85 px-4 backdrop-blur-lg sm:px-6 lg:px-8">
            <button type="button" @click="sidebar = true" class="btn-ghost -ml-2 p-2 lg:hidden" aria-label="Open menu">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
            </button>

            <div class="min-w-0 flex-1">
                {{-- Read from the section, not a $title variable: every page sets
                     @section('title', ...) and none of them pass $title. Decoded
                     once above, escaped once here. --}}
                <p class="truncate text-sm font-semibold text-ink">
                    {{ $pageTitle ?: 'Dashboard' }}
                </p>

                @hasSection('subtitle')
                    <p class="truncate text-xs text-muted">@yield('subtitle')</p>
                @endif
            </div>

            @hasSection('actions')
                <div class="hidden items-center gap-2 sm:flex">@yield('actions')</div>
            @endif

            <x-theme-toggle />

            <div x-data="{ open: false }" class="relative">
                <button type="button" @click="open = ! open" @click.outside="open = false"
                        class="flex items-center gap-2.5 rounded-xl p-1.5 pr-2.5 transition hover:bg-surface-3">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-brand-900 text-xs font-semibold text-gold-300">
                        {{ auth()->user()->initials }}
                    </span>
                    <span class="hidden text-left sm:block">
                        <span class="block text-xs font-semibold text-ink">{{ auth()->user()->name }}</span>
                        <span class="block text-[11px] text-muted">{{ auth()->user()->primaryRole() }}</span>
                    </span>
                    <svg class="h-4 w-4 text-muted" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>

                <div x-show="open" x-cloak x-transition.origin.top.right
                     class="absolute right-0 z-50 mt-2 w-56 overflow-hidden rounded-xl border border-line bg-surface py-1 shadow-float">
                    <div class="border-b border-line-soft px-4 py-3">
                        <p class="text-sm font-semibold text-ink">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-muted">{{ auth()->user()->email }}</p>
                    </div>
                    <a href="{{ route('profile.edit') }}" class="block px-4 py-2.5 text-sm text-ink-soft transition hover:bg-surface-2">My profile</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="block w-full px-4 py-2.5 text-left text-sm text-rose-600 dark:text-rose-400 transition hover:bg-rose-50 dark:bg-rose-950/40">
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        </header>

        <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
            {{-- Where this page sits. Read from the menu rather than declared per
                 page, so it is right on every page that the menu holds and simply
                 absent on the few it does not. --}}
            <x-breadcrumb class="mb-5"
                          :trail="$menu::trailFor(request()->route()?->getName(), $pageTitle)" />

            @if (session('status'))
                <div class="mb-6"><x-alert tone="success">{{ session('status') }}</x-alert></div>
            @endif

            @if (session('error'))
                <div class="mb-6"><x-alert tone="danger">{{ session('error') }}</x-alert></div>
            @endif

            {{-- Between the two: something to look at, not something that failed. --}}
            @if (session('warning'))
                <div class="mb-6"><x-alert tone="warning" title="Check this">{{ session('warning') }}</x-alert></div>
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
