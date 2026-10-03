@extends('layouts.admin')

@section('title', 'Add Students')

@section('content')
    {{-- The label and the icon are written here as well as in the menu because this page
         is a stand-in: when it is built it becomes a controller of its own and this view
         goes with it, so there is nothing to keep in step in the meantime. --}}
    <x-module-placeholder
        icon="user-plus"
        title="Add Students"
        note="Taking one child onto the roll by hand, for the ones that do not arrive in a file: a transfer in from another school, or a name the office has on paper. The same admission number and portal login the import gives, for one child rather than a class." />
@endsection
