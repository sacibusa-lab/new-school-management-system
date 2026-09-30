@extends('layouts.admin')

@section('title', 'Admissions settings')

@section('actions')
    <a href="{{ route('admin.settings.index') }}" class="btn-secondary btn-sm">
        <x-nav-icon name="cog" class="h-3.5 w-3.5" />
        All settings
    </a>
@endsection

@section('content')

{{--
    What the school does about admissions: whether the form is open, what it costs,
    where the cutoff is, and the letter that goes out with an offer.

    It sits in the Admissions section of the menu, with the work it belongs to,
    rather than under Settings. On the general settings page it was a group to
    scroll past on the way to the school's logo, and the office that opens the
    application in September does not think of what it is doing as changing a
    setting.
--}}
<p class="max-w-3xl text-sm text-muted">
    Whether the school is taking applications, what an application costs, the mark a candidate has to
    reach for each class, and the letter an offer is made in. Anything set here takes effect on the
    public site immediately.
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
