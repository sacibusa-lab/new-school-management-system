@extends('layouts.admin')

@section('title', 'Check Result')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="A parent rings the office because they cannot see their child's result. This page will look up any student's result without asking for a surname, which is why it stays with the Super Admin." />
@endsection
