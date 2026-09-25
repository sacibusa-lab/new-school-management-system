@extends('layouts.admin')

@section('title', 'Message templates')
@section('subtitle', 'The wording of every automatic text message')

@section('actions')
    <a href="{{ route('admin.sms.index') }}" class="btn-secondary btn-sm">Back to the log</a>
@endsection

@section('content')

<x-alert tone="info" class="mb-6">
    These messages are sent automatically. Anything in
    <code class="rounded bg-white/60 px-1 py-0.5 text-xs">{braces}</code> is swapped for the real value
    before sending — keep the braces exactly as they are, only change the words around them.
</x-alert>

<div class="space-y-6" x-data="{ open: @js($templates->first()->id ?? null) }">
    @foreach ($templates as $template)
        @php $default = $defaults[$template->key] ?? null; @endphp

        <div class="card overflow-hidden">
            {{-- Header row doubles as the toggle, so the page stays scannable. --}}
            <button type="button" @click="open = (open === {{ $template->id }} ? null : {{ $template->id }})"
                    class="flex w-full items-start gap-4 p-5 text-left transition hover:bg-slate-50">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-display text-base font-semibold text-slate-900">{{ $template->name }}</p>

                        @if (! $template->is_active)
                            <span class="badge bg-slate-100 text-slate-600 ring-slate-500/20">Switched off</span>
                        @endif
                    </div>

                    <p class="mt-1 text-sm text-slate-500">{{ $template->description }}</p>
                    <p class="mt-2 line-clamp-1 text-xs text-slate-400">{{ $template->body }}</p>
                </div>

                <svg class="mt-1 h-5 w-5 shrink-0 text-slate-400 transition-transform"
                     :class="open === {{ $template->id }} ? 'rotate-180' : ''"
                     fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                </svg>
            </button>

            <div x-show="open === {{ $template->id }}" x-cloak class="border-t border-slate-200 p-5">
                <form method="POST" action="{{ route('admin.sms.templates.update', $template) }}">
                    @csrf
                    @method('PUT')

                    @php $editing = old('_template') === $template->key; @endphp

                    <div class="grid gap-4 lg:grid-cols-3">
                        <div class="lg:col-span-1">
                            <x-field name="name" label="Name in the list"
                                     :value="$editing ? old('name') : $template->name" required />
                        </div>
                    </div>

                    <div class="mt-4">
                        <label for="body-{{ $template->id }}" class="label">Message</label>
                        <textarea id="body-{{ $template->id }}" name="body" rows="5" required
                                  class="input font-mono text-[13px]">{{ $editing ? old('body') : $template->body }}</textarea>
                        <p class="mt-1 text-xs text-slate-500">
                            {{ mb_strlen($template->body) }} characters — about
                            {{ max(1, (int) ceil(mb_strlen($template->body) / 160)) }} SMS part(s) per recipient.
                        </p>
                    </div>

                    <div class="mt-4">
                        <p class="label">This message understands</p>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($default['placeholders'] ?? [] as $placeholder)
                                <span class="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">
                                    {{ '{' . $placeholder . '}' }}
                                </span>
                            @endforeach
                        </div>
                        <p class="mt-2 text-xs text-slate-500">
                            Using a placeholder that is not listed here sends the literal text, so check the preview
                            before saving.
                        </p>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center gap-4">
                        <input type="hidden" name="_template" value="{{ $template->key }}">

                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="is_active" value="1"
                                   @checked($editing ? old('is_active') : $template->is_active)
                                   class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            Send this message automatically
                        </label>

                        <div class="ml-auto flex items-center gap-2">
                            @if ($default)
                                <button type="submit" class="btn-ghost btn-sm"
                                        form="reset-{{ $template->id }}"
                                        onclick="return confirm('Restore the original wording for “{{ $template->name }}”?')">
                                    Restore default
                                </button>
                            @endif

                            <button type="submit" class="btn-primary btn-sm">Save wording</button>
                        </div>
                    </div>
                </form>

                @if ($default)
                    {{-- A separate form, because a nested form is invalid HTML. --}}
                    <form id="reset-{{ $template->id }}" method="POST"
                          action="{{ route('admin.sms.templates.reset', $template) }}" class="hidden">
                        @csrf
                    </form>
                @endif
            </div>
        </div>
    @endforeach
</div>

@endsection
