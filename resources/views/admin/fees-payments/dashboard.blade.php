@extends('layouts.admin')

@section('title', 'Dashboard')
@section('subtitle', 'Fees & Payments')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="How the money is doing: collected this term against what was billed, still outstanding, what has come in through the gateway, and the bills raised today — the screen the bursar opens first thing." />
@endsection
