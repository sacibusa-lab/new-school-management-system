@extends('layouts.admin')

@section('title', 'Text messages')
@section('subtitle', 'Everything the platform has sent, and what it is about to send')

@section('actions')
    @can('sms.send')
        <a href="{{ route('admin.sms.batch') }}" class="btn-primary btn-sm">Send a message</a>
    @endcan
@endsection

@section('content')

{{-- ================= Warnings that actually matter ================= --}}
@if (! $enabled)
    <x-alert tone="warning" class="mb-6">
        Sending is switched <strong>off</strong>. Nothing will leave the building until the
        <em>Text messages enabled</em> switch is turned on in Settings.
    </x-alert>
@elseif (! $configured)
    <x-alert tone="info" class="mb-6">
        No SMS gateway API key is set, so messages are being <strong>simulated</strong> — they are written to
        this log as “Simulated” and go nowhere. Add the key in Settings when you are ready to go live.
    </x-alert>
@endif

{{-- ================= Overview ================= --}}
<div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <x-stat-card label="Waiting to send" :value="number_format($stats['queued'])" hint="Run the outbox to push these out" />
    <x-stat-card label="Sent today" :value="number_format($stats['sent_today'])" hint="Counts simulated messages too" />
    <x-stat-card label="Failed" :value="number_format($stats['failed'])" hint="Can be retried individually" />
    <x-stat-card label="All time" :value="number_format($stats['total'])" hint="Every message ever logged" />
</div>

{{-- ================= Outbox ================= --}}
@if ($stats['queued'] > 0)
    @can('sms.send')
        <div class="card-pad mt-6 flex flex-wrap items-center gap-4">
            <div class="min-w-0 flex-1">
                <p class="font-display text-base font-semibold text-ink">
                    {{ number_format($stats['queued']) }} message(s) are queued
                </p>
                <p class="mt-1 text-sm text-muted">
                    Queued messages were written to the log but never handed to the gateway — usually because
                    this server has no background worker doing it automatically.
                </p>
            </div>

            <form method="POST" action="{{ route('admin.sms.flush') }}">
                @csrf
                <button type="submit" class="btn-primary btn-sm">Send queued messages now</button>
            </form>
        </div>
    @endcan
@endif

{{-- ================= Filters ================= --}}
<form method="GET" class="card-pad mt-6">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
            <x-field name="q" label="Search" placeholder="Phone number, message text or template"
                     :value="request('q')" />
        </div>

        <x-field name="status" label="Status" type="select"
                 placeholder-option="All statuses"
                 :value="request('status')"
                 :options="$statuses" />

        <x-field name="template" label="Message type" type="select"
                 placeholder-option="All message types"
                 :value="request('template')"
                 :options="$templates->pluck('name', 'key')->all()" />
    </div>

    <div class="mt-5 flex flex-wrap items-center gap-3">
        <button type="submit" class="btn-primary btn-sm">Apply filters</button>

        @if (request()->hasAny(['q', 'status', 'template']))
            <a href="{{ route('admin.sms.index') }}" class="btn-ghost btn-sm">Clear</a>
        @endif

        <p class="ml-auto text-xs text-muted">{{ number_format($logs->total()) }} message(s)</p>
    </div>
</form>

{{-- ================= Log ================= --}}
<div class="table-wrap mt-6">
    <table class="table">
        <thead>
            <tr>
                <th>Recipient</th>
                <th>Message</th>
                <th>Type</th>
                <th>Status</th>
                <th>Sent</th>
                <th></th>
            </tr>
        </thead>

        <tbody>
            @forelse ($logs as $log)
                <tr>
                    <td class="whitespace-nowrap font-mono text-xs text-ink">
                        {{ $log->internationalRecipient() }}
                        @if ($log->user)
                            <p class="mt-1 font-sans text-[11px] text-muted">by {{ $log->user->name }}</p>
                        @endif
                    </td>

                    <td class="max-w-md">
                        <p class="text-sm text-ink-soft">{{ $log->body }}</p>

                        @if ($log->error)
                            <p class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ $log->error }}</p>
                        @endif
                    </td>

                    <td class="text-xs text-muted">
                        {{ $log->template_key ? \App\Support\SmsTemplateKey::label($log->template_key) : 'One-off' }}
                    </td>

                    <td><x-status-pill :status="$log->status" /></td>

                    <td class="whitespace-nowrap text-xs text-muted">
                        {{ $log->sent_at?->format('j M Y, H:i') ?? '—' }}
                        <p class="mt-1 text-[11px] text-muted">logged {{ $log->created_at->diffForHumans() }}</p>
                    </td>

                    <td class="text-right">
                        @can('sms.send')
                            @unless ($log->status->isDelivered())
                                <form method="POST" action="{{ route('admin.sms.resend', $log) }}">
                                    @csrf
                                    <button type="submit" class="btn-ghost btn-sm">Retry</button>
                                </form>
                            @endunless
                        @endcan
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="py-16 text-center">
                        <p class="text-sm font-medium text-ink">Nothing has been sent yet</p>
                        <p class="mt-1 text-sm text-muted">
                            Messages appear here automatically when applicants apply, are admitted or pay fees.
                        </p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-5">{{ $logs->links() }}</div>

@endsection
