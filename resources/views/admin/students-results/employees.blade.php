@extends('layouts.admin')

@section('title', 'Employee')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="The staff register: teaching and non-teaching employees, their subjects, classes and terms of service. Distinct from Staff &amp; roles, which manages logins rather than people." />
@endsection
