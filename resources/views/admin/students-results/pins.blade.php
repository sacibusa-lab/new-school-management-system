@extends('layouts.admin')

@section('title', 'Generate Pin')
@section('subtitle', 'Students & Results')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="A PIN is what a parent buys to check a result on the public page. This screen will generate them in batches, print them, and show which ones have been used." />
@endsection
