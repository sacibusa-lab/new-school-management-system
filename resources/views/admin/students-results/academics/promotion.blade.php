@extends('layouts.admin')

@section('title', 'Promotion')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        :note="$page['note']" />
@endsection
