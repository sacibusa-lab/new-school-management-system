@extends('layouts.admin')

@section('title', 'Reports')
@section('subtitle', 'Fees & Payments')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="The collection sheets: what each class has paid against what it owes, what came in by cash and by transfer, and the arrears list to send the debtors' letters from." />
@endsection