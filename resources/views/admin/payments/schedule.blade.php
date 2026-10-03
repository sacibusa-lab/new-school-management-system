@extends('layouts.admin')

@section('title', 'Payment Schedule')
@section('subtitle', 'Fees & Payments')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="Every student's bill laid out against the dates it falls due, so the office can see who should have paid by now and who is early — and send a reminder to the ones who are neither." />
@endsection