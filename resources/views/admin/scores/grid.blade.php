@extends('layouts.admin')

@section('title', 'Enter marks — ' . $exam->displayTitle())
@section('subtitle', $candidates->count() . ' candidate(s) across ' . $examSubjects->count() . ' paper(s)')

@section('actions')
    <a href="{{ route('admin.scores.index', ['exam' => $exam->id]) }}" class="btn-secondary btn-sm">
        &larr; Class and batch
    </a>
@endsection

@section('content')

@include('admin.scores.partials.grid')

@endsection

@include('admin.scores.partials.grid-scripts')
