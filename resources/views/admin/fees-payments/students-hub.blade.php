@extends('layouts.admin')

@section('title', 'Students Hub')
@section('subtitle', 'Fees & Payments')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="The students and classes read by what they owe rather than by name: which class has paid, who is behind, and the account number each child pays into — the fees desk's view of the school, as against the academic register under Students & Results." />
@endsection
