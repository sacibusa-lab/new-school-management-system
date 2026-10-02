@extends('layouts.admin')

@section('title', 'Multiple import')

@section('content')
    {{-- The label and the icon are written here as well as in the menu because this page
         is a stand-in: when it is built it becomes a controller of its own and this view
         goes with it, so there is nothing to keep in step in the meantime. --}}
    <x-module-placeholder
        icon="upload"
        title="Multiple import"
        note="Taking a whole year group's records in at once, from a spreadsheet: the names, the guardians and the class each child goes into. Read and shown back for checking before any of it is written." />
@endsection
