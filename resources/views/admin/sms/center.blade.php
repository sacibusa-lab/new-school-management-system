@extends('layouts.admin')

@section('title', 'SMS centre')
@section('subtitle', 'The one place for messaging — being built')

@section('actions')
    <a href="{{ route('admin.sms.index') }}" class="btn-secondary btn-sm">Text messages</a>
@endsection

@section('content')

@php
    $live = $configured && $enabled;
@endphp

{{-- A placeholder on purpose. Say so plainly rather than showing empty panels that
     look like something is broken. --}}
<div class="card-pad">
    <p class="eyebrow">Not built yet</p>
    <h2 class="mt-1 font-display text-lg font-semibold text-ink">This screen is reserved</h2>

    <p class="mt-2 max-w-3xl text-sm text-muted">
        The SMS centre is where messaging will be run from — sending, scheduling, delivery and
        credit, in one place. Nothing has been decided about it yet, so it is deliberately empty.
        Everything that works today is on the two screens below.
    </p>

    <div class="mt-6 flex flex-wrap gap-3">
        <a href="{{ route('admin.sms.index') }}" class="btn-primary btn-sm">Text messages</a>
        <a href="{{ route('admin.sms.batch') }}" class="btn-secondary btn-sm">Send a message</a>
        <a href="{{ route('admin.sms.templates') }}" class="btn-secondary btn-sm">Templates</a>
    </div>
</div>

{{-- The connection is worth showing now: it is what anything built here will sit
     on, and it is the first thing to check when a message does not arrive. --}}
<div class="mt-6 grid gap-6 lg:grid-cols-3">

    <div class="lg:col-span-2">
        <div class="card">
            <div class="panel-header">
                <div>
                    <p class="panel-title">Connection</p>
                    <p class="mt-0.5 text-xs text-muted">
                        Read from Settings, so the school can change it without touching the server.
                    </p>
                </div>

                @if ($live)
                    <span class="badge bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 ring-emerald-600/20 dark:ring-emerald-400/20">Ready to send</span>
                @else
                    <span class="badge bg-gold-50 dark:bg-gold-950/40 text-gold-700 dark:text-gold-300 ring-gold-600/20 dark:ring-gold-400/20">Not sending yet</span>
                @endif
            </div>

            <dl class="divide-y divide-line-soft text-sm">
                <div class="flex items-center justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Gateway</dt>
                    <dd class="font-medium text-ink">{{ ucfirst($provider) }}</dd>
                </div>

                <div class="flex items-center justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">API key</dt>
                    <dd>
                        @if ($configured)
                            <span class="font-medium text-emerald-700 dark:text-emerald-300">Set</span>
                        @else
                            <span class="font-medium text-rose-700 dark:text-rose-300">Not set</span>
                        @endif
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Sending</dt>
                    <dd>
                        @if ($enabled)
                            <span class="font-medium text-emerald-700 dark:text-emerald-300">Switched on</span>
                        @else
                            <span class="font-medium text-rose-700 dark:text-rose-300">Switched off</span>
                        @endif
                    </dd>
                </div>

                <div class="flex items-center justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Sender ID</dt>
                    <dd class="font-mono text-xs font-medium text-ink">{{ $senderId ?: '—' }}</dd>
                </div>

                <div class="flex items-center justify-between gap-4 px-5 py-3">
                    <dt class="text-muted">Channel</dt>
                    <dd class="font-medium text-ink">{{ $channel ?: '—' }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <aside class="space-y-6">
        <div @class(['card-pad', 'bg-rose-50/60' => ! $configured, 'bg-surface-2' => $configured])>
            <h3 class="text-sm font-semibold text-ink">
                {{ $configured ? 'Where this is set' : 'No API key yet' }}
            </h3>

            <p class="mt-2 text-sm text-ink-soft">
                @if ($configured)
                    The key, sender ID and channel live in Settings under <strong>API</strong>.
                    Changing them there takes effect immediately — nothing needs restarting.
                @else
                    Messages are being simulated, not sent. Add the Termii API key in Settings under
                    <strong>API</strong> to start sending for real.
                @endif
            </p>

            @can('settings.manage')
                <a href="{{ route('admin.settings.api') }}" class="btn-secondary btn-sm mt-4">
                    Open API settings
                </a>
            @endcan
        </div>

        <div class="card-pad bg-brand-50/60">
            <h3 class="text-sm font-semibold text-brand-900 dark:text-brand-100">What works today</h3>
            <p class="mt-2 text-sm text-brand-800 dark:text-brand-200">
                Registration, decisions and resit notices already go out by text, and the whole
                history is on the text messages screen. None of that waits on this screen.
            </p>
            <a href="{{ route('admin.sms.index') }}" class="btn-primary btn-sm mt-4">See what has been sent</a>
        </div>
    </aside>
</div>

@endsection
