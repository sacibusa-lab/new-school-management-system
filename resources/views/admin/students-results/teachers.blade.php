@extends('layouts.admin')

{{-- No subtitle: the breadcrumb in the header already says where this sits. --}}
@section('title', 'Teachers')

@section('content')
    {{--
        Teachers is a section rather than a single screen: the register of the
        people who teach, and the form for taking another one on. The two are drawn
        from the controller's own list — the same one the sidebar uses — so adding a
        third cannot leave one of the two behind.

        It is deliberately not Staff & roles. That page manages logins and what each
        one may do; this one is the school's list of its teachers, which is what a
        class has to be given one of.
    --}}
    <div class="card overflow-hidden">
        <div class="border-b border-line bg-surface-2 px-5 py-4">
            <p class="font-display text-base font-semibold text-ink">The people who teach</p>
            <p class="mt-1 text-sm text-muted">
                Who is on the teaching staff, which classes each one is class teacher of, and the
                account they sign in with. Non-teaching staff are not here — they are logins rather
                than teachers, and they belong with the rest of the staff accounts.
            </p>
        </div>

        <ul class="divide-y divide-line">
            @foreach ($children as $child)
                <li>
                    <a href="{{ route($child['route']) }}"
                       class="flex items-start gap-4 px-5 py-4 transition-colors hover:bg-surface-2">
                        <span class="mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-surface-3 text-muted">
                            <x-nav-icon :name="$child['icon']" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block font-medium text-ink">{{ $child['label'] }}</span>
                            <span class="mt-0.5 block text-sm text-muted">{{ $child['note'] }}</span>
                        </span>

                        <x-nav-icon name="chevron-right" class="mt-1.5 h-4 w-4 shrink-0 text-muted" />
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endsection
