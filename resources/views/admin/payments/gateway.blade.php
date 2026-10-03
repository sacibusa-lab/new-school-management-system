@extends('layouts.admin')

@section('title', 'Gateway')
@section('subtitle', 'Payments')

@section('content')
    <x-module-placeholder
        :icon="$page['icon']"
        :title="$page['label']"
        note="The school's Paystack account: the keys (set on Settings → API), every account number issued to a student, and whether the webhook is being heard — the page to open when a parent says they paid and the money is not showing." />
@endsection