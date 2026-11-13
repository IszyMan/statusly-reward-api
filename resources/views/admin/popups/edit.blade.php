@extends('admin.layout')

@section('title', 'Edit popup')

@section('content')
    <h1>Edit popup</h1>

    <p class="help">
        Manage the announcement independently of the campaign.
    </p>

    @include('admin.popups.form')
@endsection