@extends('layouts.admin')

@section('title', 'Exam Master')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="Term examinations and the subjects each class sits — the termly counterpart to the entrance examination built in the Admissions section. Needs deciding whether it extends that or stands beside it." />
@endsection
