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
    send a text message, an AI provider to read a scoresheet that has been
    photographed.

    These do not belong among the school's logo and its address. They are set once,
    when an account is opened with the provider, and then not looked at again — and
    the two things that DO get changed in the course of a term (whether to send
    text messages at all, and what the school is called on a letter) are on the
    general page, where the office can reach them without going past a secret key.

    A key is drawn as dots, with an eye beside it. The value has to be in the page
    for the eye to mean anything: the only way to check that the key in the
    provider's own dashboard is the one pasted here is to look at it.

    Every card saves itself. Three unrelated accounts on one form meant that
    correcting a Termii sender ID also submitted the Paystack key — and a submitted
    empty key is a key the school has deleted without meaning to.
--}}

<p class="max-w-3xl text-sm text-muted">
    The keys the school signs in to its other services with. These are set once, when the account is
    opened with the provider. A key is hidden until the eye beside it is clicked, and each account is
    saved on its own.
</p>

<div class="mt-6 space-y-6 lg:max-w-3xl">
    @foreach ($groups as $group)
        @include('admin.settings.partials.group', [
            'group' => $group,
            'choices' => $choices,
            'ownForm' => true,
        ])
    @endforeach
</div>
@endsection
