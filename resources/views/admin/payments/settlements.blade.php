@extends('layouts.admin')

@section('title', 'Settlements')
@section('subtitle', 'Payments')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="What Paystack has actually paid into the school's bank account, and when, so that what the gateway collected can be checked against what arrived. The gap between the two is where fees go missing." />
@endsection