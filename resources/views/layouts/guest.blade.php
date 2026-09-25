<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">

    <title>@yield('title', $title ?? 'Sign in') · {{ $school->name }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="grid min-h-full grid-cols-1 lg:grid-cols-2">

    {{-- Left: form --}}
    <div class="flex flex-col justify-center px-5 py-12 sm:px-10 lg:px-16">
        <div class="mx-auto w-full max-w-sm">
            <a href="{{ route('home') }}" class="inline-flex items-center gap-3">
                <x-brand-mark />
                <span class="font-display text-base font-semibold text-slate-900">{{ $school->name }}</span>
            </a>

            <div class="mt-10">
                {{ $slot ?? '' }}
                @yield('content')
            </div>

            <p class="mt-10 text-center text-xs text-slate-500">
                <a href="{{ route('home') }}" class="transition hover:text-slate-700">&larr; Back to the school website</a>
            </p>
        </div>
    </div>

    {{-- Right: brand panel --}}
    <div class="relative hidden overflow-hidden bg-brand-950 lg:block">
        <div class="absolute inset-0 hero-mesh opacity-90"></div>
        <div class="absolute inset-0 grid-lines opacity-40"></div>

        <div class="relative flex h-full flex-col justify-center px-16">
            <p class="font-display text-4xl leading-tight font-semibold text-white text-balance">
                One sign-in.<br>
                Admissions, results and fees.
            </p>
            <p class="mt-6 max-w-md text-brand-200">
                Staff manage applicants, examinations and fee records from a single dashboard.
                Students and parents use the same door to see results and outstanding balances.
            </p>

            <dl class="mt-12 grid max-w-md grid-cols-1 gap-6 sm:grid-cols-3">
                @foreach ([
                    ['label' => 'Admissions', 'value' => 'SAC-00001'],
                    ['label' => 'Results', 'value' => 'Term reports'],
                    ['label' => 'Fees', 'value' => 'Invoice & receipts'],
                ] as $item)
                    <div class="rounded-2xl bg-white/5 p-4 ring-1 ring-white/10 backdrop-blur">
                        <dt class="text-xs font-semibold uppercase tracking-wider text-gold-300">{{ $item['label'] }}</dt>
                        <dd class="mt-1 text-sm text-brand-100">{{ $item['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>
</body>
</html>
