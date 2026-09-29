@extends('layouts.admin')

@section('title', 'Students & Results')
@section('subtitle', 'Dashboard')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        title="Students &amp; Results dashboard"
        note="Will hold the at-a-glance figures for this module: students on roll, results published, PINs sold, and results checked." />
@endsection
