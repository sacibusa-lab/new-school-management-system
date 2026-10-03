@extends('layouts.admin')

@section('title', 'API settings')

@section('actions')
    <a href="{{ route('admin.settings.index') }}" class="btn-secondary btn-sm">
        <x-nav-icon name="cog" class="h-3.5 w-3.5" />
        All settings
    </a>
@endsection

@section('content')

{{--
    The school's accounts with other people: Paystack to collect fees, Termii to
    send a text message, DeepSeek to read a scoresheet that has been photographed.

    These do not belong among the school's logo and its address. They are set once,
    when an account is opened with the provider, and then not looked at again — and
    the two things that DO get changed in the course of a term (whether to send
    text messages at all, and what the school is called on a letter) are on the
    general page, where the office can reach them without going past a secret key.

    A key is drawn as dots, with an eye beside it. The value has to be in the page
    for the eye to mean anything: the only way to check that the key in the
    provider's own dashboard is the one pasted here is to look at it.
--}}
<p class="max-w-3xl text-sm text-muted">
    The keys the school signs in to its other services with. These are set once, when the account is
    opened with the provider. A key is hidden until the eye beside it is clicked.
</p>

<form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data" class="mt-6">
    @csrf
    @method('PUT')

    <div class="space-y-6 lg:max-w-3xl">
        @foreach ($groups as $group)
            @include('admin.settings.partials.group', ['group' => $group])
        @endforeach

        <div class="flex items-center gap-3">
            <button type="submit" class="btn-primary btn-lg">Save settings</button>
            <p class="text-xs text-muted">Changes take effect immediately.</p>
        </div>
    </div>
</form>
@endsection
