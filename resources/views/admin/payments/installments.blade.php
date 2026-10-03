@extends('layouts.admin')

@section('title', 'Installments')
@section('subtitle', 'Payments')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="Students paying a term's fees in parts: how many instalments were agreed, what has been kept to and what has not, and how much is left." />
@endsection