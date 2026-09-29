@extends('layouts.admin')

@section('title', 'Attendance')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="A daily register per class, and the term's attendance figures per student — which is the only way a report card can say how many times a child was absent." />
@endsection
