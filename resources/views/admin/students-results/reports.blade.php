@extends('layouts.admin')

@section('title', 'Reports')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="The printable sheets: broadsheets per class, subject analysis, position lists, and the report cards themselves." />
@endsection
