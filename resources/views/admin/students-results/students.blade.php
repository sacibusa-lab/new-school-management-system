@extends('layouts.admin')

@section('title', 'Students Details')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="The student register for this module: one record per student with their class, guardian, results and history. Note that a separate Students page already exists and works — this one is the module's own version and is blank until we build it." />
@endsection
