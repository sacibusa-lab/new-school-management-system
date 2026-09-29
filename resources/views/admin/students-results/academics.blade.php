@extends('layouts.admin')

@section('title', 'Academic')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="Sessions, terms, classes and subjects — the structure a result hangs off. Needs deciding against the equivalent settings that already exist in Settings, so the school has one answer, not two." />
@endsection
