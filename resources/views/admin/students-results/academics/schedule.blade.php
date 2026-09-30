@extends('layouts.admin')

@section('title', 'Class schedule')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        :note="$page['note']" />
@endsection
