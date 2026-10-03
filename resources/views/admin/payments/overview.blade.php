@extends('layouts.admin')

@section('title', 'Overview')
@section('subtitle', 'Payments')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="What has come in this term, by class and by day, with who is behind on their bill and how far behind — the screen the bursar opens first thing." />
@endsection