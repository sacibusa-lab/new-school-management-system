@extends('layouts.admin')

@section('title', 'Classes & Sections')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        :note="$page['note']" />
@endsection
