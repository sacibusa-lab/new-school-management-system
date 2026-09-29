@extends('layouts.admin')

@section('title', 'Alumni')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="Students who have left: when they graduated, what they did next, and how to reach them. Nothing here yet — the page exists so the menu is complete." />
@endsection
