@extends('layouts.admin')

@section('title', 'Settings')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="This module's own settings — grading bands, how results are computed and what a PIN costs. Deliberately separate from the school-wide Settings page; only what belongs to results will move here." />
@endsection
