@extends('admin.layout')

@section('title', 'Create popup')

@section('content')
    <h1>Create popup</h1>

    <p class="help">
        Link an announcement to an existing campaign.
    </p>

    @include('admin.popups.form')
@endsection