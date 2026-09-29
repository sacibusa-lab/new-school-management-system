@extends('layouts.admin')

@section('title', 'Performance Analytics')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="Will compare performance across classes, terms and years — subject averages, pass rates, and the students moving up or down." />
@endsection
